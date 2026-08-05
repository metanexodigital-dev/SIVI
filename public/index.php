<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: public/index.php
 * Propósito: Actúa como controlador principal de la aplicación y dirige las solicitudes hacia cada módulo.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$page = (string)($_GET['page'] ?? (Auth::check() ? 'dashboard' : 'login'));

if ($page === 'setup') {
    // El instalador solo permanece disponible mientras no exista ningún usuario.
    if (Database::isInstalled()) {
        if (Auth::check()) {
            Auth::logout();
        }
        redirect('login');
    }

    if (request_method('POST')) {
        verify_csrf();
        $configuredSetupKey = SetupPolicy::configuredKey();
        $providedSetupKey = SetupPolicy::normalizeProvidedKey((string)($_POST['setup_key'] ?? ''));
        $adminName = trim((string)($_POST['admin_name'] ?? ''));
        $adminEmail = trim((string)($_POST['admin_email'] ?? ''));
        $adminPassword = (string)($_POST['admin_password'] ?? '');
        $adminPasswordConfirmation = (string)($_POST['admin_password_confirmation'] ?? '');

        if (SetupPolicy::requiresKey() && $configuredSetupKey === '') {
            flash('danger', 'APP_SETUP_KEY no está configurada. Defina una clave segura y vuelva a desplegar la aplicación.');
            redirect('setup');
        }
        if (!SetupPolicy::validate($providedSetupKey)) {
            try {
                $reference = 'SETUP-' . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3)));
            } catch (Throwable) {
                $reference = 'SETUP-' . date('Ymd-His') . '-' . strtoupper(substr(hash('sha256', uniqid('', true)), 0, 6));
            }
            $payload = [
                'timestamp' => date(DATE_ATOM),
                'reference' => $reference,
                'context' => 'setup_key_mismatch',
                'setup_key_required' => SetupPolicy::requiresKey(),
                'configured_source' => Env::source('APP_SETUP_KEY'),
                'configured_length' => strlen($configuredSetupKey),
                'configured_fingerprint' => $configuredSetupKey === '' ? null : substr(hash('sha256', $configuredSetupKey), 0, 12),
                'provided_length' => strlen($providedSetupKey),
                'provided_fingerprint' => $providedSetupKey === '' ? null : substr(hash('sha256', $providedSetupKey), 0, 12),
                'ip' => client_ip(),
            ];
            $logDir = dirname(__DIR__) . '/storage/logs';
            if (!is_dir($logDir)) @mkdir($logDir, 0770, true);
            @file_put_contents($logDir . '/security.log', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
            error_log('[' . $reference . '] setup_key_mismatch');
            flash('danger', 'La clave de instalación no es válida. Referencia: ' . $reference . '.');
            redirect('setup');
        }
        if ($adminPassword !== $adminPasswordConfirmation) {
            flash('danger', 'La confirmación de la contraseña temporal no coincide.');
            redirect('setup');
        }

        try {
            $schemaStatus = Database::schemaStatus();
            if (!$schemaStatus['ok']) {
                throw new RuntimeException(
                    'El esquema de MySQL todavía no está preparado. Verifique el contenedor de base de datos y vuelva a intentar.'
                );
            }
            Database::createSuperAdmin($adminName, $adminEmail, $adminPassword);
            security_event('setup.superadmin_created', [
                'email_hash' => hash('sha256', mb_strtolower($adminEmail)),
            ], 'notice');
            flash('success', 'Instalación completada. Inicie sesión con el correo registrado y cambie la contraseña temporal.');
            redirect('login');
        } catch (Throwable $e) {
            $reference = log_exception_reference($e, 'setup_installation');
            flash('danger', safe_error_message($e->getMessage() ?: 'No fue posible completar la instalación', $reference));
            redirect('setup');
        }
    }

    $content = '<div class="login-card setup-card">'
        . '<div class="brand-login brand-login-stacked">'
        . '<div class="sivi-logo-box sivi-logo-box--setup"><img class="sivi-logo-image" src="assets/brand/logos/sivi-logo-horizontal-600px.png?v=' . e(rawurlencode(AppVersion::package())) . '" alt="SIVI - Sistema Integrado de Verificación de Inventario"></div>'
        . '<div class="brand-separator"><span></span><b>●</b><span></span></div>'
        . '<div class="setup-heading"><div class="setup-icon">⚙</div><h1>Instalación inicial</h1><p class="muted">Configure el primer usuario Superadministrador del sistema.</p></div>'
        . '</div>'
        . '<form method="post">' . csrf_field()
        . (SetupPolicy::requiresKey()
            ? field('setup_key','Clave de instalación','','password',['required'=>true,'placeholder'=>'Ingrese APP_SETUP_KEY','autocomplete'=>'off'])
            : '<input type="hidden" name="setup_key" value="">'
              . '<div class="note note-info"><strong>Modo de pruebas:</strong> la clave de instalación está deshabilitada. El instalador se cerrará automáticamente al crear el primer usuario.</div>')
        . field('admin_name','Nombre completo del Superadministrador','','text',['required'=>true,'placeholder'=>'Nombre y apellidos'])
        . field('admin_email','Correo electrónico','','email',['required'=>true,'placeholder'=>'usuario@dominio.com'])
        . field('admin_password','Contraseña temporal','','password',['required'=>true,'placeholder'=>'Mínimo 10 caracteres'])
        . field('admin_password_confirmation','Confirmar contraseña temporal','','password',['required'=>true,'placeholder'=>'Repita la contraseña temporal'])
        . '<div class="setup-password-help">Debe incluir mayúscula, minúscula, número y carácter especial. En el primer ingreso será obligatorio cambiarla.</div>'
        . '<button class="btn setup-submit" type="submit">🔒&nbsp;&nbsp; Crear Superadministrador</button>'
        . '</form>'
        . '<div class="setup-note">ⓘ&nbsp;&nbsp; El esquema se inicializa desde el contenedor MySQL; este paso solo crea el primer usuario. Política del instalador: <strong>' . e(SetupPolicy::modeLabel()) . '</strong>. '
        . 'Los datos del Superadministrador no se guardan en Environment Settings.</div>'
        . '</div>';
    render_page('Instalación', $content);
    exit;
}
try {
    Database::connection();
} catch (Throwable $e) {
    $reference = log_exception_reference($e, 'database_connection');
    $content = '<div class="login-card"><h1>Base de datos no disponible</h1><p>No fue posible conectar con MySQL.</p><div class="note">Revise las variables DB_HOST, DB_DATABASE, DB_USERNAME y DB_PASSWORD. Después abra la instalación inicial.</div><p><a class="btn" href="' . e(route_url('setup')) . '">Abrir instalación</a></p><small class="muted">Referencia: ' . e($reference) . '</small></div>';
    render_page('Conexión pendiente', $content);
    exit;
}

// El lector móvil usa un enlace temporal y no exige iniciar sesión en el celular.
// El token aleatorio limita la sesión al formulario que originó la conexión.
if ($page === 'mobile_scanner') {
    mobile_scanner_page();
    exit;
}
if ($page === 'mobile_scan_submit') {
    mobile_scan_submit_page();
    exit;
}
if ($page === 'mobile_scan_status') {
    mobile_scan_status_page();
    exit;
}
if ($page === 'mobile_scan_decode_image') {
    mobile_scan_decode_image_page();
    exit;
}

if (Auth::check()) {
    $expirationReason = Auth::enforceSessionPolicy();
    if ($expirationReason !== null) {
        header('Location: ' . route_url('login') . '&session_expired=' . urlencode($expirationReason));
        exit;
    }
}

if ($page === 'session_keepalive') {
    Auth::requireLogin();
    Auth::touchSession();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'timestamp' => time()], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($page === 'login') {
    if (Auth::check()) redirect('dashboard');
    $expired = (string)($_GET['session_expired'] ?? '');
    if ($expired === 'idle') flash('warning', 'Su sesión finalizó por inactividad. Ingrese nuevamente.');
    elseif ($expired === 'absolute') flash('warning', 'Su sesión alcanzó la duración máxima permitida. Ingrese nuevamente.');
    if (request_method('POST')) {
        verify_csrf();
        try {
            if (Auth::attempt((string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''))) {
                redirect(Auth::mustChangePassword() ? 'cambiar_clave' : ((Auth::is('registrador') || Auth::is('formador')) ? 'equipos' : 'dashboard'));
            }
            flash('danger', 'Correo o contraseña incorrectos.');
        } catch (LoginRateLimitException $e) {
            $minutes = max(1, (int)ceil($e->retryAfterSeconds() / 60));
            flash('danger', 'Demasiados intentos fallidos. Espere aproximadamente ' . $minutes . ' minuto(s) antes de volver a intentar.');
        }
        redirect('login');
    }
    $content = '<div class="login-card auth-card"><div class="brand-login brand-login-stacked"><div class="sivi-logo-box sivi-logo-box--login"><img class="sivi-logo-image" src="assets/brand/logos/sivi-logo-horizontal-600px.png?v=' . e(rawurlencode(AppVersion::package())) . '" alt="SIVI - Sistema Integrado de Verificación de Inventario"></div><div class="brand-separator"><span></span><b>●</b><span></span></div><div class="auth-heading"><h1>Iniciar sesión</h1><p class="muted">Acceda con las credenciales asignadas.</p></div></div><form method="post">' . csrf_field() . field('email','Correo electrónico','','email',['required'=>true,'placeholder'=>'usuario@dominio.com']) . field('password','Contraseña','','password',['required'=>true,'placeholder'=>'Ingrese su contraseña']) . '<button class="btn auth-submit" type="submit">Ingresar</button></form><p class="login-help">El acceso y la información visible dependen del rol asignado.</p></div>';
    render_page('Iniciar sesión', $content);
    exit;
}

if ($page === 'logout') {
    Auth::logout();
    redirect('login');
}

Auth::requireLogin();

// El Superadministrador no puede usar ningún módulo mientras conserve la clave inicial.
if (Auth::mustChangePassword() && !in_array($page, ['cambiar_clave','logout'], true)) {
    flash('warning', 'Debe cambiar la contraseña inicial antes de continuar.');
    redirect('cambiar_clave');
}

// La operación de SIVI solo se habilita después de completar la carga inicial
// en el orden: Sedes -> GLPI computadores -> Almacén.
$initializationStatus = InitializationState::status();
if (!$initializationStatus['ready'] && in_array($page, InitializationState::operationalPages(), true)) {
    flash('warning', 'SIVI todavía está en inicialización. Complete Sedes, GLPI de computadores y Almacén antes de usar los módulos operativos.');
    redirect(Auth::is('admin_gi') ? 'importar' : 'dashboard');
}

// Las campañas nuevas y su publicación requieren semáforo sin hallazgos críticos.
if ($initializationStatus['ready'] && in_array($page, ['campania_crear'], true)) {
    try {
        if (!ImportQuality::campaignsAllowed()) {
            flash('danger', 'La creación de campañas está bloqueada porque existen inconsistencias críticas. Revise el Centro de diagnóstico.');
            redirect(Auth::is('admin_gi') ? 'diagnostico' : 'campanias');
        }
    } catch (Throwable) {
        // El controlador general mostrará cualquier problema de esquema pendiente.
    }
}

// Menú y acceso por perfil territorial.
// El Registrador conserva los cinco módulos operativos de su sede.
// El Formador agrega gestión territorial departamental y consulta de campañas,
// pero no puede crear, editar, publicar, duplicar, notificar, cancelar ni finalizar campañas.
if ($initializationStatus['ready'] && (Auth::is('registrador') || Auth::is('formador'))) {
    if ($page === 'dashboard') redirect('equipos');
    $operationalPages = [
        'equipos','equipo_validar','validation_draft','historial_equipo',
        'campania_sede_contacto','campania_accion','acta_sede',
        'adicionales','additional_identity_check','additional_catalog','novedades','notificaciones','correcciones','correccion_accion','buscar_equipo','exportar',
        'mobile_scan_start','mobile_scan_poll','mobile_scan_ack','mobile_scan_renew','mobile_scan_stop','mobile_scan_qr',
        'cambiar_clave','logout','site_quality_gate',
    ];
    if (Auth::is('formador')) {
        $operationalPages = array_merge($operationalPages, [
            'equipo_asignar','novedad_accion',
            'sedes','sede_editar',
            'campanias',
            'seguimiento','seguimiento_accion',
            'calidad',
            'traslados','traslado_accion',
            'reaperturas','reapertura_accion',
            'inconsistencias','informes','informe_exportar','informe_imprimir',
        ]);
    }
    if (!in_array($page, $operationalPages, true)) {
        http_response_code(403);
        if (Auth::is('formador')) {
            render_error('Acceso restringido', 'El perfil Formador puede operar el inventario y gestionar las sedes de sus departamentos. Campañas está disponible únicamente para consulta.');
        } else {
            render_error('Acceso restringido', 'El perfil Registrador solo puede validar la sede y el inventario, registrar equipos adicionales y consultar novedades, notificaciones o correcciones.');
        }
        exit;
    }
}

try {
switch ($page) {
    case 'cambiar_clave': forced_password_change_page(); break;
    case 'dashboard': dashboard_page(); break;
    case 'sedes': sedes_page(); break;
    case 'sede_editar': sede_edit_page(); break;
    case 'campania_sede_contacto': campaign_site_contact_page(); break;
    case 'equipos': equipment_page(); break;
    case 'buscar_equipo': equipment_search_page(); break;
    case 'equipo_validar': equipment_validate_page(); break;
    case 'validation_draft': validation_draft_page(); break;
    case 'equipo_asignar': equipment_assign_page(); break;
    case 'campanias': campaigns_page(); break;
    case 'campania_crear': campaign_create_page(); break;
    case 'campania_accion': campaign_action_page(); break;
    case 'directorio': directory_page(); break;
    case 'directorio_accion': directory_action_page(); break;
    case 'calidad': quality_page(); break;
    case 'site_quality_gate': site_quality_gate_page(); break;
    case 'homologaciones': homologations_page(); break;
    case 'traslados': transfers_page(); break;
    case 'traslado_accion': transfer_action_page(); break;
    case 'acta_sede': site_closure_certificate_page(); break;
    case 'notificaciones': internal_notifications_page(); break;
    case 'reaperturas': reopening_requests_page(); break;
    case 'reapertura_accion': reopening_action_page(); break;
    case 'correcciones': corrections_page(); break;
    case 'correccion_accion': correction_action_page(); break;
    case 'recordatorios': reminders_page(); break;
    case 'recordatorio_accion': reminder_action_page(); break;
    case 'informes': reports_page(); break;
    case 'informe_exportar': reports_export_page(); break;
    case 'informe_imprimir': reports_print_page(); break;
    case 'reporte_ejecutivo': executive_report_page(); break;
    case 'respaldos': backups_page(); break;
    case 'versionamiento': version_control_page(); break;
    case 'sistema': system_health_page(); break;
    case 'adicionales': additional_page(); break;
    case 'additional_identity_check': additional_identity_check_page(); break;
    case 'additional_catalog': additional_catalog_page(); break;
    case 'mobile_scan_start': mobile_scan_start_page(); break;
    case 'mobile_scan_poll': mobile_scan_poll_page(); break;
    case 'mobile_scan_ack': mobile_scan_ack_page(); break;
    case 'mobile_scan_renew': mobile_scan_renew_page(); break;
    case 'mobile_scan_stop': mobile_scan_stop_page(); break;
    case 'mobile_scan_qr': mobile_scan_qr_page(); break;
    case 'seguimiento': workflow_page(); break;
    case 'seguimiento_accion': workflow_action_page(); break;
    case 'inconsistencias': inconsistencies_page(); break;
    case 'novedades': incidents_page(); break;
    case 'novedad_accion': incident_action_page(); break;
    case 'historial_equipo': equipment_history_page(); break;
    case 'importar': import_page(); break;
    case 'import_validation_report': import_validation_report_page(); break;
    case 'diagnostico': diagnostics_page(); break;
    case 'configuracion': configuration_page(); break;
    case 'correo': mail_notifications_page(); break;
    case 'glpi': glpi_integration_page(); break;
    case 'placa_config': header('Location: admin-plate-policy.php'); exit;
    case 'usuarios': users_page(); break;
    case 'usuario_editar': user_edit_page(); break;
    case 'usuarios_plantilla': users_template_page(); break;
    case 'usuarios_importar': users_import_page(); break;
    case 'usuario_estado': user_status_page(); break;
    case 'usuario_clave': user_password_page(); break;
    case 'auditoria': audit_page(); break;
    case 'exportar': export_page(); break;
    default:
        http_response_code(404);
        render_error('Página no encontrada', 'La opción solicitada no existe.');
}
} catch (Throwable $e) {
    $reference = log_exception_reference($e, 'module_' . $page);
    http_response_code(500);
    $message = 'No fue posible abrir este módulo.';
    try {
        $schema = Database::schemaStatus();
        if (!$schema['ok']) {
            $message = 'La estructura de la base de datos no coincide con esta compilación. Verifique el despliegue del contenedor MySQL y contacte al administrador de infraestructura.';
        }
    } catch (Throwable) {
        // Se conserva el mensaje general cuando tampoco es posible revisar el esquema.
    }
    render_error('Error al cargar el módulo', $message . ' Referencia: ' . $reference . '.');
}


function forced_password_change_page(): void
{
    $user = Auth::user();
    if (!$user) redirect('login');
    if (!Auth::mustChangePassword()) redirect(Auth::is('registrador') ? 'equipos' : 'dashboard');

    $errors = [];
    if (request_method('POST')) {
        verify_csrf();
        $current = (string)($_POST['current_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $confirmation = (string)($_POST['confirm_password'] ?? '');

        if (!password_verify($current, (string)$user['password_hash'])) $errors[] = 'La contraseña actual no es correcta.';
        if (strlen($new) < 10) $errors[] = 'La nueva contraseña debe tener mínimo 10 caracteres.';
        if (strlen($new) > 128) $errors[] = 'La nueva contraseña no puede superar 128 caracteres.';
        if (!preg_match('/[A-ZÁÉÍÓÚÑ]/u', $new)) $errors[] = 'Incluya al menos una letra mayúscula.';
        if (!preg_match('/[a-záéíóúñ]/u', $new)) $errors[] = 'Incluya al menos una letra minúscula.';
        if (!preg_match('/\d/', $new)) $errors[] = 'Incluya al menos un número.';
        if (!preg_match('/[^\pL\pN\s]/u', $new)) $errors[] = 'Incluya al menos un carácter especial.';
        if ($new !== $confirmation) $errors[] = 'La confirmación no coincide con la nueva contraseña.';
        if ($new !== '' && password_verify($new, (string)$user['password_hash'])) $errors[] = 'La nueva contraseña no puede ser igual a la contraseña actual.';
        if ($new !== '' && !empty($user['default_password_hash']) && password_verify($new, (string)$user['default_password_hash'])) {
            $errors[] = 'La nueva contraseña no puede ser igual a la contraseña predeterminada.';
        }

        if (!$errors) {
            Database::execute(
                'UPDATE users SET password_hash=?,default_password_hash=NULL,must_change_password=0,updated_at=NOW() WHERE id=?',
                [password_hash($new, PASSWORD_DEFAULT), (int)$user['id']]
            );
            audit('forced_password_change', 'user', (int)$user['id'], ['must_change_password' => 1], ['must_change_password' => 0]);
            Auth::forgetCachedUser();
            session_regenerate_id(true);
            flash('success', 'La contraseña fue actualizada correctamente.');
            redirect(Auth::is('registrador') ? 'equipos' : 'dashboard');
        }
    }

    $errorHtml = '';
    if ($errors) {
        $errorHtml = '<div class="alert alert-danger"><strong>No fue posible cambiar la contraseña:</strong><ul class="mb-0">';
        foreach ($errors as $error) $errorHtml .= '<li>' . e($error) . '</li>';
        $errorHtml .= '</ul></div>';
    }
    $content = '<div class="card mx-auto sivi-max-w-720">'
        . '<div class="card-body p-4"><h2 class="h4">Cambio obligatorio de contraseña</h2>'
        . '<p class="text-muted">Por seguridad, el usuario Superadministrador debe reemplazar la contraseña inicial antes de acceder a la aplicación.</p>'
        . $errorHtml
        . '<form method="post" autocomplete="off">' . csrf_field()
        . field('current_password', 'Contraseña actual', '', 'password', ['required'=>true, 'attributes'=>['autocomplete'=>'current-password']])
        . field('new_password', 'Nueva contraseña', '', 'password', ['required'=>true, 'attributes'=>['autocomplete'=>'new-password']])
        . field('confirm_password', 'Confirmar nueva contraseña', '', 'password', ['required'=>true, 'attributes'=>['autocomplete'=>'new-password']])
        . '<div class="form-text mb-3">Mínimo 10 caracteres, con mayúscula, minúscula, número y carácter especial. No puede ser igual a la clave inicial.</div>'
        . '<button class="btn btn-primary" type="submit">Guardar nueva contraseña</button>'
        . '</form></div></div>';
    render_page('Cambiar contraseña', $content, ['subtitle'=>'Acción obligatoria para habilitar el acceso del Superadministrador.']);
}

function campaign_department_codes(int $campaignId): array
{
    $rows = Database::fetchAll('SELECT cod_dd FROM campaign_departments WHERE campaign_id=? ORDER BY cod_dd', [$campaignId]);
    if (!$rows) {
        $rows = Database::fetchAll("SELECT DISTINCT s.cod_dd FROM campaign_sedes cs JOIN sedes s ON s.id=cs.sede_id WHERE cs.campaign_id=? AND NULLIF(TRIM(s.cod_dd),'') IS NOT NULL ORDER BY s.cod_dd", [$campaignId]);
    }
    return array_values(array_unique(array_filter(array_map(static fn(array $row): string => trim((string)($row['cod_dd'] ?? '')), $rows))));
}

function campaign_department_rows(int $campaignId): array
{
    return Database::fetchAll(
        "SELECT x.cod_dd,COALESCE(MAX(NULLIF(TRIM(s.departamento),'')),MAX(NULLIF(TRIM(d.name),'')),x.cod_dd) departamento,COUNT(DISTINCT cs.sede_id) sedes_count
         FROM (SELECT cod_dd FROM campaign_departments WHERE campaign_id=? UNION SELECT DISTINCT s2.cod_dd FROM campaign_sedes cs2 JOIN sedes s2 ON s2.id=cs2.sede_id WHERE cs2.campaign_id=?) x
         LEFT JOIN departments d ON d.code=x.cod_dd
         LEFT JOIN sedes s ON s.cod_dd=x.cod_dd
         LEFT JOIN campaign_sedes cs ON cs.campaign_id=? AND cs.sede_id=s.id
         WHERE NULLIF(TRIM(x.cod_dd),'') IS NOT NULL
         GROUP BY x.cod_dd ORDER BY departamento",
        [$campaignId,$campaignId,$campaignId]
    );
}

function campaign_department_summary(int $campaignId): string
{
    $rows = campaign_department_rows($campaignId);
    if (!$rows) return 'Sin cobertura territorial';
    $names = array_map(static fn(array $row): string => (string)$row['departamento'], $rows);
    if (count($names) <= 3) return implode(', ', $names);
    return implode(', ', array_slice($names, 0, 3)) . ' y ' . (count($names) - 3) . ' más';
}


function campaign_status_label(string $status): string
{
    return match ($status) {
        'borrador' => 'Borrador',
        'programada' => 'Programada',
        'activa' => 'Activa',
        'cerrada' => 'Cerrada',
        'en_revision' => 'En revisión',
        'finalizada' => 'Finalizada',
        'cancelada' => 'Cancelada',
        default => ucfirst(str_replace('_', ' ', $status)),
    };
}

function campaign_is_operational(array|string $campaign): bool
{
    $status = is_array($campaign) ? (string)($campaign['status'] ?? '') : $campaign;
    if ($status === 'activa') return true;
    if ($status !== 'programada') return false;
    if (!is_array($campaign)) return false;
    $start = trim((string)($campaign['start_date'] ?? ''));
    return $start !== '' && $start <= date('Y-m-d');
}

function campaign_equipment_count(int $campaignId): int
{
    return (int)(Database::fetchOne('SELECT COUNT(*) total FROM campaign_equipment WHERE campaign_id=?', [$campaignId])['total'] ?? 0);
}

function campaign_equipment_exists(int $campaignId, int $equipmentId): bool
{
    return (bool)Database::fetchOne('SELECT 1 ok FROM campaign_equipment WHERE campaign_id=? AND equipment_id=?', [$campaignId,$equipmentId]);
}

function campaign_overlap_summary(int $campaignId): array
{
    $row = Database::fetchOne(
        "SELECT COUNT(DISTINCT ce.equipment_id) equipos,COUNT(DISTINCT other.campaign_id) campanias "
        . "FROM campaign_equipment ce JOIN campaign_equipment other ON other.equipment_id=ce.equipment_id AND other.campaign_id<>ce.campaign_id "
        . "JOIN campaigns c ON c.id=other.campaign_id WHERE ce.campaign_id=? AND c.status IN ('programada','activa','en_revision')",
        [$campaignId]
    ) ?: [];
    return ['equipos'=>(int)($row['equipos']??0),'campanias'=>(int)($row['campanias']??0)];
}

function campaign_snapshot_condition(int $campaignId, string $equipmentAlias = 'e'): string
{
    return $campaignId > 0
        ? "EXISTS (SELECT 1 FROM campaign_equipment ce_scope WHERE ce_scope.campaign_id=" . (int)$campaignId . " AND ce_scope.equipment_id={$equipmentAlias}.id)"
        : '1=1';
}

function campaign_next_pending_equipment(int $campaignId, int $sedeId, ?array $afterEquipment = null): ?array
{
    if ($campaignId < 1 || $sedeId < 1) return null;
    $rows = Database::fetchAll(
        "SELECT e.id,e.name,e.asset_category FROM campaign_equipment ce "
        . "JOIN equipment e ON e.id=ce.equipment_id "
        . "LEFT JOIN equipment_validations ev ON ev.campaign_id=ce.campaign_id AND ev.equipment_id=ce.equipment_id AND ev.reported_by_sede_id=ce.sede_id "
        . "WHERE ce.campaign_id=? AND ce.sede_id=? AND (ev.id IS NULL OR ev.validation_status='pendiente') "
        . "ORDER BY e.asset_category,e.name,e.id",
        [$campaignId,$sedeId]
    );
    if ($rows === []) return null;
    if ($afterEquipment === null) return $rows[0];

    $afterCategory = mb_strtolower(trim((string)($afterEquipment['asset_category'] ?? '')));
    $afterName = mb_strtolower(trim((string)($afterEquipment['name'] ?? '')));
    $afterId = (int)($afterEquipment['id'] ?? 0);
    foreach ($rows as $row) {
        $category = mb_strtolower(trim((string)($row['asset_category'] ?? '')));
        $name = mb_strtolower(trim((string)($row['name'] ?? '')));
        $id = (int)($row['id'] ?? 0);
        if ($category > $afterCategory
            || ($category === $afterCategory && $name > $afterName)
            || ($category === $afterCategory && $name === $afterName && $id > $afterId)) {
            return $row;
        }
    }

    // Si el usuario omitió equipos anteriores, continuar con el primer pendiente.
    return $rows[0];
}


function operational_step_badge(string $status): string
{
    return match ($status) {
        'complete' => '<span class="guided-step-state guided-step-complete">✓ Completado</span>',
        'current' => '<span class="guided-step-state guided-step-current">→ Siguiente</span>',
        'attention' => '<span class="guided-step-state guided-step-attention">! Requiere atención</span>',
        'blocked' => '<span class="guided-step-state guided-step-blocked">○ Bloqueado</span>',
        default => '<span class="guided-step-state guided-step-available">• Disponible</span>',
    };
}

function guided_site_work_panel(int $campaignId, int $sedeId): string
{
    $state = OperationalExperience::siteState($campaignId,$sedeId,(int)(Auth::id() ?? 0));
    if ($state === []) return '';
    $action = $state['next_action'];
    $steps = '';
    foreach ($state['steps'] as $step) {
        $steps .= '<a class="guided-step guided-step--'.e((string)$step['status']).'" href="'.e((string)$step['url']).'">'
            . '<span class="guided-step-number">'.(int)$step['number'].'</span><span class="guided-step-copy"><strong>'.e((string)$step['label']).'</strong><small>'.e((string)$step['detail']).'</small></span>'
            . operational_step_badge((string)$step['status']).'</a>';
    }
    $attention = [
        ['Equipos pendientes',(int)$state['pending'],route_url('equipos',['campaign_id'=>$campaignId,'sede_id'=>$sedeId,'validation'=>'pending']),'Validar ahora'],
        ['Seriales por registrar',(int)$state['serial_pending'],route_url('equipos',['campaign_id'=>$campaignId,'sede_id'=>$sedeId,'serial'=>'pending']),'Revisar'],
        ['Novedades abiertas',(int)$state['incidents'],route_url('novedades',['campaign_id'=>$campaignId,'sede_id'=>$sedeId]),'Gestionar'],
    ];
    if (AppSettings::validationDraftsEnabled()) {
        array_splice($attention, 1, 0, [[
            'Borradores automáticos pendientes',
            (int)$state['drafts'],
            route_url('equipos',['campaign_id'=>$campaignId,'sede_id'=>$sedeId,'validation'=>'pending']),
            'Revisar'
        ]]);
    }
    if ((int)$state['corrections'] > 0) {
        $attention[] = ['Correcciones pendientes',(int)$state['corrections'],route_url('correcciones',['campaign_id'=>$campaignId,'sede_id'=>$sedeId]),'Resolver'];
    }
    if ((int)$state['unread_notifications'] > 0) {
        $attention[] = ['Notificaciones sin leer',(int)$state['unread_notifications'],route_url('notificaciones',['campaign_id'=>$campaignId,'sede_id'=>$sedeId]),'Consultar'];
    }
    $attentionHtml='';
    foreach($attention as [$label,$value,$url,$button]){
        $attentionHtml.='<a class="guided-pending-card'.($value>0?' has-pending':' is-clear').'" href="'.e($url).'"><span>'.e($label).'</span><strong>'.number_format($value,0,',','.').'</strong><small>'.($value>0?e($button):'Sin pendientes').'</small></a>';
    }
    return '<section class="guided-work-card" data-guided-work>'
        . '<div class="guided-work-head"><div><div class="kicker">Mi ruta de trabajo</div><h2>'.e((string)$state['campaign_name']).'</h2><p>'.e((string)$state['identificador'].' · '.$state['nombre_sede'].' · '.$state['municipio'].' / '.$state['departamento']).'</p></div>'
        . '<div class="guided-progress-value"><strong>'.(int)$state['progress'].'%</strong><span>Avance general</span></div></div>'
        . '<div class="guided-progress" role="progressbar" aria-label="Avance de la sede" aria-valuemin="0" aria-valuemax="100" aria-valuenow="'.(int)$state['progress'].'"><span class="'.progress_width_class((int)$state['progress']).'"></span></div>'
        . '<div class="guided-next-action"><div><strong>¿Qué debe hacer ahora?</strong><span>'.e((string)$action['reason']).'</span></div><a class="btn btn-'.e((string)$action['tone']).'" href="'.e((string)$action['url']).'">'.e((string)$action['label']).'</a></div>'
        . '<div class="guided-steps" aria-label="Pasos de validación">'.$steps.'</div>'
        . '<div class="guided-section-head"><div><h3>Qué requiere atención</h3><p>Abra directamente el pendiente que desea resolver.</p></div><a class="btn btn-sm btn-outline-primary" href="'.e(route_url('buscar_equipo',['campaign_id'=>$campaignId])).'">Buscar equipo</a></div>'
        . '<div class="guided-pending-grid">'.$attentionHtml.'</div></section>';
}


function site_closure_panel(int $campaignId, int $sedeId): string
{
    $state=OperationalExperience::siteState($campaignId,$sedeId,(int)(Auth::id()??0));
    if($state===[])return '';
    if(!empty($state['closed'])){
        return '<section class="card site-closure-card" id="siteClosure"><div class="guided-step-state guided-step-complete">✓ Completado</div><h3>Sede finalizada</h3><p>La validación fue cerrada el '.e((string)($state['closed_at']??'')).'. La información quedó bloqueada para conservar la trazabilidad.</p><a class="btn btn-outline-primary" href="'.e(route_url('acta_sede',['campaign_id'=>$campaignId,'sede_id'=>$sedeId])).'">Ver constancia de cierre</a></section>';
    }
    $reasons=[];
    if(empty($state['profile_complete']))$reasons[]='Falta confirmar la información y el responsable de la sede.';
    if((int)$state['pending']>0)$reasons[]='Falta validar '.(int)$state['pending'].' equipo(s).';
    if((int)$state['drafts']>0)$reasons[]='Existen '.(int)$state['drafts'].' borrador(es) que todavía no tienen una validación confirmada.';
    if((int)$state['corrections']>0)$reasons[]='Existen '.(int)$state['corrections'].' corrección(es) pendiente(s).';
    if((int)$state['critical_incidents']>0)$reasons[]='Existen '.(int)$state['critical_incidents'].' novedad(es) de prioridad alta o crítica.';
    $ready=!empty($state['ready_to_close']);
    $status=$ready
        ? '<div class="alert alert-success"><strong>La sede está lista para finalizar.</strong><div>Revise el resumen y confirme la declaración.</div></div>'
        : '<div class="alert alert-warning"><strong>La sede todavía no puede finalizarse.</strong><ul class="mb-0 mt-2"><li>'.implode('</li><li>',array_map('e',$reasons)).'</li></ul></div>';
    $draftSummary = AppSettings::validationDraftsEnabled()
        ? '<span><b>'.(int)$state['drafts'].'</b>Borradores automáticos</span>'
        : '';
    return '<section class="card site-closure-card" id="siteClosure"><div class="kicker">Paso 5</div><h3>Revisar y finalizar la sede</h3><div class="site-closure-summary"><span><b>'.(int)$state['assigned'].'</b>Asignados</span><span><b>'.(int)$state['validated'].'</b>Validados</span>'.$draftSummary.'<span><b>'.(int)$state['additional'].'</b>Adicionales</span><span><b>'.(int)$state['incidents'].'</b>Novedades</span><span><b>'.(int)$state['corrections'].'</b>Correcciones</span></div>'.$status
        . '<div class="form-actions mb-3"><a class="btn btn-outline-primary" href="'.e(route_url('site_quality_gate',['campaign_id'=>$campaignId,'sede_id'=>$sedeId])).'">Abrir control de calidad</a></div>'
        . '<form method="post" action="'.e(route_url('campania_accion')).'">'.csrf_field().'<input type="hidden" name="action" value="submit_sede"><input type="hidden" name="campaign_id" value="'.$campaignId.'"><input type="hidden" name="sede_id" value="'.$sedeId.'"><label class="field mb-3"><span class="form-label">Observación de cierre</span><textarea class="form-control" name="closure_notes" rows="3" placeholder="Registre una observación final si es necesario"></textarea></label><label class="confirmation-box mb-3"><input type="checkbox" name="closure_acceptance" value="1" required><span><strong>Confirmo que la información corresponde a la verificación física realizada.</strong><small>Después del cierre, la sede quedará bloqueada y se generará una constancia.</small></span></label><button class="btn btn-success" '.($ready?'':'disabled').' data-confirm="¿Confirma que terminó la revisión completa de la sede?">Finalizar validación de la sede</button></form></section>';
}

function guided_territory_panel(int $campaignId): string
{
    if ($campaignId < 1) {
        return '<section class="guided-work-card"><div class="kicker">Mi ruta de trabajo</div><h2>Seleccione una campaña</h2><p>Elija una campaña para consultar las sedes y sus pendientes.</p></section>';
    }
    $rows=OperationalExperience::territoryOverview($campaignId);
    $total=count($rows);$notStarted=0;$inProgress=0;$completed=0;$incidents=0;$corrections=0;
    foreach($rows as $row){
        $assigned=(int)$row['assigned'];$validated=(int)$row['validated'];
        if(in_array((string)$row['status'],['cerrado','aprobado'],true))$completed++;
        elseif($validated===0&&empty($row['site_confirmed_at']))$notStarted++;
        else $inProgress++;
        $incidents+=(int)$row['incidents'];$corrections+=(int)$row['corrections'];
    }
    $priority='';
    foreach(array_slice($rows,0,12) as $row){
        $assigned=(int)$row['assigned'];$validated=(int)$row['validated'];$pending=max(0,$assigned-$validated);
        $profileComplete=!empty($row['site_confirmed_at'])&&trim((string)$row['responsible_name'])!==''&&filter_var((string)$row['responsible_email'],FILTER_VALIDATE_EMAIL)!==false;
        $reason=!$profileComplete?'Validar sede y responsable':($pending>0?$pending.' equipo(s) pendiente(s)':(((int)$row['corrections']>0||(int)$row['incidents']>0)?'Resolver novedades o correcciones':'Revisar cierre'));
        $priority.='<a class="territory-priority-row" href="'.e(route_url('equipos',['campaign_id'=>$campaignId,'sede_id'=>(int)$row['id']])).'"><span><strong>'.e($row['identificador'].' · '.$row['nombre_sede']).'</strong><small>'.e($row['municipio'].' / '.$row['departamento']).'</small></span><span class="territory-priority-reason">'.e($reason).'</span><span aria-hidden="true">›</span></a>';
    }
    return '<section class="guided-work-card"><div class="guided-work-head"><div><div class="kicker">Seguimiento departamental</div><h2>¿Qué requiere atención?</h2><p>Seleccione una sede para continuar su validación o resolver pendientes.</p></div><a class="btn btn-outline-primary" href="'.e(route_url('buscar_equipo',['campaign_id'=>$campaignId])).'">Buscar equipo</a></div>'
        . '<div class="territory-metrics"><span><strong>'.$total.'</strong>Sedes visibles</span><span><strong>'.$notStarted.'</strong>Sin iniciar</span><span><strong>'.$inProgress.'</strong>En proceso</span><span><strong>'.$completed.'</strong>Completadas</span><span><strong>'.$incidents.'</strong>Novedades</span><span><strong>'.$corrections.'</strong>Correcciones</span></div>'
        . '<div class="guided-section-head"><div><h3>Sedes prioritarias</h3><p>Ordenadas por falta de información y equipos pendientes.</p></div></div><div class="territory-priority-list">'.($priority!==''?$priority:'<p class="muted">No hay sedes pendientes en esta campaña.</p>').'</div></section>';
}

function registrar_campaign_panel(int $campaignId, int $sedeId): string
{
    if ($campaignId < 1 || $sedeId < 1) return '';
    $campaign = Database::fetchOne('SELECT * FROM campaigns WHERE id=?',[$campaignId]);
    $sede = Database::fetchOne('SELECT s.*,cs.status site_status,cs.closed_at FROM sedes s JOIN campaign_sedes cs ON cs.sede_id=s.id AND cs.campaign_id=? WHERE s.id=?',[$campaignId,$sedeId]);
    if (!$campaign || !$sede) return '';
    $assigned = (int)(Database::fetchOne('SELECT COUNT(*) total FROM campaign_equipment WHERE campaign_id=? AND sede_id=?',[$campaignId,$sedeId])['total']??0);
    $validated = (int)(Database::fetchOne("SELECT COUNT(DISTINCT equipment_id) total FROM equipment_validations WHERE campaign_id=? AND reported_by_sede_id=? AND validation_status<>'pendiente'",[$campaignId,$sedeId])['total']??0);
    $additional = (int)(Database::fetchOne("SELECT COUNT(*) total FROM additional_equipment WHERE campaign_id=? AND sede_id=?",[$campaignId,$sedeId])['total']??0);
    $incidents = (int)(Database::fetchOne("SELECT COUNT(*) total FROM incidents WHERE campaign_id=? AND sede_id=? AND status IN ('abierta','en_gestion')",[$campaignId,$sedeId])['total']??0);
    $pending = max(0,$assigned-$validated);
    $percent = $assigned>0?(int)round($validated/$assigned*100):0;
    $next = campaign_next_pending_equipment($campaignId,$sedeId);
    $days = null;
    if (!empty($campaign['end_date'])) $days=(int)floor((strtotime((string)$campaign['end_date'].' 23:59:59')-time())/86400);
    $deadline = $days===null?'Sin fecha límite':($days<0?'Plazo vencido hace '.abs($days).' día(s)':($days===0?'Vence hoy':'Faltan '.$days.' día(s)'));
    $action = $next
        ? '<a class="btn btn-light" href="'.e(route_url('equipo_validar',['id'=>(int)$next['id'],'campaign_id'=>$campaignId])).'">Continuar con '.e($next['name']?:asset_category_label((string)$next['asset_category'])).'</a>'
        : '<a class="btn btn-light" href="'.e(route_url('equipos',['campaign_id'=>$campaignId,'sede_id'=>$sedeId])).'">Revisar y cerrar sede</a>';
    return '<section class="registrar-campaign-hero"><div><div class="kicker">Campaña activa en su sede</div><h2>'.e($campaign['name']).'</h2><p>'.e($sede['identificador'].' · '.$sede['nombre_sede']).' · '.e($deadline).'</p><div class="progress"><span class="'.progress_width_class(min(100,$percent)).'"></span></div><small>'.$validated.' de '.$assigned.' equipos verificados · '.$percent.'%</small></div><div class="registrar-campaign-metrics"><span><b>'.$pending.'</b>Pendientes</span><span><b>'.$additional.'</b>Adicionales</span><span><b>'.$incidents.'</b>Novedades abiertas</span></div><div>'.$action.'</div></section>';
}

function refresh_campaign_states(): void
{
    static $refreshed = false;
    if ($refreshed) return;

    Database::execute(
        "UPDATE campaigns SET status='activa',"
        . "published_at=COALESCE(published_at,NOW()) "
        . "WHERE status='programada' "
        . "AND start_date IS NOT NULL AND start_date<=CURDATE()"
    );
    Database::execute(
        "UPDATE campaigns SET status='en_revision',"
        . "closed_at=COALESCE(closed_at,NOW()) "
        . "WHERE status='activa' "
        . "AND end_date IS NOT NULL AND end_date<CURDATE()"
    );
    $refreshed = true;
}

function campaign_accepts_responses(int $campaignId): bool
{
    refresh_campaign_states();
    $row=Database::fetchOne("SELECT status,start_date,end_date FROM campaigns WHERE id=?",[$campaignId]);
    if(!$row||(string)$row['status']!=='activa')return false;
    $today=date('Y-m-d');
    return (empty($row['start_date'])||(string)$row['start_date']<=$today)&&(empty($row['end_date'])||(string)$row['end_date']>=$today);
}

/**
 * Devuelve las campañas visibles para el usuario actual con una sola consulta
 * por solicitud. Conserva exactamente las reglas de acceso de cada perfil.
 *
 * @return array<int,array<string,mixed>>
 */
function accessible_campaign_rows(): array
{
    static $cache = null;
    if (is_array($cache)) return $cache;

    if (!Auth::check()) {
        $cache = [];
        return $cache;
    }

    refresh_campaign_states();
    $user = Auth::user();
    $role = (string)($user['role'] ?? '');
    $order = " ORDER BY CASE c.status "
        . "WHEN 'activa' THEN 0 WHEN 'programada' THEN 1 "
        . "WHEN 'en_revision' THEN 2 ELSE 3 END,c.id DESC";

    if (in_array($role, ['admin_gi','superadmin'], true)) {
        $cache = Database::fetchAll(
            "SELECT c.id,c.name,c.status FROM campaigns c" . $order
        );
        return $cache;
    }

    if ($role === 'registrador') {
        $sedeId = (int)($user['sede_id'] ?? 0);
        if ($sedeId < 1) {
            $cache = [];
            return $cache;
        }
        $cache = Database::fetchAll(
            "SELECT DISTINCT c.id,c.name,c.status "
            . "FROM campaigns c "
            . "JOIN campaign_sedes cs ON cs.campaign_id=c.id "
            . "WHERE cs.sede_id=? "
            . "AND c.status IN ('activa','en_revision','finalizada')"
            . $order,
            [$sedeId]
        );
        return $cache;
    }

    if ($role === 'formador') {
        $departments = array_values(array_filter(
            array_map('strval', $user['departments'] ?? [])
        ));
        if ($departments === []) {
            $cache = [];
            return $cache;
        }
        $placeholders = implode(
            ',',
            array_fill(0, count($departments), '?')
        );
        $cache = Database::fetchAll(
            "SELECT DISTINCT c.id,c.name,c.status "
            . "FROM campaigns c "
            . "JOIN campaign_sedes cs ON cs.campaign_id=c.id "
            . "JOIN sedes s ON s.id=cs.sede_id "
            . "WHERE s.cod_dd IN ({$placeholders})"
            . $order,
            $departments
        );
        return $cache;
    }

    $cache = [];
    return $cache;
}

function campaign_accessible_to_current_user(int $campaignId): bool
{
    if ($campaignId < 1) return false;
    foreach (accessible_campaign_rows() as $campaign) {
        if ((int)$campaign['id'] === $campaignId) return true;
    }
    return false;
}

function campaign_manageable_by_current_user(int $campaignId): bool
{
    if ($campaignId < 1 || !Auth::check()) return false;
    // La administración de campañas queda reservada para los perfiles nacionales.
    // El Formador solo participa en la operación y seguimiento de las sedes de su departamento.
    return in_array((string)(Auth::user()['role'] ?? ''), ['admin_gi','superadmin'], true);
}

function selected_campaign_id(): int
{
    $requested = (int)(
        $_GET['campaign_id']
        ?? $_POST['campaign_id']
        ?? 0
    );
    $rows = accessible_campaign_rows();

    if ($requested > 0) {
        foreach ($rows as $row) {
            if ((int)$row['id'] === $requested) return $requested;
        }
    }

    return isset($rows[0]['id']) ? (int)$rows[0]['id'] : 0;
}

function site_quality_score_values(
    int $total,
    int $validated,
    int $evidence,
    int $notes,
    int $returned
): int {
    if ($total <= 0) return 0;
    $score = ($validated / $total) * 70
        + ($evidence / max(1, $validated)) * 20
        + ($notes / max(1, $validated)) * 10
        - ($returned * 5);
    return max(0, min(100, (int)round($score)));
}

function site_quality_score(int $campaignId,int $sedeId): int
{
    $total=(int)(Database::fetchOne('SELECT COUNT(*) total FROM campaign_equipment WHERE campaign_id=? AND sede_id=?',[$campaignId,$sedeId])['total']??0);if($total===0)return 0;
    $row=Database::fetchOne("SELECT COUNT(*) validated,SUM(CASE WHEN evidence_path IS NOT NULL AND evidence_path<>'' THEN 1 ELSE 0 END) evidence,SUM(CASE WHEN notes IS NOT NULL AND notes<>'' THEN 1 ELSE 0 END) notes,SUM(CASE WHEN review_status='devuelto' THEN 1 ELSE 0 END) returned FROM equipment_validations WHERE campaign_id=? AND reported_by_sede_id=? AND validation_status<>'pendiente'",[$campaignId,$sedeId])?:[];
    $validated=(int)($row['validated']??0);$evidence=(int)($row['evidence']??0);$notes=(int)($row['notes']??0);$returned=(int)($row['returned']??0);
    return site_quality_score_values($total,$validated,$evidence,$notes,$returned);
}

function initialization_progress_panel(array $state, bool $showAction = true): string
{
    $stepData = [
        1 => [
            'title' => 'Maestro de Sedes',
            'description' => 'Carga la estructura territorial oficial: identificador, departamento, municipio, tipo, nombre y dirección de cada sede.',
            'state' => $state['sedes'],
        ],
        2 => [
            'title' => 'Inventario GLPI · Computadores',
            'description' => 'Carga únicamente CPU, portátiles y computadores Todo en Uno y los asocia con el maestro de sedes.',
            'state' => $state['glpi'],
        ],
        3 => [
            'title' => 'Inventario de Almacén',
            'description' => 'Concilia seriales y placas, define la categoría patrimonial e incorpora escáneres y UPS.',
            'state' => $state['warehouse'],
        ],
    ];

    $html = '<div class="card initialization-summary"><div class="d-flex flex-wrap justify-content-between align-items-start gap-3">'
        . '<div><div class="kicker">Inicialización obligatoria</div><h2>Preparación de datos de SIVI</h2><p>Complete las tres etapas en orden. Las campañas y los módulos operativos se habilitarán únicamente al finalizar.</p></div>'
        . '<div class="text-end"><strong>' . (int)$state['progress'] . '%</strong><div class="muted">' . e($state['next_label']) . '</div></div></div>'
        . '<div class="progress mb-4" role="progressbar" aria-valuenow="' . (int)$state['progress'] . '" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar ' . progress_width_class((int)$state['progress']) . '"></div></div>'
        . '<div class="row g-3">';

    foreach ($stepData as $number => $step) {
        $complete = !empty($step['state']['complete']);
        $active = !$complete && (int)$state['next_step'] === $number;
        $class = $complete ? 'border-success' : ($active ? 'border-primary' : 'border-secondary-subtle');
        $badge = $complete
            ? '<span class="badge text-bg-success">Completada</span>'
            : ($active ? '<span class="badge text-bg-primary">Siguiente</span>' : '<span class="badge text-bg-secondary">Pendiente</span>');
        $file = trim((string)($step['state']['file'] ?? ''));
        $date = trim((string)($step['state']['completed_at'] ?? ''));
        $meta = $complete
            ? '<div class="small muted mt-2">Registros vigentes: ' . number_format((int)($step['state']['rows'] ?? 0), 0, ',', '.')
                . ($file !== '' ? '<br>Archivo: ' . e($file) : '')
                . ($date !== '' ? '<br>Completada: ' . e($date) : '') . '</div>'
            : '';
        $html .= '<div class="col-lg-4"><div class="card h-100 ' . $class . '"><div class="d-flex justify-content-between align-items-center mb-2"><span class="initialization-step-number">' . $number . '</span>' . $badge . '</div>'
            . '<h3>' . e($step['title']) . '</h3><p>' . e($step['description']) . '</p>' . $meta . '</div></div>';
    }
    $html .= '</div>';
    if ($showAction && Auth::is('admin_gi')) {
        $html .= '<div class="form-actions mt-4"><a class="btn btn-primary" href="' . e(route_url('importar')) . '">Continuar inicialización</a></div>';
    } elseif ($showAction && !$state['ready']) {
        $html .= '<div class="note note-info mt-4">La carga inicial debe ser completada por un usuario Administrador GI o Superadministrador.</div>';
    }
    return $html . '</div>';
}

function dashboard_page(): void
{
    $initialization = InitializationState::status();
    if (!$initialization['ready']) {
        $content = initialization_progress_panel($initialization, true);
        render_page('Inicialización de SIVI', $content, [
            'subtitle' => 'Los módulos operativos permanecen protegidos hasta completar las tres fuentes de información.',
        ]);
        return;
    }
    [$sedeWhere, $sedeParams] = Scope::sedeCondition('s');
    $campaignId = selected_campaign_id();
    $user = Auth::user();
    $selectedDepartment = trim((string)($_GET['department'] ?? ''));
    $selectedMunicipality = trim((string)($_GET['municipality'] ?? ''));

    $campaignScopeFilter = $sedeWhere;
    $campaignScopeParams = $sedeParams;
    if ($campaignId > 0) {
        $campaignScopeFilter .= ' AND EXISTS (SELECT 1 FROM campaign_sedes cscope WHERE cscope.campaign_id=? AND cscope.sede_id=s.id)';
        $campaignScopeParams[] = $campaignId;
    }
    $scopeFilter = $campaignScopeFilter;
    $scopeParams = $campaignScopeParams;
    if ($selectedDepartment !== '') {
        $scopeFilter .= ' AND s.cod_dd=?';
        $scopeParams[] = $selectedDepartment;
    }
    if ($selectedMunicipality !== '') {
        $scopeFilter .= ' AND s.municipio=?';
        $scopeParams[] = $selectedMunicipality;
    }

    $sedes = (int)(Database::fetchOne("SELECT COUNT(*) total FROM sedes s WHERE {$scopeFilter}", $scopeParams)['total'] ?? 0);
    if ($campaignId > 0) {
        $equipmentParams = array_merge([$campaignId], $scopeParams);
        $equipment = (int)(Database::fetchOne("SELECT COUNT(*) total FROM campaign_equipment ce JOIN equipment e ON e.id=ce.equipment_id JOIN sedes s ON s.id=ce.sede_id WHERE ce.campaign_id=? AND {$scopeFilter}", $equipmentParams)['total'] ?? 0);
        $categoryRows = Database::fetchAll("SELECT e.asset_category,COUNT(*) total FROM campaign_equipment ce JOIN equipment e ON e.id=ce.equipment_id JOIN sedes s ON s.id=ce.sede_id WHERE ce.campaign_id=? AND {$scopeFilter} GROUP BY e.asset_category", $equipmentParams);
    } else {
        $equipment = (int)(Database::fetchOne("SELECT COUNT(*) total FROM equipment e LEFT JOIN sedes s ON s.id=e.current_sede_id WHERE e.active=1 AND {$scopeFilter}", $scopeParams)['total'] ?? 0);
        $categoryRows = Database::fetchAll("SELECT e.asset_category,COUNT(*) total FROM equipment e LEFT JOIN sedes s ON s.id=e.current_sede_id WHERE e.active=1 AND {$scopeFilter} GROUP BY e.asset_category", $scopeParams);
    }
    $categoryTotals = array_fill_keys(array_keys(asset_category_labels(true)), 0);
    foreach ($categoryRows as $categoryRow) $categoryTotals[$categoryRow['asset_category']] = (int)$categoryRow['total'];

    $validated = 0;
    $transferred = 0;
    $submittedSedes = 0;
    $approvedSedes = 0;
    if ($campaignId > 0) {
        $params = array_merge([$campaignId], $scopeParams);
        $validated = (int)(Database::fetchOne("SELECT COUNT(*) total FROM equipment_validations ev JOIN sedes s ON s.id=ev.reported_by_sede_id WHERE ev.campaign_id=? AND ev.validation_status<>'pendiente' AND {$scopeFilter}", $params)['total'] ?? 0);
        $transferred = (int)(Database::fetchOne("SELECT COUNT(*) total FROM equipment_validations ev JOIN sedes s ON s.id=ev.reported_by_sede_id WHERE ev.campaign_id=? AND ev.physical_condition='trasladado' AND {$scopeFilter}", $params)['total'] ?? 0);
        $submittedSedes = (int)(Database::fetchOne("SELECT COUNT(*) total FROM campaign_sedes cs JOIN sedes s ON s.id=cs.sede_id WHERE cs.campaign_id=? AND cs.status IN ('enviado','en_revision','aprobado','cerrado') AND {$scopeFilter}", $params)['total'] ?? 0);
        $approvedSedes = (int)(Database::fetchOne("SELECT COUNT(*) total FROM campaign_sedes cs JOIN sedes s ON s.id=cs.sede_id WHERE cs.campaign_id=? AND cs.status IN ('aprobado','cerrado') AND {$scopeFilter}", $params)['total'] ?? 0);
    }
    $equipmentPercent = $equipment > 0 ? round(($validated / $equipment) * 100, 1) : 0;
    $sedePercent = $sedes > 0 ? round(($submittedSedes / $sedes) * 100, 1) : 0;
    $campaign = $campaignId ? Database::fetchOne('SELECT * FROM campaigns WHERE id=?', [$campaignId]) : null;

    $accessibleCampaigns = accessible_campaign_rows();
    $availableDepartments = Database::fetchAll("SELECT DISTINCT s.cod_dd,s.departamento FROM sedes s WHERE {$campaignScopeFilter} ORDER BY s.departamento", $campaignScopeParams);
    $municipalityWhere = $campaignScopeFilter;
    $municipalityParams = $campaignScopeParams;
    if ($selectedDepartment !== '') {
        $municipalityWhere .= ' AND s.cod_dd=?';
        $municipalityParams[] = $selectedDepartment;
    }
    $availableMunicipalities = Database::fetchAll("SELECT DISTINCT s.municipio FROM sedes s WHERE {$municipalityWhere} AND NULLIF(s.municipio,'') IS NOT NULL ORDER BY s.municipio", $municipalityParams);

    $content = (Auth::is('registrador') && $campaignId > 0 ? registrar_campaign_panel($campaignId,(int)($user['sede_id']??0)) : '') . '<div class="card dashboard-filter-card"><div class="dashboard-filter-head"><div><div class="kicker">Vista de trabajo</div><h2>Seleccione la campaña y el territorio</h2><p>Los indicadores y pendientes se actualizan con los filtros seleccionados.</p></div></div><form method="get" class="row g-3 align-items-end">'
        . '<input type="hidden" name="page" value="dashboard">'
        . '<div class="col-lg-3"><label class="form-label">Campaña</label><select class="form-select" name="campaign_id"><option value="0">Sin campaña</option>';
    foreach ($accessibleCampaigns as $campaignOption) {
        $selected = $campaignId === (int)$campaignOption['id'] ? ' selected' : '';
        $content .= '<option value="' . (int)$campaignOption['id'] . '"' . $selected . '>' . e($campaignOption['name'] . ' · ' . $campaignOption['status']) . '</option>';
    }
    $content .= '</select></div><div class="col-lg-3"><label class="form-label">Departamento</label><select class="form-select" name="department"><option value="">Todos los departamentos</option>';
    foreach ($availableDepartments as $row) {
        $selected = $selectedDepartment === (string)$row['cod_dd'] ? ' selected' : '';
        $content .= '<option value="' . e($row['cod_dd']) . '"' . $selected . '>' . e($row['departamento']) . '</option>';
    }
    $content .= '</select></div><div class="col-lg-3"><label class="form-label">Municipio</label><select class="form-select" name="municipality"><option value="">Todos los municipios</option>';
    foreach ($availableMunicipalities as $row) {
        $selected = $selectedMunicipality === (string)$row['municipio'] ? ' selected' : '';
        $content .= '<option value="' . e($row['municipio']) . '"' . $selected . '>' . e($row['municipio']) . '</option>';
    }
    $content .= '</select></div><div class="col-lg-3 d-flex gap-2"><button class="btn btn-primary flex-fill">Actualizar vista</button><a class="btn btn-outline-secondary" href="' . e(route_url('dashboard',['campaign_id'=>$campaignId])) . '">Limpiar territorio</a></div></form></div>';

    $content .= '<div class="metrics">'
        . metric_card('Sedes visibles',$sedes,"{$sedePercent}% enviadas")
        . metric_card('Equipos registrados',$equipment,'Inventario vigente','green')
        . metric_card('Equipos validados',$validated,"{$equipmentPercent}% de avance",'purple')
        . metric_card('Trasladados',$transferred,'Reportados en la campaña','orange')
        . metric_card('Sedes aprobadas',$approvedSedes,"De {$sedes} sedes",'orange')
        . metric_card('Calidad estimada',($campaignId>0&&Auth::is('registrador')&&Auth::user()['sede_id']?site_quality_score($campaignId,(int)Auth::user()['sede_id']):$equipmentPercent).'%','Completitud y evidencia','blue')
        . '</div>';

    $taskItems = [];
    $currentUserId = (int)(Auth::id() ?? 0);
    $unreadNotifications = (int)(Database::fetchOne('SELECT COUNT(*) total FROM internal_notifications WHERE user_id=? AND read_at IS NULL',[$currentUserId])['total'] ?? 0);
    if ($unreadNotifications > 0) {
        $taskItems[] = ['icon'=>'🔔','tone'=>'info','count'=>$unreadNotifications,'title'=>'Notificaciones sin leer','text'=>'Revise mensajes, recordatorios y decisiones recientes.','route'=>'notificaciones','action'=>'Abrir notificaciones'];
    }
    if ($campaignId > 0 && Auth::is('registrador')) {
        $ownSedeId = (int)($user['sede_id'] ?? 0);
        $pendingValidation = $ownSedeId > 0 ? (int)(Database::fetchOne("SELECT COUNT(*) total FROM campaign_equipment ce WHERE ce.campaign_id=? AND ce.sede_id=? AND NOT EXISTS(SELECT 1 FROM equipment_validations ev WHERE ev.campaign_id=ce.campaign_id AND ev.equipment_id=ce.equipment_id AND ev.reported_by_sede_id=ce.sede_id AND ev.validation_status<>'pendiente')",[$campaignId,$ownSedeId])['total'] ?? 0) : 0;
        $pendingCorrections = $ownSedeId > 0 ? (int)(Database::fetchOne("SELECT COUNT(*) total FROM validation_corrections vc JOIN equipment_validations ev ON ev.id=vc.validation_id WHERE vc.status='pendiente' AND ev.reported_by_sede_id=?",[$ownSedeId])['total'] ?? 0) : 0;
        $taskItems[] = ['icon'=>'✓','tone'=>$pendingValidation>0?'primary':'success','count'=>$pendingValidation,'title'=>'Equipos pendientes de validar','text'=>$pendingValidation>0?'Continúe la revisión física de los activos de su sede.':'La sede no tiene equipos pendientes en esta campaña.','route'=>'equipos','params'=>['campaign_id'=>$campaignId,'sede_id'=>$ownSedeId],'action'=>$pendingValidation>0?'Continuar validación':'Ver inventario'];
        if ($pendingCorrections > 0) $taskItems[] = ['icon'=>'✎','tone'=>'warning','count'=>$pendingCorrections,'title'=>'Correcciones solicitadas','text'=>'Existen validaciones devueltas que requieren ajustes.','route'=>'correcciones','action'=>'Atender correcciones'];
    } elseif ($campaignId > 0) {
        [$taskScope,$taskParams] = Scope::sedeCondition('s');
        $reviewPending = (int)(Database::fetchOne("SELECT COUNT(*) total FROM equipment_validations ev JOIN sedes s ON s.id=ev.reported_by_sede_id WHERE ev.campaign_id=? AND ev.review_status='pendiente' AND ev.validation_status<>'pendiente' AND {$taskScope}",array_merge([$campaignId],$taskParams))['total'] ?? 0);
        $sitesPending = (int)(Database::fetchOne("SELECT COUNT(*) total FROM campaign_sedes cs JOIN sedes s ON s.id=cs.sede_id WHERE cs.campaign_id=? AND cs.status IN ('pendiente','en_diligenciamiento','devuelto') AND {$taskScope}",array_merge([$campaignId],$taskParams))['total'] ?? 0);
        $associationPending = (int)(Database::fetchOne("SELECT COUNT(*) total FROM equipment e LEFT JOIN sedes s ON s.id=e.current_sede_id WHERE e.active=1 AND e.association_review_required=1 AND {$taskScope}",$taskParams)['total'] ?? 0);
        $transferPending = (int)(Database::fetchOne("SELECT COUNT(*) total FROM equipment_transfers t LEFT JOIN sedes s ON s.id=t.origin_sede_id WHERE t.status='pendiente_aprobacion' AND {$taskScope}",$taskParams)['total'] ?? 0);
        $taskItems[] = ['icon'=>'◎','tone'=>$reviewPending>0?'warning':'success','count'=>$reviewPending,'title'=>'Validaciones por revisar','text'=>'Revise y apruebe la información enviada por las sedes.','route'=>'seguimiento','params'=>['campaign_id'=>$campaignId],'action'=>'Abrir seguimiento'];
        $taskItems[] = ['icon'=>'⌂','tone'=>$sitesPending>0?'primary':'success','count'=>$sitesPending,'title'=>'Sedes pendientes','text'=>'Sedes que aún no han terminado o fueron devueltas.','route'=>'seguimiento','params'=>['campaign_id'=>$campaignId],'action'=>'Ver sedes'];
        if ($associationPending > 0) $taskItems[] = ['icon'=>'⚠','tone'=>'danger','count'=>$associationPending,'title'=>'Asociaciones territoriales por confirmar','text'=>'Elementos asignados por reglas de contingencia requieren revisión.','route'=>'equipos','params'=>['campaign_id'=>$campaignId,'association_review'=>1],'action'=>'Revisar asociaciones'];
        if ($transferPending > 0) $taskItems[] = ['icon'=>'⇄','tone'=>'info','count'=>$transferPending,'title'=>'Traslados pendientes de decisión','text'=>'Solicitudes listas para aprobar o rechazar.','route'=>'traslados','params'=>['campaign_id'=>$campaignId],'action'=>'Revisar traslados'];
    }
    if ($taskItems !== []) {
        $content .= '<section class="work-center" aria-labelledby="work-center-title"><div class="section-heading"><div><div class="kicker">Prioridades</div><h2 id="work-center-title">Qué debe atender ahora</h2><p>SIVI organiza las tareas más importantes de acuerdo con su perfil.</p></div></div><div class="task-grid">';
        foreach ($taskItems as $task) {
            $taskUrl = route_url($task['route'], $task['params'] ?? []);
            $content .= '<a class="task-card task-card-' . e($task['tone']) . '" href="' . e($taskUrl) . '"><span class="task-icon" aria-hidden="true">' . e($task['icon']) . '</span><span class="task-copy"><strong>' . e($task['title']) . '</strong><small>' . e($task['text']) . '</small><b>' . e($task['action']) . ' →</b></span><span class="task-count">' . e($task['count']) . '</span></a>';
        }
        $content .= '</div></section>';
    }

    if ($campaign) {
        $content .= '<div class="card"><div class="d-flex flex-wrap justify-content-between align-items-center gap-3"><div><div class="kicker">Campaña seleccionada</div><h2 class="mb-1">' . e($campaign['name']) . '</h2><p class="muted mb-0">Fecha límite: ' . e($campaign['end_date'] ?: 'Sin definir') . '</p></div><div class="text-end"><strong class="fs-3">' . e($sedePercent) . '%</strong><div class="muted">avance de sedes</div></div></div><div class="progress mt-3 progress-h-12"><span class="' . progress_width_class(min(100,$sedePercent)) . '"></span></div></div>';
    }

    if (Auth::is('admin_gi')) {
        $rows = Database::fetchAll("SELECT s.cod_dd,s.departamento,COUNT(DISTINCT s.id) total_sedes,COUNT(DISTINCT CASE WHEN cs.status IN ('enviado','en_revision','aprobado','cerrado') THEN s.id END) sedes_enviadas,COUNT(DISTINCT CASE WHEN cs.status IN ('aprobado','cerrado') THEN s.id END) sedes_aprobadas,COUNT(DISTINCT e.id) total_equipos,COUNT(DISTINCT CASE WHEN ev.validation_status<>'pendiente' THEN ev.id END) equipos_validados FROM sedes s JOIN campaign_sedes cs ON cs.sede_id=s.id AND cs.campaign_id=? LEFT JOIN campaign_equipment ce ON ce.campaign_id=cs.campaign_id AND ce.sede_id=s.id LEFT JOIN equipment e ON e.id=ce.equipment_id LEFT JOIN equipment_validations ev ON ev.equipment_id=e.id AND ev.reported_by_sede_id=s.id AND ev.campaign_id=? WHERE {$campaignScopeFilter} GROUP BY s.cod_dd,s.departamento ORDER BY s.departamento", array_merge([$campaignId,$campaignId], $campaignScopeParams));
        $content .= '<div class="card"><div class="d-flex justify-content-between align-items-center mb-3"><div><div class="kicker">Seguimiento nacional</div><h3 class="mb-0">Avance por departamento</h3></div></div><div class="table-wrap"><table class="table table-hover align-middle mb-0"><thead><tr><th>Departamento</th><th>Sedes</th><th>Enviadas</th><th>Aprobadas</th><th>Equipos</th><th>Validados</th><th class="progress-col">Avance</th><th></th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $pct = (int)$row['total_sedes'] > 0 ? round(((int)$row['sedes_enviadas']/(int)$row['total_sedes'])*100,1) : 0;
            $content .= '<tr><td><strong>' . e($row['departamento']) . '</strong></td><td>' . e($row['total_sedes']) . '</td><td>' . e($row['sedes_enviadas']) . '</td><td>' . e($row['sedes_aprobadas']) . '</td><td>' . e($row['total_equipos']) . '</td><td>' . e($row['equipos_validados']) . '</td><td><div class="d-flex align-items-center gap-2"><div class="progress flex-grow-1"><span class="' . progress_width_class(min(100,$pct)) . '"></span></div><strong>' . e($pct) . '%</strong></div></td><td><a class="btn btn-sm btn-outline-primary" href="' . e(route_url('dashboard',['campaign_id'=>$campaignId,'department'=>$row['cod_dd']])) . '">Ver municipios</a></td></tr>';
        }
        $content .= '</tbody></table></div></div>';
    }

    if (Auth::is('formador') || (Auth::is('admin_gi') && $selectedDepartment !== '')) {
        $municipalityParams = array_merge([$campaignId,$campaignId], $scopeParams);
        $rows = Database::fetchAll("SELECT s.municipio,COUNT(DISTINCT s.id) total_sedes,COUNT(DISTINCT CASE WHEN cs.status IN ('enviado','en_revision','aprobado','cerrado') THEN s.id END) sedes_enviadas,COUNT(DISTINCT CASE WHEN cs.status IN ('aprobado','cerrado') THEN s.id END) sedes_aprobadas,COUNT(DISTINCT e.id) total_equipos,COUNT(DISTINCT CASE WHEN ev.validation_status<>'pendiente' THEN ev.id END) equipos_validados FROM sedes s JOIN campaign_sedes cs ON cs.sede_id=s.id AND cs.campaign_id=? LEFT JOIN campaign_equipment ce ON ce.campaign_id=cs.campaign_id AND ce.sede_id=s.id LEFT JOIN equipment e ON e.id=ce.equipment_id LEFT JOIN equipment_validations ev ON ev.equipment_id=e.id AND ev.reported_by_sede_id=s.id AND ev.campaign_id=? WHERE {$scopeFilter} GROUP BY s.municipio ORDER BY s.municipio", $municipalityParams);
        $content .= '<div class="card"><div class="kicker">Seguimiento territorial</div><h3>Avance por municipio</h3><div class="table-wrap"><table class="table table-hover align-middle mb-0"><thead><tr><th>Municipio</th><th>Sedes</th><th>Enviadas</th><th>Aprobadas</th><th>Equipos</th><th>Validados</th><th class="progress-col">Avance</th><th></th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $pct = (int)$row['total_sedes'] > 0 ? round(((int)$row['sedes_enviadas']/(int)$row['total_sedes'])*100,1) : 0;
            $content .= '<tr><td><strong>' . e($row['municipio']) . '</strong></td><td>' . e($row['total_sedes']) . '</td><td>' . e($row['sedes_enviadas']) . '</td><td>' . e($row['sedes_aprobadas']) . '</td><td>' . e($row['total_equipos']) . '</td><td>' . e($row['equipos_validados']) . '</td><td><div class="d-flex align-items-center gap-2"><div class="progress flex-grow-1"><span class="' . progress_width_class(min(100,$pct)) . '"></span></div><strong>' . e($pct) . '%</strong></div></td><td><a class="btn btn-sm btn-outline-primary" href="' . e(route_url('dashboard',['campaign_id'=>$campaignId,'department'=>$selectedDepartment,'municipality'=>$row['municipio']])) . '">Ver sedes</a></td></tr>';
        }
        $content .= '</tbody></table></div></div>';
    }

    if ((Auth::is('formador') || Auth::is('admin_gi')) && $selectedMunicipality !== '') {
        $params = array_merge([$campaignId,$campaignId], $scopeParams);
        $rows = Database::fetchAll(
            "SELECT s.id,s.identificador,s.nombre_sede,s.tipo_sede,"
            . "COALESCE(cs.status,'pendiente') campaign_status,"
            . "COUNT(DISTINCT e.id) total_equipos,"
            . "COUNT(DISTINCT CASE WHEN ev.validation_status<>'pendiente' "
            . "THEN ev.id END) equipos_validados,"
            . "COUNT(DISTINCT CASE WHEN ev.validation_status<>'pendiente' "
            . "AND ev.evidence_path IS NOT NULL AND ev.evidence_path<>'' "
            . "THEN ev.id END) quality_evidence,"
            . "COUNT(DISTINCT CASE WHEN ev.validation_status<>'pendiente' "
            . "AND ev.notes IS NOT NULL AND ev.notes<>'' "
            . "THEN ev.id END) quality_notes,"
            . "COUNT(DISTINCT CASE WHEN ev.validation_status<>'pendiente' "
            . "AND ev.review_status='devuelto' THEN ev.id END) quality_returned "
            . "FROM sedes s "
            . "JOIN campaign_sedes cs ON cs.sede_id=s.id "
            . "AND cs.campaign_id=? "
            . "LEFT JOIN campaign_equipment ce "
            . "ON ce.campaign_id=cs.campaign_id AND ce.sede_id=s.id "
            . "LEFT JOIN equipment e ON e.id=ce.equipment_id "
            . "LEFT JOIN equipment_validations ev "
            . "ON ev.equipment_id=e.id "
            . "AND ev.reported_by_sede_id=s.id AND ev.campaign_id=? "
            . "WHERE {$scopeFilter} "
            . "GROUP BY s.id,s.identificador,s.nombre_sede,s.tipo_sede,cs.status "
            . "ORDER BY s.nombre_sede",
            $params
        );
        $content .= '<div class="card"><div class="kicker">Detalle operativo</div><h3>Avance por sede</h3><div class="table-wrap"><table class="table table-hover align-middle mb-0"><thead><tr><th>Sede</th><th>Tipo</th><th>Estado</th><th>Equipos</th><th>Validados</th><th>Avance</th><th>Calidad</th><th></th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $pct = (int)$row['total_equipos'] > 0 ? round(((int)$row['equipos_validados']/(int)$row['total_equipos'])*100,1) : 0;
            $content .= '<tr><td><strong>' . e($row['nombre_sede']) . '</strong><div class="muted">' . e($row['identificador']) . '</div></td><td>' . e($row['tipo_sede']) . '</td><td>' . status_badge($row['campaign_status']) . '</td><td>' . e($row['total_equipos']) . '</td><td>' . e($row['equipos_validados']) . '</td><td><div class="progress"><span class="' . progress_width_class(min(100,$pct)) . '"></span></div><small>' . e($pct) . '%</small></td><td><strong>' . site_quality_score_values(
                (int)$row['total_equipos'],
                (int)$row['equipos_validados'],
                (int)$row['quality_evidence'],
                (int)$row['quality_notes'],
                (int)$row['quality_returned']
            ) . '%</strong></td><td><a class="btn btn-sm btn-outline-primary" href="' . e(route_url('equipos',['campaign_id'=>$campaignId,'sede_id'=>$row['id']])) . '">Ver equipos</a></td></tr>';
        }
        $content .= '</tbody></table></div></div>';
    }

    $content .= '<div class="card"><div class="kicker">Composición del inventario</div><h3>Activos por categoría</h3><div class="metrics">'
        . metric_card('CPU', $categoryTotals['cpu'], 'Equipos de escritorio', 'blue')
        . metric_card('Portátiles', $categoryTotals['portatil'], 'Computadores portátiles', 'purple')
        . metric_card('PC Todo en Uno', $categoryTotals['pc_todo_en_uno'], 'Equipos integrados', 'green')
        . metric_card('Monitores', $categoryTotals['monitor'], 'Pantallas registradas', 'purple')
        . metric_card('Impresoras', $categoryTotals['impresora'], 'Impresoras y multifuncionales', 'green')
        . metric_card('Escáneres', $categoryTotals['escaner'], 'Inventario de Almacén', 'blue')
        . metric_card('UPS', $categoryTotals['ups'], 'Inventario de Almacén', 'orange')
        . metric_card('Pendientes', $categoryTotals['otro'], 'Sin clasificación definitiva', 'orange') . '</div></div>';

    render_page('Resumen', $content, ['subtitle' => Auth::is('admin_gi') ? 'Seguimiento nacional por departamento, municipio y sede.' : (Auth::is('formador') ? 'Seguimiento departamental por municipio y sede.' : 'Seguimiento de la sede asignada.')]);
}

function sedes_page(): void
{
    [$scopeWhere, $scopeParams] = Scope::sedeCondition('s');
    $availableSedes = Database::fetchAll(
        "SELECT s.id,s.identificador,s.cod_dd,s.departamento,s.municipio,s.tipo_sede,s.nombre_sede FROM sedes s WHERE {$scopeWhere} ORDER BY s.departamento,s.municipio,s.nombre_sede",
        $scopeParams
    );

    $where = $scopeWhere;
    $params = $scopeParams;
    $q = trim((string)($_GET['q'] ?? ''));
    $department = trim((string)($_GET['department'] ?? ''));
    $municipality = trim((string)($_GET['municipality'] ?? ''));
    $municipalityName = $municipality;
    if (str_contains($municipality, '|')) {
        [$municipalityDepartment, $municipalityName] = explode('|', $municipality, 2);
        if ($department === '') {
            $department = $municipalityDepartment;
        }
    }
    $siteType = trim((string)($_GET['site_type'] ?? ''));
    $sedeId = (int)($_GET['sede_id'] ?? 0);

    if ($department !== '') {
        $where .= ' AND s.cod_dd=?';
        $params[] = $department;
    }
    if ($municipalityName !== '') {
        $where .= ' AND s.municipio=?';
        $params[] = $municipalityName;
    }
    if ($siteType !== '') {
        $where .= ' AND s.tipo_sede=?';
        $params[] = $siteType;
    }
    if ($sedeId > 0) {
        if (!Scope::canAccessSede($sedeId)) {
            render_error('Acceso denegado', 'La sede seleccionada no pertenece a su alcance.');
            return;
        }
        $where .= ' AND s.id=?';
        $params[] = $sedeId;
    }
    if ($q !== '') {
        $where .= ' AND (s.identificador LIKE ? OR s.nombre_sede LIKE ? OR s.municipio LIKE ? OR s.departamento LIKE ?)';
        $like = "%{$q}%";
        array_push($params, $like, $like, $like, $like);
    }

    $page = max(1, (int)($_GET['p'] ?? 1));
    $per = 30;
    $offset = ($page - 1) * $per;
    $total = (int)(Database::fetchOne("SELECT COUNT(*) total FROM sedes s WHERE {$where}", $params)['total'] ?? 0);
    $rows = Database::fetchAll(
        "SELECT s.*,(SELECT COUNT(*) FROM equipment e WHERE e.current_sede_id=s.id AND e.active=1) equipment_count FROM sedes s WHERE {$where} ORDER BY s.departamento,s.municipio,s.nombre_sede LIMIT {$per} OFFSET {$offset}",
        $params
    );

    $filterFields = territorial_filter_fields($availableSedes, $department, $municipality, $siteType, $sedeId);
    $content = '<div class="card"><div class="toolbar"><form class="filters" method="get" data-territorial-filters><input type="hidden" name="page" value="sedes">'
        . $filterFields
        . field('q', 'Buscar sede', $q, 'text', ['placeholder' => 'Identificador o nombre'])
        . '<button class="btn btn-secondary">Aplicar filtros</button><a class="btn btn-secondary" href="' . e(route_url('sedes')) . '">Limpiar</a></form></div>';

    if (!$rows) {
        $content .= empty_state('No se encontraron sedes', 'Ajuste los filtros o revise el alcance asignado al usuario.');
    } else {
        $content .= '<div class="table-wrap"><table><thead><tr><th>Identificador</th><th>Departamento</th><th>Municipio</th><th>Sede</th><th>Dirección actual</th><th>Equipos</th><th></th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $content .= '<tr><td><strong>' . e($row['identificador']) . '</strong><br><span class="muted">' . e($row['tipo_sede']) . '</span></td><td>' . e($row['departamento']) . '</td><td>' . e($row['municipio']) . '</td><td>' . e($row['nombre_sede']) . '</td><td>' . e($row['direccion_actual'] ?: $row['direccion_original']) . '</td><td>' . e($row['equipment_count']) . '</td><td><a class="btn btn-sm" href="' . e(route_url('sede_editar', ['id' => $row['id']])) . '">Revisar</a></td></tr>';
        }
        $content .= '</tbody></table></div>' . pagination($total, $page, $per, 'sedes', [
            'q' => $q,
            'department' => $department,
            'municipality' => $municipality,
            'site_type' => $siteType,
            'sede_id' => $sedeId,
        ]);
    }
    $content .= '</div>';
    render_page('Sedes', $content, ['subtitle' => "{$total} sedes disponibles con los filtros aplicados."]);
}

function sede_edit_page(): void
{
    $id=(int)($_GET['id'] ?? $_POST['id'] ?? 0);
    if (!$id || !Scope::canAccessSede($id)) { render_error('Sede no disponible','No puede consultar esta sede.'); return; }
    $sede=Database::fetchOne('SELECT * FROM sedes WHERE id=?',[$id]);
    if (!$sede) { render_error('Sede no encontrada','El registro solicitado no existe.'); return; }
    if (request_method('POST')) {
        verify_csrf();
        $old=$sede;
        $address=trim((string)($_POST['direccion_actual'] ?? ''));
        $contactPhone=normalize_contact_phone((string)($_POST['telefono_contacto'] ?? ''));
        if ($address==='') { flash('danger','La dirección no puede quedar vacía.'); redirect('sede_editar',['id'=>$id]); }
        if (!valid_contact_phone($contactPhone, true)) {
            flash('danger','El número de contacto debe tener 10 dígitos y comenzar por 60 para fijo o por 3 para celular.');
            redirect('sede_editar',['id'=>$id]);
        }
        Database::execute('UPDATE sedes SET direccion_actual=?,direccion_observacion=?,email_contacto=?,telefono_contacto=?,direccion_actualizada_por=?,direccion_actualizada_en=NOW() WHERE id=?',[
            $address,trim((string)($_POST['direccion_observacion'] ?? '')),trim((string)($_POST['email_contacto'] ?? '')),$contactPhone!==''?$contactPhone:null,Auth::user()['id'],$id
        ]);
        audit('update_sede_address','sede',$id,$old,['direccion_actual'=>$address,'observacion'=>$_POST['direccion_observacion'] ?? '']);
        flash('success','La información de la sede fue actualizada.'); redirect('sede_editar',['id'=>$id]);
    }
    $equipmentCount=(int)(Database::fetchOne('SELECT COUNT(*) total FROM equipment WHERE current_sede_id=? AND active=1',[$id])['total'] ?? 0);
    $content='<div class="card"><div class="split"><div class="comparison"><strong>Dirección registrada</strong><p>'.e($sede['direccion_original'] ?: 'Sin dirección').'</p></div><div class="comparison"><strong>Dirección confirmada por la sede</strong><p>'.e($sede['direccion_actual'] ?: 'Pendiente').'</p></div></div></div>';
    $contactEmail = trim((string)($sede['email_contacto'] ?? ''));
    if ($contactEmail === '') { $assigned = Database::fetchOne("SELECT email FROM users WHERE sede_id=? AND role='registrador' AND active=1 ORDER BY id LIMIT 1", [$id]); $contactEmail = (string)($assigned['email'] ?? ''); }
    $content.='<div class="card"><h2>'.e($sede['identificador'].' · '.$sede['nombre_sede']).'</h2><p class="muted">'.e($sede['departamento'].' / '.$sede['municipio'].' · '.$sede['tipo_sede']).' · '.$equipmentCount.' equipos</p><form method="post">'.csrf_field().'<input type="hidden" name="id" value="'.$id.'"><div class="form-grid">'.field('direccion_actual','Dirección confirmada o corregida',$sede['direccion_actual'] ?: $sede['direccion_original'],'textarea',['required'=>true]).field('direccion_observacion','Observación del cambio',$sede['direccion_observacion'],'textarea').field('email_contacto','Correo de contacto de la sede',$contactEmail,'email',['help'=>'Puede modificarlo sin cambiar el correo de acceso del usuario asignado.']).field('telefono_contacto','Número de contacto',$sede['telefono_contacto'],'tel',[
        'placeholder'=>'6012345678 o 3001234567',
        'help'=>contact_phone_help(),
        'attributes'=>[
            'inputmode'=>'numeric',
            'autocomplete'=>'tel',
            'minlength'=>'10',
            'maxlength'=>'10',
            'pattern'=>contact_phone_pattern(),
            'data-contact-phone'=>true,
            'title'=>'Digite 10 números: fijo desde 60 o celular desde 3',
        ],
    ]).'</div><div class="form-actions"><button class="btn">Guardar información</button><a class="btn btn-secondary" href="'.e(route_url('equipos',['sede_id'=>$id])).'">Ver equipos</a></div></form></div>';
    render_page('Información de la sede',$content,['subtitle'=>'La dirección original se conserva para mantener trazabilidad.']);
}


function equipment_search_page(): void
{
    $q=trim((string)($_GET['q']??''));
    $campaignId=selected_campaign_id();
    $results=['equipment'=>[],'additional'=>[]];
    if($q!==''&&mb_strlen($q)<2){flash('warning','Escriba al menos dos caracteres para buscar.');}
    elseif(mb_strlen($q)>=2){$results=OperationalExperience::search($q,50);}

    $content='<div class="card global-equipment-search-page"><div class="kicker">Búsqueda global</div><h2>Localice un elemento antes de reportarlo</h2><p class="muted">Busque por serial, Placa RNEC, hostname, marca, modelo o usuario. Los resultados respetan su alcance territorial.</p><form method="get" class="global-equipment-search-form"><input type="hidden" name="page" value="buscar_equipo"><input type="hidden" name="campaign_id" value="'.$campaignId.'"><label class="visually-hidden" for="global-equipment-query-page">Buscar equipo</label><input class="form-control form-control-lg" id="global-equipment-query-page" name="q" value="'.e($q).'" placeholder="Ejemplo: PF3A1B2C, 000-12345 o hostname" autocomplete="off" autofocus><button class="btn btn-primary" type="submit">Buscar en SIVI</button></form><div class="search-help-row"><span>Atajo: <kbd>Ctrl</kbd> + <kbd>K</kbd></span><span>La búsqueda no modifica el inventario.</span></div></div>';
    if(mb_strlen($q)>=2){
        $equipment=$results['equipment'];$additional=$results['additional'];$total=count($equipment)+count($additional);
        $content.='<div class="guided-section-head"><div><h2>'.number_format($total,0,',','.').' resultado(s)</h2><p>Coincidencias encontradas para “'.e($q).'”.</p></div></div>';
        if($total===0){$content.=empty_state('No se encontraron coincidencias','Revise el serial o la placa física. Si el elemento realmente no existe, puede registrarlo como equipo adicional.');}
        if($equipment){$content.='<div class="search-result-grid">';foreach($equipment as $row){
            $action=campaign_equipment_exists($campaignId,(int)$row['id'])?route_url('equipo_validar',['id'=>(int)$row['id'],'campaign_id'=>$campaignId]):route_url('historial_equipo',['id'=>(int)$row['id']]);
            $content.='<article class="equipment-search-result"><div class="equipment-search-result-head"><span class="badge text-bg-primary">Inventario activo</span>'.status_badge((string)($row['inventory_status']??'activo')).'</div><h3>'.e($row['name']?:'Elemento sin hostname').'</h3><p>'.e(asset_category_label((string)$row['asset_category'])).' · '.e(trim((string)$row['manufacturer'].' '.(string)$row['model'])).'</p><dl><div><dt>Serial</dt><dd>'.e($row['serial_number']?:'Pendiente').'</dd></div><div><dt>Placa RNEC</dt><dd>'.e($row['placa_rnec']?:'Sin placa').'</dd></div><div><dt>Sede</dt><dd>'.e(($row['identificador']?:'SIN SEDE').' · '.($row['nombre_sede']?:'Pendiente')).'</dd></div><div><dt>Ubicación</dt><dd>'.e(trim((string)$row['municipio'].' / '.(string)$row['departamento'],' /')).'</dd></div></dl><a class="btn btn-sm btn-outline-primary" href="'.e($action).'">Abrir registro</a></article>';
        }$content.='</div>';}
        if($additional){$content.='<div class="guided-section-head mt-4"><div><h3>Equipos adicionales vigentes</h3><p>Coincidencias pendientes o aprobadas en campañas.</p></div></div><div class="search-result-grid">';foreach($additional as $row){
            $content.='<article class="equipment-search-result"><div class="equipment-search-result-head"><span class="badge text-bg-warning">Equipo adicional</span>'.status_badge((string)$row['review_status']).'</div><h3>'.e($row['name']?:'Elemento adicional').'</h3><p>'.e(asset_category_label((string)$row['asset_category'])).' · '.e((string)$row['campaign_name']).'</p><dl><div><dt>Serial</dt><dd>'.e($row['serial_number']?:'Pendiente').'</dd></div><div><dt>Placa RNEC</dt><dd>'.e($row['placa_rnec']?:'Sin placa').'</dd></div><div><dt>Sede</dt><dd>'.e($row['identificador'].' · '.$row['nombre_sede']).'</dd></div><div><dt>Ubicación</dt><dd>'.e($row['municipio'].' / '.$row['departamento']).'</dd></div></dl></article>';
        }$content.='</div>';}
    }
    render_page('Buscar equipo',$content,['subtitle'=>'Consulta unificada del inventario activo y los equipos adicionales.']);
}

function equipment_page(): void
{
    [$scopeWhere, $scopeParams] = Scope::sedeCondition('s');
    $campaignId = selected_campaign_id();
    $availableWhere = $scopeWhere;
    $availableParams = $scopeParams;
    if ($campaignId > 0) {
        $availableWhere .= ' AND EXISTS (SELECT 1 FROM campaign_sedes cs_scope WHERE cs_scope.campaign_id=? AND cs_scope.sede_id=s.id)';
        $availableParams[] = $campaignId;
    }
    $availableSedes = Database::fetchAll(
        "SELECT s.id,s.identificador,s.cod_dd,s.departamento,s.municipio,s.tipo_sede,s.nombre_sede FROM sedes s WHERE {$availableWhere} ORDER BY s.departamento,s.municipio,s.nombre_sede",
        $availableParams
    );

    $where = $scopeWhere;
    $params = $scopeParams;
    $q = trim((string)($_GET['q'] ?? ''));
    $department = trim((string)($_GET['department'] ?? ''));
    $municipality = trim((string)($_GET['municipality'] ?? ''));
    $municipalityName = $municipality;
    if (str_contains($municipality, '|')) {
        [$municipalityDepartment, $municipalityName] = explode('|', $municipality, 2);
        if ($department === '') $department = $municipalityDepartment;
    }
    $siteType = trim((string)($_GET['site_type'] ?? ''));
    $sedeId = (int)($_GET['sede_id'] ?? 0);
    if (Auth::is('registrador') && $campaignId > 0 && $sedeId < 1) {
        $sedeId = (int)(Auth::user()['sede_id'] ?? 0);
    }
    $unassigned = !empty($_GET['unassigned']) && Auth::is('admin_gi');

    if ($unassigned) {
        $where = 'e.current_sede_id IS NULL';
        $params = [];
        $department = '';
        $municipality = '';
        $municipalityName = '';
        $siteType = '';
        $sedeId = 0;
    } else {
        if ($campaignId > 0) {
            $where .= ' AND EXISTS (SELECT 1 FROM campaign_equipment ce_scope WHERE ce_scope.campaign_id=? AND ce_scope.equipment_id=e.id)';
            $params[] = $campaignId;
        }
        if ($department !== '') {
            $where .= ' AND s.cod_dd=?';
            $params[] = $department;
        }
        if ($municipalityName !== '') {
            $where .= ' AND s.municipio=?';
            $params[] = $municipalityName;
        }
        if ($siteType !== '') {
            $where .= ' AND s.tipo_sede=?';
            $params[] = $siteType;
        }
        if ($sedeId > 0) {
            if (!Scope::canAccessSede($sedeId)) {
                render_error('Acceso denegado', 'La sede no pertenece a su alcance.');
                return;
            }
            $where .= ' AND s.id=?';
            $params[] = $sedeId;
        }
    }

    if ($campaignId > 0 && $sedeId > 0 && campaign_accepts_responses($campaignId) && !campaign_site_profile_complete($campaignId,$sedeId)) {
        flash('warning','Antes de validar los equipos debe confirmar la información general, dirección y responsable de la sede.');
        redirect('campania_sede_contacto',['campaign_id'=>$campaignId,'sede_id'=>$sedeId]);
    }

    if ($q !== '') {
        $where .= ' AND (e.name LIKE ? OR e.serial_number LIKE ? OR e.placa_rnec LIKE ? OR e.placa_almacen LIKE ? OR e.alternate_user LIKE ? OR e.os_name LIKE ? OR e.os_version LIKE ? OR e.processor LIKE ? OR e.screen_size LIKE ? OR e.connection_type LIKE ? OR e.print_technology LIKE ?)';
        $like = "%{$q}%";
        array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like);
    }
    $category = trim((string)($_GET['category'] ?? ''));
    if ($category !== '') {
        $where .= ' AND e.asset_category=?';
        $params[] = $category;
    }
    $type = trim((string)($_GET['type'] ?? ''));
    if ($type !== '') {
        $where .= ' AND e.equipment_type=?';
        $params[] = $type;
    }
    $associationReview = trim((string)($_GET['association_review'] ?? ''));
    if ($associationReview === '1') $where .= ' AND e.association_review_required=1';
    if ($associationReview === '0') $where .= ' AND e.association_review_required=0';
    $validationFilter = trim((string)($_GET['validation'] ?? ''));
    if ($validationFilter === 'pending') $where .= " AND (ev.id IS NULL OR ev.validation_status='pendiente')";
    if ($validationFilter === 'completed') $where .= " AND ev.id IS NOT NULL AND ev.validation_status<>'pendiente'";
    if ($validationFilter === 'returned') $where .= " AND ev.review_status='devuelto'";
    $serialFilter = trim((string)($_GET['serial'] ?? ''));
    if ($serialFilter === 'pending') $where .= " AND COALESCE(NULLIF(TRIM(ev.serial_reported),''),NULLIF(TRIM(e.serial_number),'')) IS NULL";
    if ($serialFilter === 'confirmed') $where .= " AND COALESCE(NULLIF(TRIM(ev.serial_reported),''),NULLIF(TRIM(e.serial_number),'')) IS NOT NULL";
    if (($_GET['belongs'] ?? '') === 'no_pertenece') $where .= " AND ev.physical_condition='trasladado'";
    if (($_GET['placa'] ?? '') === 'pendiente') $where .= " AND COALESCE(NULLIF(ev.placa_reported,''),NULLIF(e.placa_rnec,'')) IS NULL";
    if (($_GET['warehouse'] ?? '') === 'ambigua') $where .= " AND e.warehouse_match_status='ambigua'";
    if (($_GET['warehouse'] ?? '') === 'sin_coincidencia') $where .= " AND e.warehouse_match_status IN ('no_encontrada','sin_serial')";

    $page = max(1, (int)($_GET['p'] ?? 1));
    $per = 35;
    $offset = ($page - 1) * $per;
    $allParams = array_merge([$campaignId > 0 ? $campaignId : 0], $params);
    $total = (int)(Database::fetchOne(
        "SELECT COUNT(*) total FROM equipment e LEFT JOIN sedes s ON s.id=e.current_sede_id LEFT JOIN equipment_validations ev ON ev.equipment_id=e.id AND ev.campaign_id=? WHERE e.active=1 AND {$where}",
        $allParams
    )['total'] ?? 0);
    $rows = Database::fetchAll(
        "SELECT e.*,s.identificador,s.nombre_sede,s.departamento,s.municipio,ev.validation_status,ev.physical_condition,ev.ownership_type validation_ownership_type,ev.belongs_status,ev.placa_reported,ev.placa_status,ev.review_status FROM equipment e LEFT JOIN sedes s ON s.id=e.current_sede_id LEFT JOIN equipment_validations ev ON ev.equipment_id=e.id AND ev.campaign_id=? WHERE e.active=1 AND {$where} ORDER BY s.departamento,s.municipio,e.asset_category,e.name LIMIT {$per} OFFSET {$offset}",
        $allParams
    );

    $types = Database::fetchAll('SELECT DISTINCT equipment_type FROM equipment WHERE NULLIF(equipment_type,"") IS NOT NULL ORDER BY equipment_type');
    $campaigns = array_values(array_filter(Database::fetchAll('SELECT id,name,status FROM campaigns ORDER BY id DESC'), static fn(array $campaign): bool => campaign_accessible_to_current_user((int)$campaign['id'])));
    $campaignChoices = ['' => 'Sin campaña seleccionada'];
    foreach ($campaigns as $campaign) $campaignChoices[$campaign['id']] = $campaign['name'] . ' (' . $campaign['status'] . ')';
    $categoryChoices = asset_category_choices(true, true);
    $typeChoices = ['' => 'Todos'];
    foreach ($types as $equipmentType) $typeChoices[$equipmentType['equipment_type']] = $equipmentType['equipment_type'];

    $siteContextHtml = '';
    if ($sedeId > 0) {
        $selectedSede = null;
        foreach ($availableSedes as $availableSede) {
            if ((int)($availableSede['id'] ?? 0) === $sedeId) {
                $selectedSede = $availableSede;
                break;
            }
        }
        if ($selectedSede) {
            $siteContextHtml = '<div class="card sede-fixed-summary"><div class="kicker">Sede en contexto</div><h3>'
                .e((string)$selectedSede['identificador'].' · '.(string)$selectedSede['nombre_sede'])
                .'</h3><p class="muted mb-0">'.e((string)$selectedSede['tipo_sede'].' · '.(string)$selectedSede['departamento'].' / '.(string)$selectedSede['municipio']).'</p></div>';
        }
        $filterFields = '<input type="hidden" name="department" value="'.e($department).'">'
            .'<input type="hidden" name="municipality" value="'.e($municipality).'">'
            .'<input type="hidden" name="site_type" value="'.e($siteType).'">'
            .'<input type="hidden" name="sede_id" value="'.$sedeId.'">';
    } else {
        $filterFields = territorial_filter_fields($availableSedes, $department, $municipality, $siteType, $sedeId);
    }
    $exportParams = [
        'campaign_id' => $campaignId,
        'department' => $department,
        'municipality' => $municipality,
        'site_type' => $siteType,
        'sede_id' => $sedeId,
        'q' => $q,
        'category' => $category,
        'type' => $type,
        'association_review' => $associationReview,
        'validation' => $validationFilter,
        'serial' => $serialFilter,
    ];
    $guidedPanel = '';
    if (Auth::is('registrador') && $campaignId > 0 && $sedeId > 0) {
        $guidedPanel = guided_site_work_panel($campaignId,$sedeId);
    } elseif (Auth::is('formador') && $campaignId > 0) {
        $guidedPanel = $sedeId > 0 ? guided_site_work_panel($campaignId,$sedeId) : guided_territory_panel($campaignId);
    }
    $content = $guidedPanel . $siteContextHtml . '<div class="card inventory-filter-card"><div class="guided-section-head"><div><h2>'.($sedeId>0?'Buscar dentro de esta sede':'Buscar y filtrar inventario').'</h2><p>'.($sedeId>0?'La sede ya está definida. Use únicamente los filtros del inventario que necesite.':'Use solo los filtros que necesite. SIVI conservará su contexto mientras trabaja.').'</p></div><a class="btn btn-sm btn-outline-primary" href="' . e(route_url('buscar_equipo',['campaign_id'=>$campaignId])) . '">Búsqueda global</a></div><div class="toolbar"><form class="filters" method="get"'.($sedeId>0?'':' data-territorial-filters').' data-persist-filters="equipos"><input type="hidden" name="page" value="equipos">'
        . field('campaign_id', 'Campaña', $campaignId, 'select', ['choices' => $campaignChoices])
        . $filterFields
        . field('q', 'Buscar', $q, 'text', ['placeholder' => 'Nombre, placa, serial, usuario, IP o característica'])
        . field('category', 'Categoría', $category, 'select', ['choices' => $categoryChoices])
        . field('type', 'Tipo GLPI', $type, 'select', ['choices' => $typeChoices])
        . field('association_review', 'Asociación territorial', $associationReview, 'select', ['choices' => ['' => 'Todas', '1' => 'Requiere revisión', '0' => 'Confirmada automáticamente']])
        . field('validation', 'Estado de validación', $validationFilter, 'select', ['choices' => ['' => 'Todos', 'pending' => 'Pendientes', 'completed' => 'Validados', 'returned' => 'Devueltos para corrección']])
        . field('serial', 'Estado del serial', $serialFilter, 'select', ['choices' => ['' => 'Todos', 'pending' => 'Serial pendiente', 'confirmed' => 'Serial registrado']])
        . '<button class="btn btn-secondary">Aplicar filtros</button><a class="btn btn-secondary" href="' . e(route_url('equipos', ['campaign_id' => $campaignId])) . '">Limpiar</a></form>'
        . '<div class="form-actions"><a class="btn btn-secondary" href="' . e(route_url('exportar', array_merge($exportParams, ['format' => 'xlsx']))) . '">Exportar Excel</a>'
        . '<a class="btn btn-secondary" href="' . e(route_url('exportar', array_merge($exportParams, ['format' => 'csv']))) . '">Exportar CSV</a></div></div>';

    if (!$rows) {
        $content .= empty_state('No hay activos para mostrar', 'Importe los reportes GLPI o de Almacén, o ajuste los filtros seleccionados.');
    } else {
        $content .= '<div class="table-wrap"><table><thead><tr><th>Activo</th><th>Placa RNEC</th><th>Serial</th><th>Categoría / Modelo</th><th>Características</th><th>Usuario / IP</th><th>Sede</th><th>Validación</th><th>Estado validado</th><th></th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $effectivePlate = $row['placa_reported'] ?: $row['placa_rnec'];
            $effectiveOwnership = $row['validation_ownership_type'] ?: $row['ownership_type'];
            $plateText = $effectivePlate ?: ($effectiveOwnership !== 'propio' && $effectiveOwnership !== 'desconocido' ? 'No requerida' : 'Placa pendiente');
            $plateClass = $effectivePlate ? '' : ($plateText === 'No requerida' ? ' badge badge-neutral' : ' badge badge-warning');
            $categoryLabel = asset_category_label((string)$row['asset_category']);
            if ($row['asset_category'] === 'monitor') {
                $features = 'Tamaño: ' . e($row['screen_size'] ?: 'No reportado') . '<br><span class="muted">Conexión: ' . e($row['connection_type'] ?: 'No reportada') . '</span>';
            } elseif ($row['asset_category'] === 'impresora') {
                $features = 'Tecnología: ' . e($row['print_technology'] ?: 'No reportada') . '<br><span class="muted">Conexión: ' . e($row['connection_type'] ?: 'No reportada') . '</span>';
            } elseif ($row['asset_category'] === 'escaner') {
                $features = 'Modelo: ' . e($row['model'] ?: 'No reportado') . '<br><span class="muted">Conexión: ' . e($row['connection_type'] ?: 'No reportada') . '</span>';
            } elseif ($row['asset_category'] === 'ups') {
                $features = 'Modelo / referencia: ' . e($row['model'] ?: 'No reportado') . '<br><span class="muted">Estado: ' . e($row['source_state'] ?: 'No reportado') . '</span>';
            } elseif (is_computer_category((string)$row['asset_category'])) {
                $features = e($row['os_name'] ?: 'Sistema operativo no reportado') . ' ' . e($row['os_version']) . '<br><span class="muted">' . e($row['processor'] ?: 'Procesador no reportado') . ' · RAM ' . e($row['memory'] ?: 'no reportada') . '</span>';
            } else {
                $features = e($row['manufacturer'] ?: 'Fabricante no reportado') . '<br><span class="muted">' . e($row['model'] ?: 'Modelo no reportado') . '</span>';
            }
            $associationText = association_method_label($row['association_method'] ?? null);
            $associationMeta = '<br><span class="muted">Asociación: ' . e($associationText) . '</span><br>'
                . association_confidence_badge($row['association_confidence'] ?? null)
                . (!empty($row['association_review_required']) ? ' <span class="badge badge-warning">Requiere revisión</span>' : '');
            if (Auth::is('admin_gi') && (empty($row['current_sede_id']) || !empty($row['association_review_required']))) {
                $actionButton = '<a class="btn btn-sm btn-secondary" href="' . e(route_url('equipo_asignar', ['id' => $row['id']])) . '">'
                    . (empty($row['current_sede_id']) ? 'Asignar sede' : 'Revisar sede') . '</a>';
            } else {
                $actionLabel = !empty($row['validation_status']) && $row['validation_status'] !== 'pendiente' ? 'Revisar validación' : 'Validar equipo';
                $actionButton = '<a class="btn btn-sm" href="' . e(route_url('equipo_validar', ['id' => $row['id'], 'campaign_id' => $campaignId])) . '">' . e($actionLabel) . '</a>';
            }
            $content .= '<tr>'
                . '<td data-label="Activo"><strong>' . e($row['name'] ?: 'Sin nombre') . '</strong><br><span class="muted">' . e($row['equipment_type']) . '</span></td>'
                . '<td data-label="Placa RNEC"><strong class="' . e(trim($plateClass)) . '">' . e($plateText) . '</strong><br><span class="muted">Almacén: ' . e($row['placa_almacen'] ?: 'Sin coincidencia') . '</span></td>'
                . '<td data-label="Serial">' . e($row['serial_number'] ?: 'Sin serial') . '</td>'
                . '<td data-label="Categoría / Modelo"><strong>' . e($categoryLabel) . '</strong><br><span class="muted">' . e(trim($row['manufacturer'] . ' ' . $row['model'])) . '</span></td>'
                . '<td data-label="Características">' . $features . '</td>'
                . '<td data-label="Usuario / IP">' . e($row['alternate_user'] ?: 'Sin usuario') . '<br><span class="muted">IP: ' . e($row['ip_address'] ?: 'No reportada') . '</span></td>'
                . '<td data-label="Sede">' . e(($row['identificador'] ?: 'SIN SEDE') . ' · ' . $row['nombre_sede']) . '<br><span class="muted">' . e(trim($row['departamento'] . ' ' . $row['municipio'])) . '</span>' . $associationMeta . '</td>'
                . '<td data-label="Validación">' . status_badge($row['validation_status'] ?: 'pendiente') . '</td>'
                . '<td data-label="Estado">' . status_badge($row['physical_condition'] ?: $row['inventory_status'] ?: 'pendiente') . '</td>'
                . '<td data-label="Acción">' . $actionButton . '</td></tr>';
        }
        $content .= '</tbody></table></div>' . pagination($total, $page, $per, 'equipos', [
            'q' => $q,
            'category' => $category,
            'type' => $type,
            'association_review' => $associationReview,
            'validation' => $validationFilter,
            'serial' => $serialFilter,
            'campaign_id' => $campaignId,
            'department' => $department,
            'municipality' => $municipality,
            'sede_id' => $sedeId,
        ]);
    }
    $content .= '</div>';

    if (Auth::is('registrador') && $campaignId > 0) {
        $content .= site_closure_panel($campaignId,(int)Auth::user()['sede_id']);
    }
    $pageTitle=(Auth::is('registrador')||Auth::is('formador'))?'Validar inventario':'Equipos';
    render_page($pageTitle, $content, ['subtitle' => "{$total} activos encontrados con los filtros aplicados."]);
}

function equipment_assign_page(): void
{
    Auth::requireRole('admin_gi');
    $id=(int)($_GET['id'] ?? $_POST['id'] ?? 0);
    $equipment=Database::fetchOne('SELECT * FROM equipment WHERE id=?',[$id]);
    if(!$equipment){render_error('Equipo no encontrado','El registro solicitado no existe.');return;}
    if(request_method('POST')){
        verify_csrf();
        $sedeId=(int)($_POST['sede_id'] ?? 0);
        $sede=Database::fetchOne('SELECT * FROM sedes WHERE id=?',[$sedeId]);
        if(!$sede){flash('danger','Seleccione una sede válida.');redirect('equipo_asignar',['id'=>$id]);}
        Database::execute("UPDATE equipment SET original_sede_id=COALESCE(original_sede_id,?),current_sede_id=?,association_method='manual',association_confidence='alta',association_evidence=?,association_review_required=0 WHERE id=?",[$sedeId,$sedeId,json_encode(['rule'=>'asignacion_manual','assigned_by'=>(int)Auth::id()],JSON_UNESCAPED_UNICODE),$id]);
        audit('manual_assign_equipment','equipment',$id,$equipment,['current_sede_id'=>$sedeId]);
        flash('success','El equipo fue asignado a '.$sede['identificador'].' · '.$sede['nombre_sede'].'.');
        redirect('equipos',['association_review'=>1]);
    }
    $sedes=Database::fetchAll('SELECT id,identificador,departamento,municipio,nombre_sede FROM sedes ORDER BY departamento,municipio,nombre_sede');
    $choices=[''=>'Seleccione una sede'];foreach($sedes as $s)$choices[$s['id']]=$s['identificador'].' · '.$s['departamento'].' / '.$s['municipio'].' · '.$s['nombre_sede'];
    $evidence=json_decode((string)($equipment['association_evidence'] ?? ''),true);if(!is_array($evidence))$evidence=[];
    $reason=(string)($evidence['motivo_revision'] ?? 'Revise el hostname, usuario y Localización GLPI antes de confirmar la sede.');
    $content='<div class="card"><div class="kicker">Revisión territorial</div><h2>'.e($equipment['name'] ?: 'Equipo sin hostname').'</h2><p><strong>Serial:</strong> '.e($equipment['serial_number']).'<br><strong>Usuario GLPI:</strong> '.e($equipment['alternate_user'] ?: 'No reportado').'<br><strong>Localización GLPI:</strong> '.e($equipment['source_location'] ?: 'No reportada').'<br><strong>Método actual:</strong> '.e(association_method_label($equipment['association_method'] ?? null)).' · '.e((string)($equipment['association_confidence'] ?? 'sin_asignar')).'</p><div class="alert alert-warning">'.e($reason).'</div></div><div class="card"><form method="post">'.csrf_field().'<input type="hidden" name="id" value="'.$id.'">'.field('sede_id','Sede correcta',(string)($equipment['current_sede_id'] ?? ''),'select',['choices'=>$choices,'required'=>true]).'<div class="form-actions"><button class="btn">Confirmar sede</button><a class="btn btn-secondary" href="'.e(route_url('equipos',['association_review'=>1])).'">Cancelar</a></div></form></div>';
    render_page('Revisar sede del equipo',$content,['subtitle'=>'Confirme o corrija las asociaciones automáticas de baja confianza.']);
}

function equipment_validate_page(): void
{
    $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    $campaignId = (int)($_GET['campaign_id'] ?? $_POST['campaign_id'] ?? selected_campaign_id());
    if (!$id || !Scope::canAccessEquipment($id)) {
        render_error('Equipo no disponible', 'No tiene acceso a este equipo.');
        return;
    }
    if (!$campaignId) {
        render_error('Campaña requerida', 'Debe crear o seleccionar una campaña.');
        return;
    }

    $equipment = Database::fetchOne('SELECT e.*,s.identificador,s.nombre_sede,s.tipo_sede,s.cod_dd,s.departamento,s.municipio FROM equipment e LEFT JOIN sedes s ON s.id=e.current_sede_id WHERE e.id=?', [$id]);
    if (!$equipment || !campaign_accessible_to_current_user($campaignId) || !campaign_equipment_exists($campaignId,$id)) {
        render_error('Equipo fuera de la campaña','El equipo no pertenece a los departamentos seleccionados para esta campaña.');
        return;
    }
    if (campaign_accepts_responses($campaignId) && !campaign_site_profile_complete($campaignId,(int)$equipment['current_sede_id'])) {
        flash('warning','Confirme primero la información general, dirección y responsable de la sede.');
        redirect('campania_sede_contacto',['campaign_id'=>$campaignId,'sede_id'=>(int)$equipment['current_sede_id'],'equipment_id'=>$id]);
    }
    $campaign = Database::fetchOne('SELECT * FROM campaigns WHERE id=?',[$campaignId]);
    $validationDraftsEnabled = AppSettings::validationDraftsEnabled();
    $validationImagesEnabled = AppSettings::validationImagesEnabled();
    $campaignRequiresEvidence = $validationImagesEnabled && (int)($campaign['requires_evidence']??0)===1;
    $plateTotal=placa_rnec_total_characters();
    $plateExample=PlatePolicy::example($plateTotal);
    $platePattern=placa_rnec_pattern();
    $validation = Database::fetchOne('SELECT * FROM equipment_validations WHERE campaign_id=? AND equipment_id=? AND reported_by_sede_id=?', [$campaignId, $id, $equipment['current_sede_id']]);
    $validationNoteParts = validation_notes_parse((string)($validation['notes'] ?? ''));

    if (request_method('POST')) {
        if(!campaign_accepts_responses($campaignId)){flash('danger','La campaña no está activa o su plazo de recepción finalizó.');redirect('equipos',['campaign_id'=>$campaignId,'sede_id'=>$equipment['current_sede_id']]);}
        verify_csrf();
        $physicalCondition = (string)($_POST['physical_condition'] ?? 'pendiente');
        $belongs = (string)($_POST['belongs_status'] ?? 'pertenece');
        $belongsReason = trim((string)($_POST['belongs_reason'] ?? ''));
        $belongsReasonOther = trim((string)($_POST['belongs_reason_other'] ?? ''));
        $ownershipType = (string)($_POST['ownership_type'] ?? 'desconocido');
        $serialReported = trim((string)($_POST['serial_reported'] ?? ''));
        $placaRaw = trim((string)($_POST['placa_reported'] ?? ''));
        $plateUnavailable = (string)($_POST['placa_no_visible'] ?? '') === '1';
        $plateUnavailableReason = trim((string)($_POST['plate_unavailable_reason'] ?? ''));
        $placa = null;
        $destination = (int)($_POST['destination_sede_id'] ?? 0);
        $destination = $destination > 0 ? $destination : null;
        $disposalDate = trim((string)($_POST['disposal_date'] ?? ''));
        $disposalDocument = trim((string)($_POST['disposal_document'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $validationConfirmation = (string)($_POST['validation_confirmation'] ?? '');

        if ($validationConfirmation !== '1') {
            flash('danger', 'Confirme que realizó la verificación física del equipo antes de guardar.');
            redirect('equipo_validar', ['id'=>$id,'campaign_id'=>$campaignId]);
        }

        $allowedStatuses = ['activo','inactivo','para_baja','dado_baja','en_almacen','en_mantenimiento','trasladado'];
        $allowedOwnership = ['propio','comodato','donado_sin_legalizar'];
        $allowedBelongs = ['pertenece','no_pertenece'];
        $allowedBelongsReasons = ['trasladado','asignacion_incorrecta','prestamo','reparacion','baja','no_localizado','otro'];
        if (!in_array($belongs, $allowedBelongs, true)) {
            flash('danger', 'Indique si el equipo pertenece o no a esta oficina.');
            redirect('equipo_validar', ['id'=>$id,'campaign_id'=>$campaignId]);
        }
        if ($belongs === 'no_pertenece' && !in_array($belongsReason, $allowedBelongsReasons, true)) {
            flash('danger', 'Seleccione el motivo por el cual el equipo no pertenece a esta oficina.');
            redirect('equipo_validar', ['id'=>$id,'campaign_id'=>$campaignId]);
        }
        if ($belongs === 'pertenece') {
            $belongsReason = '';
            $belongsReasonOther = '';
        }
        if ($belongs === 'no_pertenece' && $belongsReason === 'otro') {
            if (mb_strlen($belongsReasonOther) < 5) {
                flash('danger', 'Explique el otro motivo por el cual el equipo no pertenece a esta sede.');
                redirect('equipo_validar', ['id'=>$id,'campaign_id'=>$campaignId]);
            }
        } else {
            $belongsReasonOther = '';
        }
        if ($belongs === 'no_pertenece' && $belongsReason === 'trasladado') $physicalCondition = 'trasladado';
        if ($belongs === 'no_pertenece' && $belongsReason === 'reparacion') $physicalCondition = 'en_mantenimiento';
        if (!in_array($physicalCondition, $allowedStatuses, true)) {
            flash('danger', 'Seleccione un estado válido para el equipo.');
            redirect('equipo_validar', ['id'=>$id,'campaign_id'=>$campaignId]);
        }
        if (!in_array($ownershipType, $allowedOwnership, true)) {
            flash('danger', 'Seleccione si el equipo es propio, está en comodato o fue donado sin legalizar.');
            redirect('equipo_validar', ['id'=>$id,'campaign_id'=>$campaignId]);
        }
        if ($serialReported === '') {
            flash('danger', 'El Número de serie verificado es obligatorio para todos los equipos.');
            redirect('equipo_validar', ['id'=>$id,'campaign_id'=>$campaignId]);
        }
        $serialMatches = SerialIntegrity::activeMatches($serialReported, $id);
        if ($serialMatches) {
            $match=$serialMatches[0];
            $location=trim((string)($match['identificador']??'').' · '.(string)($match['nombre_sede']??''),' ·');
            $registered=trim((string)($match['name']??''))?:asset_category_label((string)($match['asset_category']??'otro'));
            flash('danger', 'No se guardó la validación: el serial ' . $serialReported . ' ya está registrado en ' . $registered . ($location!==''?' de '.$location:'') . '. Revise físicamente la etiqueta o abra el equipo existente.');
            redirect('equipo_validar', ['id'=>$id,'campaign_id'=>$campaignId]);
        }
        if ($ownershipType !== 'propio') {
            $plateUnavailable = false;
            $plateUnavailableReason = '';
        }

        if ($ownershipType === 'propio' && $placaRaw === '' && $plateUnavailable) {
            if (mb_strlen($plateUnavailableReason) < 10) {
                flash('danger', 'Explique por qué no fue posible visualizar físicamente la Placa RNEC.');
                redirect('equipo_validar', ['id'=>$id,'campaign_id'=>$campaignId]);
            }
            $placa = null;
        } else {
            $plateUnavailable = false;
            $plateUnavailableReason = '';
            $plateValidation = PlatePolicy::validate($placaRaw, $plateTotal, $ownershipType === 'propio');
            if (!$plateValidation['ok']) {
                flash('danger', (string)$plateValidation['message']);
                redirect('equipo_validar', ['id'=>$id,'campaign_id'=>$campaignId]);
            }
            $placa = $plateValidation['value'] !== '' ? (string)$plateValidation['value'] : null;
        }
        if ($placa !== null) {
            $plateMatches=SerialIntegrity::activePlateMatches($placa,$id);
            if($plateMatches){
                $match=$plateMatches[0];
                $location=trim((string)($match['identificador']??'').' · '.(string)($match['nombre_sede']??''),' ·');
                $registered=trim((string)($match['name']??''))?:asset_category_label((string)($match['asset_category']??'otro'));
                flash('danger','No se guardó la validación: la Placa RNEC '.$placa.' ya está registrada en '.$registered.($location!==''?' de '.$location:'').'. Revise la placa física o abra el equipo existente.');
                redirect('equipo_validar',['id'=>$id,'campaign_id'=>$campaignId]);
            }
        }
        if ($physicalCondition === 'dado_baja') {
            if ($disposalDate === '' || $disposalDocument === '') {
                flash('danger', 'Para un equipo dado de baja debe registrar la fecha y la resolución o acta de baja.');
                redirect('equipo_validar', ['id'=>$id,'campaign_id'=>$campaignId]);
            }
            $date = DateTime::createFromFormat('Y-m-d', $disposalDate);
            if (!$date || $date->format('Y-m-d') !== $disposalDate) {
                flash('danger', 'La fecha de baja no es válida.');
                redirect('equipo_validar', ['id'=>$id,'campaign_id'=>$campaignId]);
            }
        } else {
            $disposalDate = '';
            $disposalDocument = '';
        }
        if ($physicalCondition === 'trasladado') {
            if ($belongs !== 'no_pertenece') {
                flash('danger', 'Un equipo trasladado debe marcarse como no perteneciente a esta oficina.');
                redirect('equipo_validar', ['id'=>$id,'campaign_id'=>$campaignId]);
            }
            if ($destination === null) {
                flash('danger', 'Seleccione Departamento, Municipio, Tipo de sede y Sede a la que fue trasladado el equipo.');
                redirect('equipo_validar', ['id'=>$id,'campaign_id'=>$campaignId]);
            }
            if ($destination === (int)$equipment['current_sede_id']) {
                flash('danger', 'La sede destino debe ser diferente de la oficina que está realizando la validación.');
                redirect('equipo_validar', ['id'=>$id,'campaign_id'=>$campaignId]);
            }
            if (!Database::fetchOne('SELECT id FROM sedes WHERE id=?',[$destination])) {
                flash('danger', 'La sede destino seleccionada no existe.');
                redirect('equipo_validar', ['id'=>$id,'campaign_id'=>$campaignId]);
            }
        } else {
            $destination = null;
        }

        $originalSerial = trim((string)($equipment['serial_number'] ?? ''));
        $serialStatus = ($originalSerial !== '' && mb_strtoupper($originalSerial) === mb_strtoupper($serialReported)) ? 'confirmado' : 'corregido';
        $originalPlate = normalize_placa_rnec((string)($equipment['placa_rnec'] ?? ''));
        if ($placa === null) {
            $placaStatus = 'sin_placa';
        } else {
            $placaStatus = ($originalPlate !== null && $originalPlate === $placa) ? 'confirmada' : 'corregida';
        }
        $validationStatusMap = [
            'activo'=>'confirmado', 'inactivo'=>'confirmado', 'para_baja'=>'pendiente_baja',
            'dado_baja'=>'dado_baja', 'en_almacen'=>'almacenado',
            'en_mantenimiento'=>'reparacion', 'trasladado'=>'trasladado',
        ];
        $status = $validationStatusMap[$physicalCondition];
        if ($belongs === 'no_pertenece') {
            $status = match ($belongsReason) {
                'trasladado' => 'trasladado',
                'reparacion' => 'reparacion',
                'baja' => 'pendiente_baja',
                'no_localizado' => 'no_encontrado',
                default => 'con_correccion',
            };
        }

        $notes = validation_notes_compose(
            $notes,
            $belongsReasonOther,
            $plateUnavailableReason
        );

        if($campaignRequiresEvidence){
            $hasNewGeneral=!empty($_FILES['evidence_general']['name'])&&($_FILES['evidence_general']['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_OK;
            $hasExistingEvidence=!empty($validation['id'])&&(bool)Database::fetchOne("SELECT 1 ok FROM evidence_files WHERE validation_id=? AND evidence_type='general' LIMIT 1",[(int)$validation['id']]);
            if(!$hasNewGeneral&&!$hasExistingEvidence){flash('danger','Esta campaña exige una fotografía general del equipo.');redirect('equipo_validar',['id'=>$id,'campaign_id'=>$campaignId]);}
        }
        $evidence = $validation['evidence_path'] ?? null;
        Database::execute(
            'INSERT INTO equipment_validations (campaign_id,equipment_id,reported_by_sede_id,validation_status,physical_condition,ownership_type,belongs_status,belongs_reason,placa_original,placa_reported,serial_original,serial_reported,serial_status,placa_status,destination_sede_id,destination_text,disposal_date,disposal_document,notes,evidence_path,submitted_by,submitted_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE validation_status=VALUES(validation_status),physical_condition=VALUES(physical_condition),ownership_type=VALUES(ownership_type),belongs_status=VALUES(belongs_status),belongs_reason=VALUES(belongs_reason),placa_reported=VALUES(placa_reported),serial_reported=VALUES(serial_reported),serial_status=VALUES(serial_status),placa_status=VALUES(placa_status),destination_sede_id=VALUES(destination_sede_id),destination_text=NULL,disposal_date=VALUES(disposal_date),disposal_document=VALUES(disposal_document),notes=VALUES(notes),evidence_path=VALUES(evidence_path),submitted_by=VALUES(submitted_by),submitted_at=NOW(),review_status="pendiente"',
            [
                $campaignId,$id,$equipment['current_sede_id'],$status,$physicalCondition,$ownershipType,
                $belongs,$belongsReason!==''?$belongsReason:null,$equipment['placa_rnec'] ?: null,$placa,
                $equipment['serial_number'] ?: null,$serialReported,$serialStatus,$placaStatus,
                $destination,null,$disposalDate !== '' ? $disposalDate : null,
                $disposalDocument !== '' ? $disposalDocument : null,$notes,$evidence,Auth::id(),
            ]
        );
        $savedValidation = Database::fetchOne('SELECT id FROM equipment_validations WHERE campaign_id=? AND equipment_id=? AND reported_by_sede_id=?', [$campaignId,$id,$equipment['current_sede_id']]);
        if ($savedValidation && $validationImagesEnabled) {
            process_evidence_uploads((int)$savedValidation['id']);
        }
        // Al confirmar una validación se eliminan todos los borradores residuales del mismo equipo y sede.
        Database::execute('DELETE FROM validation_drafts WHERE campaign_id=? AND equipment_id=? AND sede_id=?',[$campaignId,$id,(int)$equipment['current_sede_id']]);
        Database::execute("INSERT INTO campaign_sedes (campaign_id,sede_id,status) VALUES (?,?,'en_diligenciamiento') ON DUPLICATE KEY UPDATE status=IF(status='aprobado',status,'en_diligenciamiento')", [$campaignId,$equipment['current_sede_id']]);
        audit('validate_equipment','equipment',$id,$validation,[
            'validation_status'=>$status,'physical_condition'=>$physicalCondition,'ownership_type'=>$ownershipType,
            'belongs_status'=>$belongs,'placa_reported'=>$placa,'placa_status'=>$placaStatus,
            'serial_reported'=>$serialReported,'serial_status'=>$serialStatus,
            'destination_sede_id'=>$destination,'disposal_date'=>$disposalDate ?: null,
            'disposal_document'=>$disposalDocument ?: null,
            'belongs_reason_other'=>$belongsReasonOther ?: null,
            'placa_unavailable_reason'=>$plateUnavailableReason ?: null,
            'notes'=>validation_notes_for_display($notes),
        ]);
        $nextPending = campaign_next_pending_equipment(
            $campaignId,
            (int)$equipment['current_sede_id'],
            ['id'=>$id,'name'=>(string)($equipment['name'] ?? ''),'asset_category'=>(string)($equipment['asset_category'] ?? '')]
        );
        if ($nextPending) {
            flash('success','La validación fue guardada. Continúe con el siguiente equipo pendiente.');
            redirect('equipo_validar',['id'=>(int)$nextPending['id'],'campaign_id'=>$campaignId]);
        }
        flash('success','La validación fue guardada y no quedan equipos pendientes. Revise el resumen para finalizar la sede.');
        redirect('equipos',['campaign_id'=>$campaignId,'sede_id'=>(int)$equipment['current_sede_id']]);
    }

    if (($_GET['action'] ?? '') === 'approve' && !Auth::is('registrador')) {
        verify_get_csrf();
        if (!$validation) {
            flash('danger','No existe una validación para aprobar.');
            redirect('equipo_validar',['id'=>$id,'campaign_id'=>$campaignId]);
        }
        $newSede = (int)$equipment['current_sede_id'];
        if (($validation['physical_condition'] ?? '') === 'trasladado' && !empty($validation['destination_sede_id'])) {
            if (Auth::is('formador') && !Scope::canAccessSede((int)$validation['destination_sede_id'])) {
                flash('danger','El Formador solo puede aplicar traslados dentro de sus departamentos.');
                redirect('equipo_validar',['id'=>$id,'campaign_id'=>$campaignId]);
            }
            $newSede = (int)$validation['destination_sede_id'];
        }
        $approvedPlate = normalize_placa_rnec((string)($validation['placa_reported'] ?? ''));
        $approvedNoteParts = validation_notes_parse((string)($validation['notes'] ?? ''));
        if (
            ($validation['ownership_type'] ?? 'desconocido') === 'propio'
            && $approvedPlate === null
            && trim((string)$approvedNoteParts['placa_unavailable_reason']) === ''
        ) {
            flash('danger','Un equipo propio debe tener una Placa RNEC válida o una justificación de por qué no fue posible verla físicamente.');
            redirect('equipo_validar',['id'=>$id,'campaign_id'=>$campaignId]);
        }
        $approvedSerial = trim((string)($validation['serial_reported'] ?? ''));
        if ($approvedSerial === '') {
            flash('danger','El Número de serie verificado es obligatorio antes de aprobar la validación.');
            redirect('equipo_validar',['id'=>$id,'campaign_id'=>$campaignId]);
        }
        if (SerialIntegrity::activeMatches($approvedSerial, $id)) {
            flash('danger','El serial verificado ya está registrado en otro elemento activo. No se puede aprobar hasta corregirlo.');
            redirect('equipo_validar',['id'=>$id,'campaign_id'=>$campaignId]);
        }
        $inventoryMap = [
            'activo'=>'activo','inactivo'=>'inactivo','para_baja'=>'para_baja','dado_baja'=>'dado_baja',
            'en_almacen'=>'en_almacen','en_mantenimiento'=>'en_mantenimiento','trasladado'=>'trasladado',
        ];
        $inventoryStatus = $inventoryMap[$validation['physical_condition']] ?? 'desconocido';
        Database::execute('UPDATE equipment_validations SET review_status=?,reviewed_by=?,reviewed_at=NOW() WHERE id=?',['aprobada',Auth::id(),$validation['id']]);
        Database::execute('UPDATE equipment SET ownership_type=?,inventory_status=?,placa_rnec=?,serial_number=?,serial_review_required=0,serial_review_reason=NULL,serial_verified_at=NOW(),serial_verified_by=?,current_sede_id=? WHERE id=?',[
            $validation['ownership_type'],$inventoryStatus,$approvedPlate,$approvedSerial,Auth::id(),$newSede,$id,
        ]);
        if ($newSede !== (int)$equipment['current_sede_id']) {
            Database::execute('INSERT INTO equipment_history(equipment_id,campaign_id,event_type,origin_sede_id,destination_sede_id,description,old_values,new_values,changed_by) VALUES(?,?,?,?,?,?,?,?,?)',[
                $id,$campaignId,'traslado_validado',(int)$equipment['current_sede_id'],$newSede,'Traslado reportado durante la validación del inventario',
                json_encode(['sede_id'=>(int)$equipment['current_sede_id']],JSON_UNESCAPED_UNICODE),
                json_encode(['sede_id'=>$newSede],JSON_UNESCAPED_UNICODE),Auth::id(),
            ]);
        }
        if (($validation['physical_condition'] ?? '') === 'dado_baja') {
            Database::execute('INSERT INTO equipment_history(equipment_id,campaign_id,event_type,origin_sede_id,description,old_values,new_values,changed_by) VALUES(?,?,?,?,?,?,?,?)',[
                $id,$campaignId,'baja_validada',(int)$equipment['current_sede_id'],
                'Baja registrada: '.($validation['disposal_document'] ?? ''),
                json_encode(['inventory_status'=>$equipment['inventory_status'] ?? null],JSON_UNESCAPED_UNICODE),
                json_encode(['inventory_status'=>'dado_baja','disposal_date'=>$validation['disposal_date'] ?? null,'document'=>$validation['disposal_document'] ?? null],JSON_UNESCAPED_UNICODE),Auth::id(),
            ]);
        }
        audit('approve_equipment_validation','equipment',$id,$equipment,[
            'sede_id'=>$newSede,'ownership_type'=>$validation['ownership_type'],'inventory_status'=>$inventoryStatus,
            'placa'=>$approvedPlate,'serial'=>$approvedSerial,'disposal_date'=>$validation['disposal_date'] ?? null,
            'disposal_document'=>$validation['disposal_document'] ?? null,
        ]);
        flash('success','La validación fue aprobada y aplicada al inventario consolidado.');
        redirect('equipo_validar',['id'=>$id,'campaign_id'=>$campaignId]);
    }

    $existingPlateNormalized = normalize_placa_rnec((string)($equipment['placa_rnec'] ?? ''));
    $defaultStatus = in_array((string)($equipment['inventory_status'] ?? ''),['activo','inactivo','para_baja','dado_baja','en_almacen','en_mantenimiento','trasladado'],true)
        ? (string)$equipment['inventory_status'] : 'pendiente';
    $validation = $validation ?: [
        'validation_status'=>'pendiente','physical_condition'=>$defaultStatus,
        'ownership_type'=>(string)($equipment['ownership_type'] ?? 'desconocido'),
        'belongs_status'=>'desconocido','placa_reported'=>$existingPlateNormalized ?: '',
        'placa_status'=>$existingPlateNormalized ? 'confirmada' : 'pendiente',
        'serial_reported'=>(string)($equipment['serial_number'] ?? ''),
        'serial_status'=>!empty($equipment['serial_number']) ? 'confirmado' : 'pendiente',
        'belongs_reason'=>'','destination_sede_id'=>'','destination_text'=>'',
        'disposal_date'=>'','disposal_document'=>'','notes'=>'','review_status'=>'pendiente',
    ];
    $sequenceRows = Database::fetchAll("SELECT e.id,e.name,e.asset_category,CASE WHEN ev.validation_status IS NULL OR ev.validation_status='pendiente' THEN 0 ELSE 1 END validated FROM campaign_equipment ce JOIN equipment e ON e.id=ce.equipment_id LEFT JOIN equipment_validations ev ON ev.equipment_id=e.id AND ev.campaign_id=ce.campaign_id AND ev.reported_by_sede_id=ce.sede_id WHERE ce.campaign_id=? AND ce.sede_id=? ORDER BY e.asset_category,e.name,e.id",[$campaignId,(int)$equipment['current_sede_id']]);
    $sequenceIndex = 0;
    foreach ($sequenceRows as $index=>$sequenceRow) { if ((int)$sequenceRow['id'] === $id) { $sequenceIndex = $index; break; } }
    $previousEquipment = $sequenceIndex > 0 ? $sequenceRows[$sequenceIndex-1] : null;
    $nextEquipment = $sequenceIndex < count($sequenceRows)-1 ? $sequenceRows[$sequenceIndex+1] : null;
    $sequenceValidated = count(array_filter($sequenceRows,static fn(array $row): bool => (int)$row['validated'] === 1));
    $sequenceTotal = count($sequenceRows);
    $sequencePercent = $sequenceTotal > 0 ? (int)round(($sequenceValidated/$sequenceTotal)*100) : 0;
    $destinationRows = Database::fetchAll('SELECT id,identificador,tipo_sede,cod_dd,departamento,municipio,nombre_sede FROM sedes ORDER BY departamento,municipio,tipo_sede,nombre_sede');

    $assetCategory = (string)($equipment['asset_category'] ?? 'cpu');
    $assetCategoryLabel = asset_category_label($assetCategory);
    $systemName = trim((string)$equipment['os_name']);
    $systemVersion = trim((string)$equipment['os_version']);
    $architecture = trim((string)$equipment['architecture']);
    $processor = trim((string)$equipment['processor']);
    $memory = trim((string)$equipment['memory']);
    $confirmedPlateRaw = trim((string)$equipment['placa_rnec']);
    $confirmedPlate = normalize_placa_rnec($confirmedPlateRaw) ?? '';
    $confirmedPlateInvalid = $confirmedPlateRaw !== '' && $confirmedPlate === '';
    $suggestedPlate = normalize_placa_rnec((string)$equipment['placa_almacen']) ?? '';

    $previousUrl = $previousEquipment ? route_url('equipo_validar',['id'=>(int)$previousEquipment['id'],'campaign_id'=>$campaignId]) : '';
    $nextUrl = $nextEquipment ? route_url('equipo_validar',['id'=>(int)$nextEquipment['id'],'campaign_id'=>$campaignId]) : '';
    $content = '<div class="equipment-sequence-bar"><a class="btn btn-secondary" href="' . e(route_url('equipos',['campaign_id'=>$campaignId,'sede_id'=>$equipment['current_sede_id']])) . '">← Volver al inventario</a><div class="equipment-sequence-progress"><strong>Equipo ' . e($sequenceIndex+1) . ' de ' . e($sequenceTotal) . '</strong><span>' . e($sequenceValidated) . ' validados · ' . e($sequencePercent) . '%</span><div class="progress"><span class="' . progress_width_class($sequencePercent) . '"></span></div></div><div class="equipment-sequence-actions">' . ($previousUrl !== '' ? '<a class="btn btn-secondary" href="' . e($previousUrl) . '">← Anterior</a>' : '<span class="btn btn-secondary disabled" aria-disabled="true">← Anterior</span>') . ($nextUrl !== '' ? '<a class="btn" href="' . e($nextUrl) . '">Siguiente →</a>' : '<span class="btn disabled" aria-disabled="true">Siguiente →</span>') . '</div></div>';
    $content .= '<div class="card"><div class="split"><div><div class="kicker">Identificación del equipo</div><h2>' . e($equipment['name'] ?: 'Equipo sin hostname') . '</h2><p>'
        . '<strong>Serial registrado:</strong> ' . e($equipment['serial_number'] ?: 'No reportado') . '<br>'
        . '<strong>Placa registrada:</strong> ' . e($confirmedPlate ?: ($confirmedPlateRaw ?: 'No reportada')) . '<br>'
        . '<strong>Propiedad registrada:</strong> ' . status_badge($equipment['ownership_type'] ?: 'desconocido') . '<br>'
        . '<strong>Categoría:</strong> ' . e($assetCategoryLabel) . '<br>'
        . '<strong>Tipo GLPI:</strong> ' . e($equipment['equipment_type'] ?: 'No reportado') . '<br>'
        . '<strong>Marca/Modelo:</strong> ' . e(trim($equipment['manufacturer'] . ' ' . $equipment['model']) ?: 'No reportado') . '<br>'
        . '<strong>Usuario:</strong> ' . e($equipment['alternate_user'] ?: 'No reportado') . '<br>'
        . '<strong>IP:</strong> ' . e($equipment['ip_address'] ?: 'No reportada') . '</p></div>'
        . '<div class="comparison"><strong>Sede asignada</strong><p>' . e($equipment['identificador'] . ' · ' . $equipment['nombre_sede']) . '<br>' . e($equipment['tipo_sede'].' · '.$equipment['departamento'] . ' / ' . $equipment['municipio']) . '</p><strong>Localización de origen</strong><p>' . e($equipment['source_location'] ?: 'No reportada') . '</p></div></div></div>';

    if ($assetCategory === 'monitor') {
        $content .= '<div class="card"><h3>Características del monitor</h3><div class="spec-grid"><div class="spec-item"><span>Fabricante</span><strong>'.e($equipment['manufacturer'] ?: 'No reportado').'</strong></div><div class="spec-item"><span>Modelo</span><strong>'.e($equipment['model'] ?: 'No reportado').'</strong></div><div class="spec-item"><span>Tamaño / diagonal</span><strong>'.e($equipment['screen_size'] ?: 'No reportado').'</strong></div><div class="spec-item"><span>Conexión</span><strong>'.e($equipment['connection_type'] ?: 'No reportada').'</strong></div></div></div>';
    } elseif ($assetCategory === 'impresora') {
        $content .= '<div class="card"><h3>Características de la impresora</h3><div class="spec-grid"><div class="spec-item"><span>Fabricante</span><strong>'.e($equipment['manufacturer'] ?: 'No reportado').'</strong></div><div class="spec-item"><span>Modelo</span><strong>'.e($equipment['model'] ?: 'No reportado').'</strong></div><div class="spec-item"><span>Tecnología</span><strong>'.e($equipment['print_technology'] ?: 'No reportada').'</strong></div><div class="spec-item"><span>Conexión</span><strong>'.e($equipment['connection_type'] ?: 'No reportada').'</strong></div></div></div>';
    } elseif (is_computer_category($assetCategory)) {
        $content .= '<div class="card"><h3>Características técnicas registradas</h3><div class="spec-grid"><div class="spec-item"><span>Sistema operativo</span><strong>'.e($systemName ?: 'No reportado').'</strong></div><div class="spec-item"><span>Versión</span><strong>'.e($systemVersion ?: 'No reportada').'</strong></div><div class="spec-item"><span>Arquitectura</span><strong>'.e($architecture ?: 'No reportada').'</strong></div><div class="spec-item"><span>Procesador</span><strong>'.e($processor ?: 'No reportado').'</strong></div><div class="spec-item"><span>Memoria RAM</span><strong>'.e($memory ?: 'No reportada').'</strong></div></div></div>';
    }

    $plateNote = $confirmedPlate !== ''
        ? '<div class="note"><strong>Placa consolidada:</strong> '.e($confirmedPlate).'. Confírmela o corríjala durante la validación.</div>'
        : ($confirmedPlateInvalid
            ? '<div class="alert alert-warning"><strong>Placa histórica inválida:</strong> '.e($confirmedPlateRaw).'. Corríjala al formato vigente: '.e($plateExample).'.</div>'
            : '<div class="alert alert-warning"><strong>Placa RNEC no registrada:</strong> será obligatoria únicamente si el equipo se identifica como propio.</div>');
    $selectedOwnership = (string)($validation['ownership_type'] ?? 'desconocido');
    $belongsReasonOtherValue = (string)($validationNoteParts['belongs_reason_other'] ?? '');
    $plateUnavailableReasonValue = (string)($validationNoteParts['placa_unavailable_reason'] ?? '');
    $generalValidationNotes = (string)($validationNoteParts['notes'] ?? '');
    $plateUnavailableChecked = $selectedOwnership === 'propio' && $plateUnavailableReasonValue !== '';
    $suggestedPlateAction = $suggestedPlate !== ''
        ? '<button type="button" class="btn btn-sm btn-secondary" data-suggested-plate-action data-copy-suggested-plate="'.e($suggestedPlate).'"'.($selectedOwnership==='propio'?'':' hidden').'>Usar placa sugerida</button>'
        : '';
    $suggestedPlateHelp = $suggestedPlate !== ''
        ? '<small class="form-text plate-suggestion-help">Sugerida por Almacén: <strong>'.e($suggestedPlate).'</strong></small>'
        : '';

    $selectedStatus = (string)($validation['physical_condition'] ?? 'pendiente');
    $selectedBelongs = (string)($validation['belongs_status'] ?? 'desconocido');
    $belongsOptions = [
        'pertenece'=>['icon'=>'✓','title'=>'Sí pertenece','description'=>'El equipo está físicamente en esta oficina y corresponde a su inventario.'],
        'no_pertenece'=>['icon'=>'↗','title'=>'No pertenece','description'=>'El equipo no corresponde a esta oficina, fue trasladado o no se encuentra.'],
    ];
    $belongsCards='';
    foreach($belongsOptions as $value=>$option){$checked=$selectedBelongs===$value?' checked':'';$belongsCards.='<label class="choice-card"><input type="radio" name="belongs_status" value="'.e($value).'" data-belongs-selector required'.$checked.'><span class="choice-card-icon">'.e($option['icon']).'</span><span class="choice-card-copy"><strong>'.e($option['title']).'</strong><small>'.e($option['description']).'</small></span><span class="choice-card-check">✓</span></label>';}
    $belongsReasonChoices=[''=>'Seleccione el motivo','trasladado'=>'Fue trasladado a otra sede','asignacion_incorrecta'=>'Asignación incorrecta en el inventario','prestamo'=>'Está en préstamo','reparacion'=>'Está en reparación fuera de la sede','baja'=>'Fue retirado o dado de baja','no_localizado'=>'No fue localizado físicamente','otro'=>'Otro motivo'];
    $ownershipOptions = [
        'propio'=>['icon'=>'🏛️','title'=>'Propio de la RNEC','description'=>'Activo institucional. Registre la placa visible o justifique por qué no puede observarse físicamente.'],
        'comodato'=>['icon'=>'🤝','title'=>'En comodato','description'=>'Entregado temporalmente por un tercero. La placa es opcional.'],
        'donado_sin_legalizar'=>['icon'=>'🎁','title'=>'Donado sin legalizar','description'=>'Recibido en donación y pendiente de legalización. La placa es opcional.'],
    ];
    $ownershipCards = '';
    foreach ($ownershipOptions as $value=>$option) {
        $checked = $selectedOwnership === $value ? ' checked' : '';
        $ownershipCards .= '<label class="choice-card"><input type="radio" name="ownership_type" value="'.e($value).'" data-ownership-selector required'.$checked.'><span class="choice-card-icon" aria-hidden="true">'.e($option['icon']).'</span><span class="choice-card-copy"><strong>'.e($option['title']).'</strong><small>'.e($option['description']).'</small></span><span class="choice-card-check" aria-hidden="true">✓</span></label>';
    }
    $statusOptions = [
        'activo'=>['icon'=>'✅','title'=>'Activo','description'=>'Está en uso o disponible para prestar servicio.'],
        'inactivo'=>['icon'=>'⏸️','title'=>'Inactivo','description'=>'Permanece en la sede, pero actualmente no está en uso.'],
        'para_baja'=>['icon'=>'⚠️','title'=>'Para baja','description'=>'Requiere iniciar o completar el trámite administrativo de baja.'],
        'dado_baja'=>['icon'=>'📄','title'=>'Dado de baja','description'=>'Ya cuenta con fecha y resolución o acta de baja.'],
        'en_almacen'=>['icon'=>'📦','title'=>'En Almacén','description'=>'Está bajo custodia del almacén y no en operación.'],
        'en_mantenimiento'=>['icon'=>'🛠️','title'=>'En Mantenimiento','description'=>'Está en diagnóstico, reparación o pendiente de repuesto.'],
        'trasladado'=>['icon'=>'↗️','title'=>'Trasladado','description'=>'El equipo se encuentra actualmente en otra sede.'],
    ];
    $statusCards = '';
    foreach ($statusOptions as $value=>$option) {
        $checked = $selectedStatus === $value ? ' checked' : '';
        $statusCards .= '<label class="choice-card choice-card-status"><input type="radio" name="physical_condition" value="'.e($value).'" data-asset-status-selector required'.$checked.'><span class="choice-card-icon" aria-hidden="true">'.e($option['icon']).'</span><span class="choice-card-copy"><strong>'.e($option['title']).'</strong><small>'.e($option['description']).'</small></span><span class="choice-card-check" aria-hidden="true">✓</span></label>';
    }
    $serialRegistered = trim((string)($equipment['serial_number'] ?? ''));
    $serialPendingAlert = ((int)($equipment['serial_review_required'] ?? 0) === 1 && (string)($equipment['serial_review_reason'] ?? '') === 'duplicado')
        ? '<div class="alert alert-warning"><strong>Serial pendiente:</strong> el valor importado estaba repetido en el inventario activo y fue eliminado. Verifique físicamente la etiqueta y registre el serial correcto y único.</div>'
        : '';
    $plateForInput = normalize_placa_rnec((string)($validation['placa_reported'] ?? '')) ?? '';
    $serialQuickAction = $serialRegistered !== '' ? '<button type="button" class="btn btn-sm btn-secondary" data-fill-target="serial_reported" data-fill-value="'.e($serialRegistered).'">Usar serial registrado</button>' : '';
    $plateQuickAction = $confirmedPlate !== '' ? '<button type="button" class="btn btn-sm btn-secondary" data-fill-target="placa_reported" data-fill-value="'.e($confirmedPlate).'">Usar placa registrada</button>' : '';

    $draftAttributes = $validationDraftsEnabled
        ? ' data-draft-key="sivi.validation.'.(int)$campaignId.'.'.(int)$id.'" data-draft-endpoint="'.e(route_url('validation_draft',['campaign_id'=>$campaignId,'equipment_id'=>$id])).'" data-clear-draft="'.(!empty($_GET['saved'])?'1':'0').'"'
        : '';
    $draftStatusHtml = $validationDraftsEnabled
        ? '<div class="draft-status draft-status-passive" data-draft-status aria-live="polite"><span class="draft-status-icon">☁</span><span><strong>Guardado automático</strong><small>SIVI protege silenciosamente el avance mientras diligencia la validación.</small></span></div>'
        : '';

    $validationEvidenceHtml = '';
    if ($validationImagesEnabled) {
        $validationEvidenceHtml = '<div class="evidence-grid intuitive-evidence-grid"><label class="evidence-upload-card"><span class="evidence-upload-icon">📷</span><strong>Foto general'.($campaignRequiresEvidence?' <span class="text-danger">*</span>':'').'</strong><small>'.($campaignRequiresEvidence?'Obligatoria en esta campaña. Debe mostrar el equipo completo.':'Una imagen completa del equipo.').'</small><input type="file" name="evidence_general" accept="image/jpeg,image/png,image/webp" capture="environment" data-file-preview'.($campaignRequiresEvidence?' required':'').'><span class="evidence-file-name" data-file-name>Ningún archivo seleccionado</span></label><label class="evidence-upload-card"><span class="evidence-upload-icon">🏷️</span><strong>Foto de la placa</strong><small>Debe permitir leer todos los números cuando sea visible.</small><input type="file" name="evidence_placa" accept="image/jpeg,image/png,image/webp" capture="environment" data-file-preview><span class="evidence-file-name" data-file-name>Ningún archivo seleccionado</span></label><label class="evidence-upload-card"><span class="evidence-upload-icon">🔎</span><strong>Foto del serial</strong><small>Enfoque la etiqueta del fabricante.</small><input type="file" name="evidence_serial" accept="image/jpeg,image/png,image/webp" capture="environment" data-file-preview><span class="evidence-file-name" data-file-name>Ningún archivo seleccionado</span></label><label class="evidence-upload-card"><span class="evidence-upload-icon">📎</span><strong>Soporte adicional</strong><small>Acta, resolución, daño o mantenimiento.</small><input type="file" name="evidence_documento" accept="image/jpeg,image/png,image/webp,application/pdf" data-file-preview><span class="evidence-file-name" data-file-name>Ningún archivo seleccionado</span></label></div><p class="muted small">Máximo 8 MB por archivo. Las imágenes se comprimen automáticamente y se conserva su huella SHA-256.</p>';
    }
    $stepThreeTitle = $validationImagesEnabled ? 'Adjunte evidencias y confirme' : 'Revise y confirme';
    $stepThreeHelp = $validationImagesEnabled
        ? 'Las fotografías facilitan la revisión y reducen solicitudes de corrección.'
        : 'La carga de imágenes está deshabilitada por la configuración de SIVI. Revise el resumen antes de guardar.';

    $content .= '<div class="card validation-wizard"><div class="wizard-head"><div><div class="kicker">Validación guiada</div><h3>Validación del activo, serial y Placa RNEC</h3><p class="muted wizard-intro">Complete tres pasos cortos. SIVI mostrará únicamente los campos que correspondan a sus respuestas.</p></div><div class="wizard-completion" aria-live="polite"><span>Completitud</span><strong data-wizard-completion>0%</strong></div></div>'
        . '<div class="wizard-progress" role="navigation" aria-label="Pasos de la validación"><button type="button" class="wizard-progress-step is-active" data-wizard-go="1"><span>1</span><small>Activo</small></button><i></i><button type="button" class="wizard-progress-step" data-wizard-go="2"><span>2</span><small>Serial y placa</small></button><i></i><button type="button" class="wizard-progress-step" data-wizard-go="3"><span>3</span><small>Confirmación</small></button></div>'
        . '<div class="validation-help"><strong>Antes de empezar:</strong><span>Revise físicamente el equipo y tenga a la vista el serial, la placa y los soportes que correspondan.</span></div>'
        . $serialPendingAlert . $plateNote
        . '<form method="post" enctype="multipart/form-data" id="equipment-validation-form" data-asset-validation-form data-mobile-scan-form data-dirty-guard'.$draftAttributes.' data-original-serial="'.e($serialRegistered).'" data-original-plate="'.e($confirmedPlate).'">' . csrf_field()
        . '<input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="campaign_id" value="'.$campaignId.'">'.$draftStatusHtml.'<div class="wizard-error" data-wizard-error role="alert" hidden></div>'
        . '<section class="wizard-section" data-wizard-step="1"><div class="wizard-step-title"><span>1</span><div><strong>Identifique la situación actual del equipo</strong><small>Seleccione una opción en cada grupo. Puede cambiarla antes de guardar.</small></div></div>'
        . '<fieldset class="choice-fieldset"><legend>¿El equipo pertenece a esta oficina? <span class="text-danger">*</span></legend><p>Responda según la verificación física y la asignación real del activo.</p><div class="choice-card-grid choice-card-grid-2">'.$belongsCards.'</div></fieldset><div class="conditional-panel" data-belongs-panel="no_pertenece"><div class="conditional-panel-head"><strong>Motivo de la novedad</strong><span>Indique por qué el equipo no pertenece a esta oficina.</span></div>'.field('belongs_reason','Motivo',(string)($validation['belongs_reason']??''),'select',['choices'=>$belongsReasonChoices]).'</div><div class="conditional-panel" data-belongs-other-panel><div class="conditional-panel-head"><strong>Explique el otro motivo</strong><span>Describa brevemente la situación encontrada.</span></div>'.field('belongs_reason_other','Otro motivo',$belongsReasonOtherValue,'textarea',['placeholder'=>'Escriba el motivo por el cual el equipo no pertenece a esta sede.']).'</div>'
        . '<fieldset class="choice-fieldset"><legend>¿Cuál es el tipo de propiedad? <span class="text-danger">*</span></legend><p>Esta respuesta determina si la Placa RNEC será obligatoria.</p><div class="choice-card-grid choice-card-grid-3">'.$ownershipCards.'</div></fieldset>'
        . '<fieldset class="choice-fieldset"><legend>¿Cuál es el estado actual del equipo? <span class="text-danger">*</span></legend><p>Seleccione la opción que describa dónde está y en qué situación se encuentra.</p><div class="choice-card-grid">'.$statusCards.'</div></fieldset>'
        . '<div class="conditional-panel" data-status-panel="dado_baja"><div class="conditional-panel-head"><strong>📄 Información de la baja</strong><span>Estos datos son obligatorios porque seleccionó “Dado de baja”.</span></div><div class="form-grid">'
        . field('disposal_date','Fecha de baja',(string)($validation['disposal_date'] ?? ''),'date')
        . field('disposal_document','Resolución o acta de baja',(string)($validation['disposal_document'] ?? ''),'text',['placeholder'=>'Ejemplo: Resolución 123 de 2026 o Acta 045'])
        . '</div></div><div class="conditional-panel" data-status-panel="trasladado"><div class="conditional-panel-head"><strong>↗️ Ubicación actual del equipo</strong><span>Seleccione la sede donde se encuentra físicamente el activo.</span></div>'
        . module_sede_selector_fields($destinationRows,(int)($validation['destination_sede_id'] ?? 0),'validation_destination','destination_sede_id','Nombre de la sede',['destination'=>true,'exclude_sede_id'=>(int)$equipment['current_sede_id']])
        . '</div>'
        . '<div class="wizard-navigation"><span>Paso 1 de 3</span><button type="button" class="btn" data-wizard-next>Continuar a serial y placa</button></div></section>'
        . '<section class="wizard-section" data-wizard-step="2"><div class="wizard-step-title"><span>2</span><div><strong>Confirme el serial y la Placa RNEC</strong><small>Compare lo registrado en SIVI con lo que observa físicamente.</small></div></div>'
        . mobile_scan_connection_panel($campaignId, (int)$equipment['current_sede_id'])
        . '<div class="verification-grid"><div class="verification-card"><div class="verification-card-head"><div><span>Serial registrado</span><strong>'.e($serialRegistered ?: 'No reportado').'</strong></div>'.$serialQuickAction.'</div><label class="field"><span class="form-label">Número de serie verificado <span class="text-danger">*</span></span><input class="form-control" type="text" name="serial_reported" value="'.e((string)($validation['serial_reported'] ?? $serialRegistered)).'" required autocomplete="off" placeholder="Digite exactamente el serial visible" data-verification-input="serial" data-mobile-scan-target="serial_number"></label><div class="verification-result" data-verification-result="serial">Pendiente de verificar</div></div>'
        . '<div class="verification-card"><div class="verification-card-head"><div><span>Placa registrada</span><strong>'.e($confirmedPlate ?: ($confirmedPlateRaw ?: 'No reportada')).'</strong></div>'.$plateQuickAction.'</div><div class="field"><span class="form-label">Placa RNEC verificada <span class="plate-required-marker" data-plate-required-marker>(opcional)</span></span><div class="plate-input-actions"><input class="form-control" type="text" name="placa_reported" value="'.e($plateForInput).'" placeholder="Inicie con 000; el guion se agrega automáticamente" autocomplete="off" data-placa-rnec="1" data-sivi-plate-input="1" data-plate-total-characters="'.$plateTotal.'" data-conditional-plate="1" data-verification-input="plate" data-mobile-scan-target="placa_rnec">'.$suggestedPlateAction.'</div>'.$suggestedPlateHelp.'</div><div class="form-text" data-plate-help>La placa es opcional para comodatos y donados sin legalizar.</div><div class="plate-unavailable-option" data-own-plate-exception'.($selectedOwnership==='propio'?'':' hidden').'><label class="confirmation-box compact-confirmation"><input type="checkbox" name="placa_no_visible" value="1" data-plate-unavailable'.($plateUnavailableChecked?' checked':'').'><span><strong>No fue posible visualizar físicamente la placa</strong><small>Use esta opción únicamente cuando confirmó que el equipo pertenece a la RNEC pero la placa no puede observarse.</small></span></label></div><div class="conditional-panel" data-plate-unavailable-panel><div class="conditional-panel-head"><strong>Justificación obligatoria</strong><span>Explique por qué no puede visualizarse la Placa RNEC.</span></div>'.field('plate_unavailable_reason','Motivo',$plateUnavailableReasonValue,'textarea',['placeholder'=>'Ejemplo: etiqueta deteriorada, cubierta, desprendida o inaccesible físicamente.']).'</div><div class="verification-result" data-verification-result="plate">Pendiente de verificar</div></div></div>'
        . '<div class="tip-box"><strong>Consejo:</strong><span>Digite únicamente lo que puede leer en el equipo. No complete seriales o placas por suposición.</span></div>'
        . '<div class="wizard-navigation"><button type="button" class="btn btn-secondary" data-wizard-back>Regresar</button><span>Paso 2 de 3</span><button type="button" class="btn" data-wizard-next>Continuar a evidencias</button></div></section>'
        . '<section class="wizard-section" data-wizard-step="3"><div class="wizard-step-title"><span>3</span><div><strong>'.e($stepThreeTitle).'</strong><small>'.e($stepThreeHelp).'</small></div></div>'
        . $validationEvidenceHtml
        . field('notes','Observaciones para el revisor',$generalValidationNotes,'textarea',['wide'=>true,'placeholder'=>'Explique diferencias, daños, faltantes o cualquier información que ayude a revisar la validación.'])
        . '<div class="validation-summary" data-validation-summary><div class="validation-summary-head"><div><strong>Revise antes de guardar</strong><small>Este será el resumen enviado para revisión.</small></div><span data-summary-status>Información incompleta</span></div><div class="summary-grid"><span>Pertenece a la oficina</span><b data-summary-belongs>Sí</b><span>Estado</span><b data-summary-condition>Pendiente</b><span>Propiedad</span><b data-summary-ownership>Pendiente</b><span>Serial</span><b data-summary-serial>Pendiente</b><span>Placa</span><b data-summary-plate>Pendiente</b><span>Destino</span><b data-summary-destination>No aplica</b></div></div>'
        . '<label class="confirmation-box"><input type="checkbox" name="validation_confirmation" value="1" required><span><strong>Confirmo que realicé la verificación física del equipo.</strong><small>Revisé el estado, el serial y la placa, y la información registrada corresponde a lo observado.</small></span></label>'
        . '<div class="wizard-navigation wizard-navigation-final"><button type="button" class="btn btn-secondary" data-wizard-back>Regresar</button><span>Paso 3 de 3</span><div class="wizard-final-actions"><a class="btn btn-secondary" href="'.e(route_url('equipos',['campaign_id'=>$campaignId,'sede_id'=>$equipment['current_sede_id']])).'">Cancelar</a><button class="btn btn-success" type="submit">Confirmar y guardar validación</button></div></div></section></form></div>';

    if (!Auth::is('registrador') && !empty($validation['id'])) {
        $content .= '<div class="card"><h3>Revisión del Formador / Admin GI</h3><p>Estado de revisión: '.status_badge($validation['review_status']).'</p><a class="btn btn-success" data-confirm="Esta acción aplicará el estado, propiedad, serial, placa y traslado al inventario consolidado. ¿Continuar?" href="'.e(route_url('equipo_validar',['id'=>$id,'campaign_id'=>$campaignId,'action'=>'approve','csrf'=>csrf_token()])).'">Aprobar y aplicar</a></div>';
    }
    render_page('Validar equipo',$content,['subtitle'=>'Validación del activo, serial y Placa RNEC.','actions'=>'<a class="btn btn-sm btn-outline-primary" href="'.e(route_url('historial_equipo',['id'=>$id])).'">Ver historial</a>']);
}


function validation_draft_page(): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    if (!AppSettings::validationDraftsEnabled()) {
        http_response_code(404);
        echo json_encode(['ok'=>false,'message'=>'Guardado de borradores deshabilitado por configuración.'],JSON_UNESCAPED_UNICODE);
        exit;
    }
    $campaignId=(int)($_GET['campaign_id']??$_POST['campaign_id']??0);
    $equipmentId=(int)($_GET['equipment_id']??$_POST['equipment_id']??0);
    $equipment=Database::fetchOne('SELECT id,current_sede_id FROM equipment WHERE id=? AND active=1',[$equipmentId]);
    if(!$equipment||!campaign_accessible_to_current_user($campaignId)||!campaign_equipment_exists($campaignId,$equipmentId)||!Scope::canAccessEquipment($equipmentId)){
        http_response_code(403);echo json_encode(['ok'=>false,'message'=>'Acceso denegado'],JSON_UNESCAPED_UNICODE);exit;
    }

    $isPost=request_method('POST');
    if($isPost) verify_csrf();
    $userId=(int)(Auth::id()??0);
    if(session_status()===PHP_SESSION_ACTIVE) session_write_close();

    if($isPost){
        $payload=(string)($_POST['payload']??'');
        if(strlen($payload)>65535){http_response_code(422);echo json_encode(['ok'=>false,'message'=>'El borrador supera el tamaño permitido.'],JSON_UNESCAPED_UNICODE);exit;}
        $decoded=json_decode($payload,true);
        if(!is_array($decoded)){http_response_code(422);echo json_encode(['ok'=>false,'message'=>'Borrador inválido.'],JSON_UNESCAPED_UNICODE);exit;}
        unset($decoded['csrf'],$decoded['id'],$decoded['campaign_id'],$decoded['equipment_id']);
        $allowed=['belongs_status','belongs_reason','belongs_reason_other','physical_condition','ownership_type','serial_reported','placa_reported','placa_no_visible','plate_unavailable_reason','destination_sede_id','disposal_date','disposal_document','notes','validation_confirmation'];
        $clean=[];foreach($allowed as $key){if(array_key_exists($key,$decoded))$clean[$key]=is_scalar($decoded[$key])?mb_substr((string)$decoded[$key],0,4000):'';}
        Database::execute('INSERT INTO validation_drafts(campaign_id,equipment_id,user_id,sede_id,payload_json) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE sede_id=VALUES(sede_id),payload_json=VALUES(payload_json),updated_at=NOW()',[$campaignId,$equipmentId,$userId,(int)$equipment['current_sede_id'],json_encode($clean,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        echo json_encode(['ok'=>true,'updated_at'=>date(DATE_ATOM)],JSON_UNESCAPED_UNICODE);exit;
    }
    $row=Database::fetchOne('SELECT payload_json,updated_at FROM validation_drafts WHERE campaign_id=? AND equipment_id=? AND user_id=?',[$campaignId,$equipmentId,$userId]);
    echo json_encode(['ok'=>true,'draft'=>$row?json_decode((string)$row['payload_json'],true):null,'updated_at'=>$row['updated_at']??null],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
}

function verify_get_csrf(): void
{
    $token=(string)($_GET['csrf'] ?? '');if(!hash_equals((string)($_SESSION['csrf'] ?? ''),$token)){http_response_code(419);render_error('Solicitud vencida','Regrese e intente nuevamente.');exit;}
}


function campaign_site_contact_data(int $campaignId, int $sedeId): array
{
    $row = Database::fetchOne(
        "SELECT cs.campaign_id,cs.sede_id,cs.status,cs.responsible_name,cs.responsible_role,cs.responsible_email,cs.responsible_phone,cs.contact_confirmed_by,cs.contact_confirmed_at,"
        . "cs.site_confirmation_status,cs.site_confirmation_notes,cs.site_confirmed_address,cs.site_confirmed_by,cs.site_confirmed_at,"
        . "s.identificador,s.nombre_sede,s.tipo_sede,s.cod_dd,s.departamento,s.municipio,s.direccion_original,s.direccion_actual,s.direccion_observacion,s.email_contacto,s.email_institucional,s.telefono_contacto "
        . "FROM campaign_sedes cs JOIN sedes s ON s.id=cs.sede_id WHERE cs.campaign_id=? AND cs.sede_id=?",
        [$campaignId,$sedeId]
    );
    if (!$row) return [];

    $row['resolved_name'] = trim((string)($row['responsible_name'] ?? ''));
    $row['resolved_role'] = trim((string)($row['responsible_role'] ?? ''));
    $row['resolved_email'] = trim((string)($row['responsible_email'] ?? ''));
    $row['resolved_phone'] = trim((string)($row['responsible_phone'] ?? ''));
    $row['resolved_address'] = trim((string)($row['site_confirmed_address'] ?: ($row['direccion_actual'] ?: $row['direccion_original'])));
    $row['contact_complete'] = $row['resolved_name'] !== ''
        && filter_var($row['resolved_email'], FILTER_VALIDATE_EMAIL) !== false
        && valid_contact_phone($row['resolved_phone'], true);
    $row['site_complete'] = !empty($row['site_confirmed_at']) && $row['resolved_address'] !== '';
    $row['profile_complete'] = $row['contact_complete'] && $row['site_complete'];
    return $row;
}

function campaign_site_contact_complete(int $campaignId, int $sedeId): bool
{
    $data = campaign_site_contact_data($campaignId,$sedeId);
    return !empty($data['contact_complete']);
}

function campaign_site_profile_complete(int $campaignId, int $sedeId): bool
{
    $data = campaign_site_contact_data($campaignId,$sedeId);
    return !empty($data['profile_complete']);
}

function campaign_site_contact_page(): void
{
    $campaignId=(int)($_GET['campaign_id']??$_POST['campaign_id']??0);
    $sedeId=(int)($_GET['sede_id']??$_POST['sede_id']??0);
    $equipmentId=(int)($_GET['equipment_id']??$_POST['equipment_id']??0);
    if($campaignId<1||$sedeId<1||!campaign_accessible_to_current_user($campaignId)||!Scope::canAccessSede($sedeId)){
        render_error('Acceso denegado','No puede confirmar la información de esta sede.');return;
    }
    $data=campaign_site_contact_data($campaignId,$sedeId);
    if(!$data){render_error('Sede fuera de la campaña','La sede seleccionada no pertenece a esta campaña.');return;}

    if(request_method('POST')){
        verify_csrf();
        $address=trim((string)($_POST['site_confirmed_address']??''));
        $siteNotes=trim((string)($_POST['site_confirmation_notes']??''));
        $siteStatus=$siteNotes!==''?'con_novedad':'confirmada';
        $name=trim((string)($_POST['responsible_name']??''));
        $role=trim((string)($_POST['responsible_role']??''));
        $email=mb_strtolower(trim((string)($_POST['responsible_email']??'')));
        $phone=normalize_contact_phone((string)($_POST['responsible_phone']??''));
        $confirmed=(string)($_POST['site_identity_confirmation']??'')==='1';

        if(!$confirmed){flash('danger','Debe confirmar que revisó la información de la sede.');redirect('campania_sede_contacto',['campaign_id'=>$campaignId,'sede_id'=>$sedeId,'equipment_id'=>$equipmentId]);}
        if($address===''){flash('danger','Confirme o corrija la dirección de la sede.');redirect('campania_sede_contacto',['campaign_id'=>$campaignId,'sede_id'=>$sedeId,'equipment_id'=>$equipmentId]);}
        if($siteNotes!==''&&mb_strlen($siteNotes)<5){flash('danger','Si registra una observación de la sede, descríbala con suficiente detalle.');redirect('campania_sede_contacto',['campaign_id'=>$campaignId,'sede_id'=>$sedeId,'equipment_id'=>$equipmentId]);}
        if(mb_strlen($name)<3){flash('danger','Registre el nombre completo del Registrador o responsable de la sede.');redirect('campania_sede_contacto',['campaign_id'=>$campaignId,'sede_id'=>$sedeId,'equipment_id'=>$equipmentId]);}
        $allowedResponsibleRoles=['Registrador','Auxiliar','Técnico'];
        if(!in_array($role,$allowedResponsibleRoles,true)){flash('danger','Seleccione uno de los cargos permitidos: Registrador, Auxiliar o Técnico.');redirect('campania_sede_contacto',['campaign_id'=>$campaignId,'sede_id'=>$sedeId,'equipment_id'=>$equipmentId]);}
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)){flash('danger','Registre un correo electrónico válido para el contacto de la sede.');redirect('campania_sede_contacto',['campaign_id'=>$campaignId,'sede_id'=>$sedeId,'equipment_id'=>$equipmentId]);}
        if(!valid_contact_phone($phone,true)){flash('danger','El número de contacto debe tener 10 dígitos y comenzar por 60 para fijo o por 3 para celular.');redirect('campania_sede_contacto',['campaign_id'=>$campaignId,'sede_id'=>$sedeId,'equipment_id'=>$equipmentId]);}
        $old=$data;
        Database::execute(
            "UPDATE campaign_sedes SET responsible_name=?,responsible_role=?,responsible_email=?,responsible_phone=?,contact_confirmed_by=?,contact_confirmed_at=NOW(),site_confirmation_status=?,site_confirmation_notes=?,site_confirmed_address=?,site_confirmed_by=?,site_confirmed_at=NOW(),status=IF(status='pendiente','en_diligenciamiento',status),notification_email=?,notification_status='pendiente',notification_error=NULL,notified_at=NULL WHERE campaign_id=? AND sede_id=?",
            [$name,$role,$email,$phone?:null,Auth::id(),$siteStatus,$siteNotes?:null,$address,Auth::id(),$email,$campaignId,$sedeId]
        );
        Database::execute(
            "UPDATE sedes SET direccion_actual=?,direccion_observacion=COALESCE(NULLIF(?,''),direccion_observacion),email_contacto=?,telefono_contacto=COALESCE(NULLIF(?,''),telefono_contacto),direccion_actualizada_por=?,direccion_actualizada_en=NOW() WHERE id=?",
            [$address,$siteNotes,$email,$phone,Auth::id(),$sedeId]
        );
        audit('confirm_campaign_site_profile','campaign_sede',$sedeId,$old,[
            'campaign_id'=>$campaignId,'site_confirmation_status'=>$siteStatus,'site_confirmed_address'=>$address,
            'site_confirmation_notes'=>$siteNotes,'responsible_name'=>$name,'responsible_role'=>$role,
            'responsible_email'=>$email,'responsible_phone'=>$phone,
        ]);
        flash('success','La información de la sede quedó validada. Puede continuar con los equipos.');
        if($equipmentId>0)redirect('equipo_validar',['id'=>$equipmentId,'campaign_id'=>$campaignId]);
        redirect('equipos',['campaign_id'=>$campaignId,'sede_id'=>$sedeId]);
    }

    $campaign=Database::fetchOne('SELECT name,status,start_date,end_date FROM campaigns WHERE id=?',[$campaignId])?:[];
    $roleValue=(string)($data['resolved_role']??'');
    $roleChoices=[
        ''=>'Seleccione el cargo',
        'Registrador'=>'Registrador',
        'Auxiliar'=>'Auxiliar',
        'Técnico'=>'Técnico',
    ];
    if(!array_key_exists($roleValue,$roleChoices))$roleValue='';

    $content='<div class="card"><div class="kicker">Paso 1 obligatorio</div><h2>Validar información de la sede</h2><p>Antes de revisar los equipos, confirme que la sede, su dirección y los datos del responsable corresponden a la oficina donde se realizará la verificación.</p><div class="note note-info"><strong>Campaña:</strong> '.e((string)($campaign['name']??'')).'<br><strong>Identificador:</strong> '.e($data['identificador']).'<br><strong>Sede:</strong> '.e($data['nombre_sede']).'<br><strong>Tipo:</strong> '.e($data['tipo_sede']).'<br><strong>Departamento / municipio:</strong> '.e($data['departamento'].' / '.$data['municipio']).'</div></div>';
    $content.='<div class="card"><form method="post">'.csrf_field().'<input type="hidden" name="campaign_id" value="'.$campaignId.'"><input type="hidden" name="sede_id" value="'.$sedeId.'"><input type="hidden" name="equipment_id" value="'.$equipmentId.'">'
        .'<h3>Información de la sede</h3><div class="form-grid">'
        .field('site_confirmed_address','Dirección confirmada o corregida',(string)$data['resolved_address'],'textarea',['required'=>true,'help'=>'La dirección original se conserva en la trazabilidad.'])
        .field('site_confirmation_notes','Observación de la sede',(string)($data['site_confirmation_notes']??''),'textarea',['placeholder'=>'Solo si encuentra una novedad, explique el cambio o inconsistencia. Si todo está correcto, deje este campo vacío.'])
        .'</div><hr><h3>Registrador o responsable de la sede</h3><div class="form-grid">'
        .field('responsible_name','Nombre completo del Registrador o responsable',(string)$data['resolved_name'],'text',['required'=>true,'placeholder'=>'Nombres y apellidos','help'=>'Este campo no se completa automáticamente con el usuario autenticado.','attributes'=>['autocomplete'=>'off']])
        .field('responsible_role','Cargo',$roleValue,'select',['required'=>true,'choices'=>$roleChoices])
        .field('responsible_email','Correo electrónico de contacto',(string)$data['resolved_email'],'email',['required'=>true,'help'=>'Se utiliza para avisos de la campaña y no modifica automáticamente el correo de acceso.','attributes'=>['autocomplete'=>'off']])
        .field('responsible_phone','Número de contacto',(string)$data['resolved_phone'],'tel',[
            'placeholder'=>'6012345678 o 3001234567',
            'help'=>contact_phone_help(),
            'attributes'=>[
                'inputmode'=>'numeric',
                'autocomplete'=>'tel',
                'minlength'=>'10',
                'maxlength'=>'10',
                'pattern'=>contact_phone_pattern(),
                'data-contact-phone'=>true,
                'title'=>'Digite 10 números: fijo desde 60 o celular desde 3',
            ],
        ])
        .'</div><label class="confirmation-box mt-3"><input type="checkbox" name="site_identity_confirmation" value="1" required><span><strong>Confirmo que revisé la información de esta sede.</strong><small>Los datos registrados corresponden a la oficina donde se verificará el inventario o dejé documentada la novedad encontrada.</small></span></label>'
        .'<div class="form-actions"><button class="btn btn-success">Guardar sede y continuar con los equipos</button><a class="btn btn-secondary" href="'.e(route_url('notificaciones')).'">Salir</a></div></form></div>';
    render_page('Validar información de la sede',$content,['subtitle'=>'Paso 1 de la validación: confirme la sede antes de habilitar el inventario.']);
}

function campaign_readiness(int $campaignId): array
{
    $campaign = Database::fetchOne('SELECT * FROM campaigns WHERE id=?',[$campaignId]) ?: [];
    $total=(int)(Database::fetchOne('SELECT COUNT(*) total FROM campaign_sedes WHERE campaign_id=?',[$campaignId])['total']??0);
    $equipment=(int)(Database::fetchOne('SELECT COUNT(*) total FROM campaign_equipment WHERE campaign_id=?',[$campaignId])['total']??0);
    $noInventory=(int)(Database::fetchOne('SELECT COUNT(*) total FROM campaign_sedes cs WHERE cs.campaign_id=? AND NOT EXISTS (SELECT 1 FROM campaign_equipment ce WHERE ce.campaign_id=cs.campaign_id AND ce.sede_id=cs.sede_id)',[$campaignId])['total']??0);
    $noRegistrar=(int)(Database::fetchOne("SELECT COUNT(*) total FROM campaign_sedes WHERE campaign_id=? AND NULLIF(TRIM(responsible_name),'') IS NULL",[$campaignId])['total']??0);
    $noEmail=(int)(Database::fetchOne("SELECT COUNT(*) total FROM campaign_sedes WHERE campaign_id=? AND NULLIF(TRIM(responsible_email),'') IS NULL",[$campaignId])['total']??0);
    $contactPending=(int)(Database::fetchOne("SELECT COUNT(*) total FROM campaign_sedes WHERE campaign_id=? AND (NULLIF(TRIM(responsible_name),'') IS NULL OR NULLIF(TRIM(responsible_email),'') IS NULL)",[$campaignId])['total']??0);
    $withoutSerial=(int)(Database::fetchOne("SELECT COUNT(*) total FROM campaign_equipment ce JOIN equipment e ON e.id=ce.equipment_id WHERE ce.campaign_id=? AND NULLIF(TRIM(e.serial_number),'') IS NULL",[$campaignId])['total']??0);
    $withoutPlate=(int)(Database::fetchOne("SELECT COUNT(*) total FROM campaign_equipment ce JOIN equipment e ON e.id=ce.equipment_id WHERE ce.campaign_id=? AND e.ownership_type='propio' AND NULLIF(TRIM(e.placa_rnec),'') IS NULL",[$campaignId])['total']??0);
    $duplicatePlate=(int)(Database::fetchOne("SELECT COALESCE(SUM(x.total),0) total FROM (SELECT COUNT(*) total FROM campaign_equipment ce JOIN equipment e ON e.id=ce.equipment_id WHERE ce.campaign_id=? AND NULLIF(TRIM(e.placa_rnec),'') IS NOT NULL GROUP BY e.placa_rnec HAVING COUNT(*)>1) x",[$campaignId])['total']??0);
    $duplicateSerial=(int)(Database::fetchOne("SELECT COALESCE(SUM(x.total),0) total FROM (SELECT COUNT(*) total FROM campaign_equipment ce JOIN equipment e ON e.id=ce.equipment_id WHERE ce.campaign_id=? AND NULLIF(TRIM(e.serial_number),'') IS NOT NULL GROUP BY e.serial_number HAVING COUNT(*)>1) x",[$campaignId])['total']??0);
    $overlap=campaign_overlap_summary($campaignId);
    $overlapCritical=empty($campaign['allow_overlap'])?(int)$overlap['equipos']:0;

    // La ausencia de inventario en algunas sedes y los datos de responsable/contacto
    // son advertencias operativas. SIVI solicita estos últimos al iniciar la validación.
    $critical=$overlapCritical;
    $warnings=$noInventory+$contactPending+$withoutSerial+$withoutPlate+$duplicatePlate+$duplicateSerial;
    return [
        'total'=>$total,'equipos'=>$equipment,'sin_inventario'=>$noInventory,'sin_registrador'=>$noRegistrar,'sin_correo'=>$noEmail,'contacto_pendiente'=>$contactPending,
        'sin_serial'=>$withoutSerial,'sin_placa'=>$withoutPlate,'placas_duplicadas'=>$duplicatePlate,'seriales_duplicados'=>$duplicateSerial,
        'equipos_solapados'=>(int)$overlap['equipos'],'campanias_solapadas'=>(int)$overlap['campanias'],'solapamiento_permitido'=>!empty($campaign['allow_overlap']),
        'criticos'=>$critical,'advertencias'=>$warnings,'apta'=>($total>0&&$equipment>0&&$critical===0),
    ];
}

function campaigns_page(): void
{
    Database::execute("UPDATE campaigns SET status='activa',published_at=COALESCE(published_at,NOW()) WHERE status='programada' AND start_date IS NOT NULL AND start_date<=CURDATE()");
    Database::execute("UPDATE campaigns SET status='en_revision',closed_at=COALESCE(closed_at,NOW()) WHERE status='activa' AND end_date IS NOT NULL AND end_date<CURDATE()");

    $allRows = Database::fetchAll(
        "SELECT c.*,(SELECT COUNT(*) FROM campaign_sedes cs WHERE cs.campaign_id=c.id) sedes_count,"
        . "(SELECT COUNT(*) FROM campaign_equipment ce WHERE ce.campaign_id=c.id) equipment_count,"
        . "(SELECT COUNT(*) FROM campaign_sedes cs WHERE cs.campaign_id=c.id AND cs.status IN ('enviado','en_revision','aprobado','cerrado')) completed_count "
        . "FROM campaigns c ORDER BY CASE c.status WHEN 'activa' THEN 0 WHEN 'programada' THEN 1 WHEN 'en_revision' THEN 2 WHEN 'borrador' THEN 3 ELSE 4 END,c.id DESC"
    );
    $rows = array_values(array_filter($allRows, static fn(array $row): bool => campaign_accessible_to_current_user((int)$row['id'])));
    $canCreate = (Auth::is('admin_gi') || Auth::is('superadmin')) && ImportQuality::campaignsAllowed();
    $readOnly = Auth::is('formador');
    $campaignKicker = $readOnly ? 'Consulta departamental' : 'Gestión operativa';
    $campaignHelp = $readOnly
        ? 'Consulte las campañas que incluyen sedes de sus departamentos. Este perfil no puede modificar su configuración ni estado.'
        : 'Defina el territorio y los tipos de activos, revise la preparación y publique únicamente cuando la campaña esté lista.';
    $content='<div class="card"><div class="toolbar"><div><div class="kicker">'.e($campaignKicker).'</div><h3>Campañas de verificación</h3><p class="muted">'.e($campaignHelp).'</p></div>'.($canCreate?'<a class="btn" href="'.e(route_url('campania_crear')).'">Crear campaña</a>':'').'</div>';
    if($readOnly)$content.='<div class="alert alert-info"><strong>Modo solo lectura:</strong> puede consultar cobertura, avance y preparación, pero no crear ni ejecutar acciones administrativas sobre campañas.</div>';
    if(!$canCreate && !ImportQuality::campaignsAllowed()) $content.='<div class="alert alert-danger">La creación de campañas está bloqueada por inconsistencias críticas de calidad. Revise el Centro de diagnóstico.</div>';
    if(!$rows){$content.=empty_state('No hay campañas disponibles',$readOnly?'No existen campañas dentro de los departamentos asignados a su perfil.':'Cree una campaña y defina su cobertura territorial y los activos que participarán.');}
    else{
        $content.='<div class="table-wrap"><table><thead><tr><th>Campaña</th><th>Alcance</th><th>Estado</th><th>Preparación</th><th>Avance</th><th>Acciones</th></tr></thead><tbody>';
        foreach($rows as $r){
            $ready=campaign_readiness((int)$r['id']);
            $pct=$r['sedes_count']?round($r['completed_count']/$r['sedes_count']*100):0;
            $manageable=campaign_manageable_by_current_user((int)$r['id']);
            $scope=json_decode((string)($r['scope_json']??''),true);if(!is_array($scope))$scope=[];
            $categories=json_decode((string)($r['asset_categories_json']??''),true);if(!is_array($categories))$categories=[];
            $categoryNames=array_map(static fn(string $category): string=>asset_category_label($category),array_map('strval',$categories));
            $coverage='<strong>'.e(match((string)($r['scope_type']??'nacional')){'nacional'=>'Nacional','departamental'=>'Departamental','municipal'=>'Municipal','sedes'=>'Sedes específicas',default=>'Territorial'}).'</strong><br><span class="muted">'.e(campaign_department_summary((int)$r['id'])).'</span><br><small>'.(int)$r['sedes_count'].' sedes · '.(int)$r['equipment_count'].' equipos'.($categoryNames?' · '.e(implode(', ',array_slice($categoryNames,0,3))).(count($categoryNames)>3?'…':''):'').'</small>';
            $preparation=$ready['apta']
                ?(($ready['contacto_pendiente']>0||$ready['sin_inventario']>0)
                    ?'<span class="badge text-bg-info">Publicable con datos pendientes</span><div class="small muted">Responsable o correo por confirmar al validar: '.$ready['contacto_pendiente'].' · Sedes sin inventario inicial: '.$ready['sin_inventario'].' · Solapados: '.$ready['equipos_solapados'].'</div>'
                    :'<span class="badge text-bg-success">Lista para operar</span>')
                :'<span class="badge text-bg-warning">Requiere ajustes</span><div class="small muted">Equipos solapados no permitidos: '.$ready['equipos_solapados'].'</div>';
            $primaryCampaignLink=$readOnly?route_url('seguimiento',['campaign_id'=>$r['id']]):route_url('dashboard',['campaign_id'=>$r['id']]);
            $primaryCampaignLabel=$readOnly?'Seguimiento':'Tablero';
            $content.='<tr><td><strong>'.e($r['name']).'</strong><br><span class="muted">'.e($r['start_date']?:'Sin inicio').' a '.e($r['end_date']?:'Sin cierre').'</span></td><td>'.$coverage.'</td><td>'.status_badge((string)$r['status']).'</td><td>'.$preparation.'</td><td>'.$r['completed_count'].' / '.$r['sedes_count'].'<div class="progress"><span class="'.progress_width_class($pct).'"></span></div><small>'.$pct.'%</small></td><td><div class="campaign-actions"><a class="btn btn-sm" href="'.e($primaryCampaignLink).'">'.e($primaryCampaignLabel).'</a> <a class="btn btn-sm btn-outline-secondary" href="'.e(route_url('equipos',['campaign_id'=>$r['id']])).'">Equipos</a> ';
            if($manageable&&$r['status']==='borrador'){
                $content.='<form class="d-inline" method="post" action="'.e(route_url('campania_accion')).'">'.csrf_field().'<input type="hidden" name="campaign_id" value="'.$r['id'].'"><button class="btn btn-sm btn-success" name="action" value="publish" '.(!$ready['apta']?'disabled':'').'>Publicar</button></form> '
                    .'<form class="d-inline" method="post" action="'.e(route_url('campania_accion')).'">'.csrf_field().'<input type="hidden" name="campaign_id" value="'.$r['id'].'"><button class="btn btn-sm btn-secondary" name="action" value="duplicate">Duplicar</button></form> '
                    .'<form class="d-inline" method="post" action="'.e(route_url('campania_accion')).'">'.csrf_field().'<input type="hidden" name="campaign_id" value="'.$r['id'].'"><button class="btn btn-sm btn-outline-danger" name="action" value="cancel" data-confirm="¿Cancelar esta campaña en borrador?">Cancelar</button></form>';
            }
            if($manageable&&$r['status']==='programada'){
                $content.='<form class="d-inline" method="post" action="'.e(route_url('campania_accion')).'">'.csrf_field().'<input type="hidden" name="campaign_id" value="'.$r['id'].'"><button class="btn btn-sm btn-success" name="action" value="activate">Activar ahora</button></form> ';
            }
            if($manageable&&in_array($r['status'],['programada','activa'],true)){
                $content.='<form class="d-inline" method="post" action="'.e(route_url('campania_accion')).'">'.csrf_field().'<input type="hidden" name="campaign_id" value="'.$r['id'].'"><button class="btn btn-sm btn-secondary" name="action" value="notify_pending">Notificar pendientes</button></form> ';
            }
            if($manageable&&$r['status']==='activa'){
                $content.='<form class="d-inline" method="post" action="'.e(route_url('campania_accion')).'">'.csrf_field().'<input type="hidden" name="campaign_id" value="'.$r['id'].'"><button class="btn btn-sm btn-outline-primary" name="action" value="close" data-confirm="¿Cerrar la recepción y pasar la campaña a revisión?">Cerrar recepción</button></form>';
            }
            if($manageable&&$r['status']==='en_revision'){
                $content.='<form class="d-inline" method="post" action="'.e(route_url('campania_accion')).'">'.csrf_field().'<input type="hidden" name="campaign_id" value="'.$r['id'].'"><button class="btn btn-sm btn-success" name="action" value="finalize" data-confirm="¿Finalizar definitivamente la campaña?">Finalizar</button></form>';
            }
            $content.='</div></td></tr>';
        }
        $content.='</tbody></table></div>';
    }
    $content.='</div>';
    render_page('Campañas',$content,['subtitle'=>$readOnly?'Consulta de campañas y avance dentro de su alcance departamental.':'Creación, publicación y seguimiento territorial con inventario congelado por campaña.']);
}

function campaign_create_page(): void
{
    Auth::requireRole(['admin_gi','superadmin']);
    if(!ImportQuality::campaignsAllowed()){render_error('Campañas bloqueadas','Corrija primero las inconsistencias críticas indicadas en el Centro de diagnóstico.');return;}
    $user=Auth::user();
    $scopeWhere="NULLIF(TRIM(s.cod_dd),'') IS NOT NULL";$scopeParams=[];
    if((string)$user['role']==='formador'){
        $allowed=array_values(array_filter(array_map('strval',$user['departments']??[])));
        if(!$allowed){render_error('Sin cobertura asignada','El perfil departamental no tiene departamentos asociados.');return;}
        $scopeWhere.=' AND s.cod_dd IN ('.implode(',',array_fill(0,count($allowed),'?')).')';$scopeParams=$allowed;
    }
    $departments=Database::fetchAll("SELECT s.cod_dd,MAX(s.departamento) departamento,COUNT(DISTINCT s.id) sedes_count,COUNT(DISTINCT CASE WHEN e.active=1 THEN e.id END) equipment_count FROM sedes s LEFT JOIN equipment e ON e.current_sede_id=s.id WHERE {$scopeWhere} GROUP BY s.cod_dd ORDER BY departamento",$scopeParams);
    $municipalities=Database::fetchAll("SELECT s.cod_dd,MAX(s.departamento) departamento,s.municipio,COUNT(DISTINCT s.id) sedes_count FROM sedes s WHERE {$scopeWhere} AND NULLIF(TRIM(s.municipio),'') IS NOT NULL GROUP BY s.cod_dd,s.municipio ORDER BY departamento,s.municipio",$scopeParams);
    $sedes=Database::fetchAll("SELECT s.id,s.identificador,s.cod_dd,s.departamento,s.municipio,s.tipo_sede,s.nombre_sede,(SELECT COUNT(*) FROM equipment e WHERE e.current_sede_id=s.id AND e.active=1) equipment_count FROM sedes s WHERE {$scopeWhere} ORDER BY s.departamento,s.municipio,s.tipo_sede,s.nombre_sede",$scopeParams);
    $sedeTypes=Database::fetchAll("SELECT DISTINCT s.tipo_sede FROM sedes s WHERE {$scopeWhere} AND NULLIF(TRIM(s.tipo_sede),'') IS NOT NULL ORDER BY s.tipo_sede",$scopeParams);
    if(!$departments||!$sedes){render_error('Sin datos disponibles','Complete la inicialización de sedes e inventario antes de crear una campaña.');return;}
    $allowedDepartmentCodes=array_map(static fn(array $r):string=>(string)$r['cod_dd'],$departments);
    $allowedSedeIds=array_map(static fn(array $r):int=>(int)$r['id'],$sedes);
    $allowedCategories=array_keys(asset_category_labels(true));

    if(request_method('POST')){
        verify_csrf();
        $name=trim((string)($_POST['name']??''));
        $startDate=trim((string)($_POST['start_date']??''));$endDate=trim((string)($_POST['end_date']??''));$cutoff=trim((string)($_POST['cutoff_date']??''));
        if((string)($_POST['campaign_confirmation']??'')!=='1'){flash('danger','Confirme que revisó la configuración de la campaña.');redirect('campania_crear');}
        $mode=(string)($_POST['scope_mode']??'nacional');
        $selectedCategories=$_POST['asset_categories']??[];if(!is_array($selectedCategories))$selectedCategories=[$selectedCategories];
        $selectedCategories=array_values(array_unique(array_intersect($allowedCategories,array_map('strval',$selectedCategories))));
        $selectedTypes=$_POST['sede_types']??[];if(!is_array($selectedTypes))$selectedTypes=[$selectedTypes];$selectedTypes=array_values(array_filter(array_map('strval',$selectedTypes)));
        if($name===''){flash('danger','El nombre de la campaña es obligatorio.');redirect('campania_crear');}
        if($startDate===''||$endDate===''){flash('danger','Defina las fechas de inicio y cierre.');redirect('campania_crear');}
        if($startDate>$endDate){flash('danger','La fecha inicial no puede ser posterior a la fecha límite.');redirect('campania_crear');}
        if(!$selectedCategories){flash('danger','Seleccione al menos un tipo de equipo.');redirect('campania_crear');}
        if(!in_array($mode,['nacional','departamental','municipal','sedes'],true))$mode='nacional';

        $where=$scopeWhere;$params=$scopeParams;$scopeSelection=[];
        if($mode==='departamental'){
            $selected=$_POST['department_codes']??[];if(!is_array($selected))$selected=[$selected];$selected=array_values(array_unique(array_intersect($allowedDepartmentCodes,array_map('strval',$selected))));
            if(!$selected){flash('danger','Seleccione al menos un departamento.');redirect('campania_crear');}
            $where.=' AND s.cod_dd IN ('.implode(',',array_fill(0,count($selected),'?')).')';$params=array_merge($params,$selected);$scopeSelection=['departments'=>$selected];
        }elseif($mode==='municipal'){
            $selected=$_POST['municipalities']??[];if(!is_array($selected))$selected=[$selected];
            $pairs=[];foreach($selected as $token){$parts=explode('|',(string)$token,2);if(count($parts)===2&&in_array($parts[0],$allowedDepartmentCodes,true)&&trim($parts[1])!=='')$pairs[]=[$parts[0],trim($parts[1])];}
            if(!$pairs){flash('danger','Seleccione al menos un municipio.');redirect('campania_crear');}
            $clauses=[];foreach($pairs as [$code,$municipality]){$clauses[]='(s.cod_dd=? AND s.municipio=?)';$params[]=$code;$params[]=$municipality;}
            $where.=' AND ('.implode(' OR ',$clauses).')';$scopeSelection=['municipalities'=>$pairs];
        }elseif($mode==='sedes'){
            $selected=$_POST['sede_ids']??[];if(!is_array($selected))$selected=[$selected];$selected=array_values(array_unique(array_intersect($allowedSedeIds,array_map('intval',$selected))));
            if(!$selected){flash('danger','Seleccione al menos una sede específica.');redirect('campania_crear');}
            $where.=' AND s.id IN ('.implode(',',array_fill(0,count($selected),'?')).')';$params=array_merge($params,$selected);$scopeSelection=['sedes'=>$selected];
        }else{$scopeSelection=['national'=>true];}
        if($selectedTypes){$where.=' AND s.tipo_sede IN ('.implode(',',array_fill(0,count($selectedTypes),'?')).')';$params=array_merge($params,$selectedTypes);$scopeSelection['sede_types']=$selectedTypes;}
        $scopeSedes=Database::fetchAll("SELECT s.id,s.cod_dd FROM sedes s WHERE {$where} ORDER BY s.id",$params);
        if(!$scopeSedes){flash('danger','La combinación seleccionada no contiene sedes.');redirect('campania_crear');}
        $sedeIds=array_map(static fn(array $r):int=>(int)$r['id'],$scopeSedes);
        $departmentCodes=array_values(array_unique(array_map(static fn(array $r):string=>(string)$r['cod_dd'],$scopeSedes)));
        $requiresEvidence=!empty($_POST['requires_evidence'])?1:0;$allowOverlap=!empty($_POST['allow_overlap'])?1:0;
        $pdo=Database::connection();
        try{
            $pdo->beginTransaction();
            Database::execute('INSERT INTO campaigns(name,cutoff_date,start_date,end_date,status,scope_type,instructions,scope_json,asset_categories_json,requires_evidence,allow_overlap,created_by) VALUES(?,?,?,?,\'borrador\',?,?,?,?,?,?,?)',[
                $name,$cutoff?:null,$startDate,$endDate,$mode,trim((string)($_POST['instructions']??'')),
                json_encode($scopeSelection,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),json_encode($selectedCategories,JSON_UNESCAPED_UNICODE),$requiresEvidence,$allowOverlap,Auth::id(),
            ]);
            $id=(int)$pdo->lastInsertId();
            foreach($departmentCodes as $code)Database::execute('INSERT IGNORE INTO campaign_departments(campaign_id,cod_dd) VALUES(?,?)',[$id,$code]);
            foreach($sedeIds as $sid)Database::execute('INSERT INTO campaign_sedes(campaign_id,sede_id) VALUES(?,?)',[$id,$sid]);
            $sedePlaceholders=implode(',',array_fill(0,count($sedeIds),'?'));$categoryPlaceholders=implode(',',array_fill(0,count($selectedCategories),'?'));
            Database::execute("INSERT INTO campaign_equipment(campaign_id,equipment_id,sede_id,asset_category) SELECT ?,e.id,e.current_sede_id,e.asset_category FROM equipment e WHERE e.active=1 AND e.current_sede_id IN ({$sedePlaceholders}) AND e.asset_category IN ({$categoryPlaceholders})",array_merge([$id],$sedeIds,$selectedCategories));
            $equipmentCount=campaign_equipment_count($id);if($equipmentCount===0)throw new RuntimeException('La selección no contiene equipos activos de las categorías elegidas.');
            $pdo->commit();
            $overlap=campaign_overlap_summary($id);
            audit('create_campaign_wizard','campaign',$id,null,['scope'=>$scopeSelection,'categories'=>$selectedCategories,'sedes'=>count($sedeIds),'equipos'=>$equipmentCount,'overlap'=>$overlap]);
            flash($overlap['equipos']>0&&!$allowOverlap?'warning':'success','Campaña creada en borrador con '.count($sedeIds).' sede(s) y '.$equipmentCount.' equipo(s).'.($overlap['equipos']>0?' Se detectaron '.$overlap['equipos'].' equipos en otras campañas operativas.':''));
            redirect('campanias');
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$reference=log_exception_reference($e,'campaign_create_019');flash('danger',safe_error_message($e->getMessage()?:'No fue posible crear la campaña',$reference));redirect('campania_crear');}
    }

    $departmentOptions='';foreach($departments as $r)$departmentOptions.='<label class="campaign-department-option"><input type="checkbox" name="department_codes[]" value="'.e($r['cod_dd']).'"><span><strong>'.e($r['departamento']).'</strong><small>'.e($r['sedes_count']).' sedes · '.e($r['equipment_count']).' equipos</small></span></label>';
    $municipalityOptions='';foreach($municipalities as $r)$municipalityOptions.='<option value="'.e($r['cod_dd'].'|'.$r['municipio']).'">'.e($r['departamento'].' / '.$r['municipio'].' · '.$r['sedes_count'].' sedes').'</option>';
    $sedeOptions='';foreach($sedes as $r)$sedeOptions.='<option value="'.(int)$r['id'].'">'.e($r['departamento'].' / '.$r['municipio'].' · '.$r['tipo_sede'].' · '.$r['identificador'].' · '.$r['nombre_sede'].' · '.$r['equipment_count'].' equipos').'</option>';
    $typeOptions='';foreach($sedeTypes as $r)$typeOptions.='<label class="form-check"><input class="form-check-input" type="checkbox" name="sede_types[]" value="'.e($r['tipo_sede']).'"><span class="form-check-label">'.e($r['tipo_sede']).'</span></label>';
    $categoryOptions='';foreach(asset_category_labels(true) as $value=>$label)$categoryOptions.='<label class="choice-card campaign-category-card"><input type="checkbox" name="asset_categories[]" value="'.e($value).'" '.(in_array($value,['cpu','portatil','pc_todo_en_uno'],true)?'checked':'').'><span class="choice-card-copy"><strong>'.e($label).'</strong><small>Incluir esta categoría en el inventario congelado de la campaña.</small></span><span class="choice-card-check">✓</span></label>';
    $content='<div class="card campaign-wizard"><div class="wizard-head"><div><div class="kicker">Asistente de campaña</div><h3>Crear campaña de verificación</h3><p>Defina información, alcance, activos y reglas antes de guardar el borrador.</p></div><strong data-campaign-wizard-progress>1 de 4</strong></div><div class="wizard-progress"><button type="button" class="wizard-progress-step is-active" data-campaign-go="1"><span>1</span><small>Información</small></button><i></i><button type="button" class="wizard-progress-step" data-campaign-go="2"><span>2</span><small>Cobertura</small></button><i></i><button type="button" class="wizard-progress-step" data-campaign-go="3"><span>3</span><small>Activos</small></button><i></i><button type="button" class="wizard-progress-step" data-campaign-go="4"><span>4</span><small>Revisión</small></button></div><form method="post" data-campaign-wizard data-dirty-guard><div class="wizard-error" data-campaign-error hidden></div><noscript><div class="alert alert-warning">JavaScript no está disponible. El formulario se muestra completo para que pueda configurar y crear la campaña en una sola página.</div></noscript>'.csrf_field()
        .'<section data-campaign-step="1"><div class="wizard-step-title"><span>1</span><div><strong>Información general</strong><small>Identifique la campaña y su vigencia.</small></div></div><div class="form-grid">'.field('name','Nombre de la campaña','','text',['required'=>true]).field('cutoff_date','Fecha de corte','','date').field('start_date','Fecha de inicio','','date',['required'=>true]).field('end_date','Fecha límite','','date',['required'=>true]).'<div class="field-wide">'.field('instructions','Instrucciones para las sedes','','textarea',['wide'=>true]).'</div></div><div class="wizard-navigation"><span>Paso 1 de 4</span><button type="button" class="btn" data-campaign-next>Continuar</button></div></section>'
        .'<section data-campaign-step="2"><div class="wizard-step-title"><span>2</span><div><strong>Cobertura territorial</strong><small>Puede trabajar a nivel nacional, departamental, municipal o por sedes específicas.</small></div></div><div class="choice-card-grid choice-card-grid-4"><label class="choice-card"><input type="radio" name="scope_mode" value="nacional" checked data-scope-mode><span class="choice-card-copy"><strong>Nacional</strong><small>Todas las sedes dentro de su cobertura.</small></span></label><label class="choice-card"><input type="radio" name="scope_mode" value="departamental" data-scope-mode><span class="choice-card-copy"><strong>Departamentos</strong><small>Uno o varios departamentos.</small></span></label><label class="choice-card"><input type="radio" name="scope_mode" value="municipal" data-scope-mode><span class="choice-card-copy"><strong>Municipios</strong><small>Municipios seleccionados.</small></span></label><label class="choice-card"><input type="radio" name="scope_mode" value="sedes" data-scope-mode><span class="choice-card-copy"><strong>Sedes específicas</strong><small>Oficinas puntuales.</small></span></label></div><div class="campaign-scope-panel" data-scope-panel="departamental" hidden><div class="campaign-department-grid">'.$departmentOptions.'</div></div><div class="campaign-scope-panel" data-scope-panel="municipal" hidden><label class="form-label">Municipios participantes</label><select class="form-select" name="municipalities[]" multiple size="12">'.$municipalityOptions.'</select><div class="form-text">Use Ctrl o Cmd para seleccionar varios.</div></div><div class="campaign-scope-panel" data-scope-panel="sedes" hidden><label class="form-label">Sedes participantes</label><select class="form-select" name="sede_ids[]" multiple size="14">'.$sedeOptions.'</select><div class="form-text">Use Ctrl o Cmd para seleccionar varias sedes.</div></div><div class="card-subtle mt-3"><strong>Filtro opcional por tipo de sede</strong><div class="campaign-type-grid mt-2">'.$typeOptions.'</div></div><div class="wizard-navigation"><button type="button" class="btn btn-secondary" data-campaign-back>Regresar</button><span>Paso 2 de 4</span><button type="button" class="btn" data-campaign-next>Continuar</button></div></section>'
        .'<section data-campaign-step="3"><div class="wizard-step-title"><span>3</span><div><strong>Activos y reglas</strong><small>Seleccione los tipos de equipo y las condiciones de operación.</small></div></div><fieldset class="choice-fieldset"><legend>Tipos de equipos incluidos</legend><div class="choice-card-grid">'.$categoryOptions.'</div></fieldset><div class="form-grid mt-3"><label class="confirmation-box"><input type="checkbox" name="requires_evidence" value="1"><span><strong>Solicitar evidencia fotográfica</strong><small>La campaña indicará que las fotos son requeridas durante la validación.</small></span></label><label class="confirmation-box"><input type="checkbox" name="allow_overlap" value="1"><span><strong>Permitir equipos en otra campaña operativa</strong><small>Use esta opción solo cuando exista una justificación administrativa.</small></span></label></div><div class="wizard-navigation"><button type="button" class="btn btn-secondary" data-campaign-back>Regresar</button><span>Paso 3 de 4</span><button type="button" class="btn" data-campaign-next>Continuar</button></div></section>'
        .'<section data-campaign-step="4"><div class="wizard-step-title"><span>4</span><div><strong>Revisión final</strong><small>Revise la configuración antes de crear el borrador.</small></div></div><div class="validation-summary"><div class="summary-grid"><span>Campaña</span><b data-campaign-summary="name">Pendiente</b><span>Vigencia</span><b data-campaign-summary="dates">Pendiente</b><span>Alcance</span><b data-campaign-summary="scope">Nacional</b><span>Categorías</span><b data-campaign-summary="categories">Pendiente</b><span>Evidencia</span><b data-campaign-summary="evidence">Opcional</b><span>Solapamientos</span><b data-campaign-summary="overlap">Bloqueados</b></div></div><label class="confirmation-box mt-3"><input type="checkbox" name="campaign_confirmation" value="1" required><span><strong>Confirmo que revisé la configuración.</strong><small>La campaña se creará como borrador y deberá superar los controles antes de publicarse.</small></span></label><div class="wizard-navigation wizard-navigation-final"><button type="button" class="btn btn-secondary" data-campaign-back>Regresar</button><span>Paso 4 de 4</span><button class="btn btn-success" type="submit">Crear campaña en borrador</button></div></section></form></div>';
    render_page('Crear campaña',$content,['subtitle'=>'Asistente de cobertura territorial, categorías y reglas operativas.']);
}

function campaign_action_page(): void
{
    if(!request_method('POST'))redirect('campanias');verify_csrf();$action=(string)($_POST['action']??'');$campaignId=(int)($_POST['campaign_id']??0);
    if($action==='submit_sede'){
        $sedeId=(int)($_POST['sede_id']??0);
        if((string)($_POST['closure_acceptance']??'')!=='1'){flash('danger','Debe aceptar la declaración de cierre para finalizar la sede.');redirect('equipos',['campaign_id'=>$campaignId,'sede_id'=>$sedeId]);}
        if(!campaign_accepts_responses($campaignId)){flash('danger','La campaña no está activa para recibir cierres de sede.');redirect('equipos',['campaign_id'=>$campaignId,'sede_id'=>$sedeId]);}
        if(!Scope::canAccessSede($sedeId)){render_error('Acceso denegado','No puede finalizar esta sede.');return;}
        if(!campaign_site_profile_complete($campaignId,$sedeId)){flash('danger','Antes de finalizar debe validar la información general, dirección y responsable de la sede.');redirect('campania_sede_contacto',['campaign_id'=>$campaignId,'sede_id'=>$sedeId]);}
        // Limpia borradores residuales de equipos ya confirmados y usa exactamente las mismas reglas que muestra la interfaz.
        OperationalExperience::clearResolvedDrafts($campaignId,$sedeId);
        $closureState=OperationalExperience::siteState($campaignId,$sedeId,(int)(Auth::id()??0));
        $qualityGate=SiteQualityGate::run($campaignId,$sedeId,Auth::id());
        if((int)$qualityGate['blocking_count']>0){
            flash('danger','El control de calidad detectó '.(int)$qualityGate['blocking_count'].' hallazgo(s) bloqueante(s). Corríjalos antes de finalizar la sede.');
            redirect('site_quality_gate',['campaign_id'=>$campaignId,'sede_id'=>$sedeId]);
        }
        if(empty($closureState['ready_to_close'])){
            $reasons=[];
            if((int)($closureState['pending']??0)>0)$reasons[]=(int)$closureState['pending'].' equipo(s) pendiente(s)';
            if((int)($closureState['drafts']??0)>0)$reasons[]=(int)$closureState['drafts'].' borrador(es) sin confirmar';
            if((int)($closureState['corrections']??0)>0)$reasons[]=(int)$closureState['corrections'].' corrección(es) pendiente(s)';
            if((int)($closureState['critical_incidents']??0)>0)$reasons[]=(int)$closureState['critical_incidents'].' novedad(es) de prioridad alta o crítica';
            $detail=$reasons?implode(', ',$reasons):'requisitos de cierre pendientes';
            flash('danger','No es posible finalizar la sede: '.$detail.'. Revise los elementos indicados e intente nuevamente.');
            redirect('equipos',['campaign_id'=>$campaignId,'sede_id'=>$sedeId,'focus'=>'closure']);
        }
        $notes=trim((string)($_POST['closure_notes']??''));$code=hash('sha256',$campaignId.'|'.$sedeId.'|'.Auth::id().'|'.microtime(true));
        Database::execute("UPDATE campaign_sedes SET status='cerrado',submitted_at=NOW(),closed_by=?,closed_at=NOW(),closure_notes=?,closure_code=?,acceptance_text='Declaro que la información registrada corresponde a la verificación física realizada en la sede.',acceptance_ip=?,acceptance_user_agent=?,accepted_at=NOW() WHERE campaign_id=? AND sede_id=?",[Auth::id(),$notes,$code,$_SERVER['REMOTE_ADDR']??null,substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500),$campaignId,$sedeId]);
        Database::execute('INSERT INTO campaign_status_history(campaign_id,sede_id,previous_status,new_status,changed_by,notes) VALUES(?,?,?,?,?,?)',[$campaignId,$sedeId,'en_diligenciamiento','cerrado',Auth::id(),$notes]);
        audit('close_site_validation','campaign_sede',$sedeId,null,['campaign_id'=>$campaignId,'closure_code'=>$code]);
        try{
            $contact=NotificationService::sedeContact($campaignId,$sedeId);
            $email=$contact?NotificationService::contactEmail($contact):'';
            $campaignRow=Database::fetchOne('SELECT name FROM campaigns WHERE id=?',[$campaignId])?:[];
            if($email!=='')NotificationService::sendTemplate('site_closed',$email,[
                'sede'=>(string)($contact['identificador']??'').' · '.(string)($contact['nombre_sede']??''),
                'campania'=>(string)($campaignRow['name']??'Campaña de inventario'),
                'numero_constancia'=>$code,
                'fecha'=>date('Y-m-d H:i:s'),
                'url_accion'=>NotificationService::appUrl('acta_sede',['campaign_id'=>$campaignId,'sede_id'=>$sedeId]),
            ],$campaignId,$sedeId);
        }catch(Throwable $mailError){log_exception_reference($mailError,'site_closure_notification');}
        flash('success','La sede fue finalizada y se generó la constancia de cierre.');redirect('acta_sede',['campaign_id'=>$campaignId,'sede_id'=>$sedeId]);
    }
    Auth::requireRole(['admin_gi','superadmin']);
    if(!campaign_manageable_by_current_user($campaignId)){render_error('Acceso denegado','La campaña contiene departamentos fuera de su cobertura.');return;}
    $campaign=Database::fetchOne('SELECT * FROM campaigns WHERE id=?',[$campaignId]);if(!$campaign){flash('danger','Campaña no encontrada.');redirect('campanias');}
    if($action==='publish'){
        if(!ImportQuality::campaignsAllowed()){flash('danger','No es posible publicar: existen inconsistencias críticas de calidad.');redirect(Auth::is('admin_gi')?'diagnostico':'campanias');}
        $ready=campaign_readiness($campaignId);if(!$ready['apta']){flash('danger','No es posible publicar: revise el inventario seleccionado y los equipos solapados con otras campañas.');redirect('campanias');}
        $newStatus=((string)$campaign['start_date']>date('Y-m-d'))?'programada':'activa';
        Database::execute('UPDATE campaigns SET status=?,published_at=NOW() WHERE id=?',[$newStatus,$campaignId]);
        audit('publish_campaign','campaign',$campaignId,$campaign,['status'=>$newStatus,'readiness'=>$ready]);
        flash('success',$newStatus==='programada'?'La campaña quedó programada. Los datos de responsable y correo pendientes se solicitarán al iniciar la validación de cada sede.':'La campaña fue activada. Los datos de responsable y correo pendientes se solicitarán al iniciar la validación de cada sede.');
    }elseif($action==='activate'){
        Database::execute("UPDATE campaigns SET status='activa',published_at=COALESCE(published_at,NOW()) WHERE id=?",[$campaignId]);audit('activate_campaign','campaign',$campaignId,$campaign,['status'=>'activa']);flash('success','La campaña quedó activa.');
    }elseif($action==='close'){
        Database::execute("UPDATE campaigns SET status='en_revision',closed_at=NOW() WHERE id=?",[$campaignId]);audit('close_campaign_reception','campaign',$campaignId,$campaign,['status'=>'en_revision']);flash('success','La recepción fue cerrada. La campaña pasó a revisión.');
    }elseif($action==='finalize'){
        $pending=(int)(Database::fetchOne("SELECT COUNT(*) total FROM campaign_sedes WHERE campaign_id=? AND status NOT IN ('cerrado','aprobado')",[$campaignId])['total']??0);
        if($pending>0){flash('danger','No puede finalizar: todavía existen '.$pending.' sedes sin cierre o aprobación.');redirect('campanias');}
        Database::execute("UPDATE campaigns SET status='finalizada',closed_at=COALESCE(closed_at,NOW()) WHERE id=?",[$campaignId]);audit('finalize_campaign','campaign',$campaignId,$campaign,['status'=>'finalizada']);flash('success','La campaña fue finalizada.');
    }elseif($action==='cancel'){
        Database::execute("UPDATE campaigns SET status='cancelada',cancelled_at=NOW() WHERE id=?",[$campaignId]);audit('cancel_campaign','campaign',$campaignId,$campaign,['status'=>'cancelada']);flash('warning','La campaña fue cancelada.');
    }elseif($action==='duplicate'){
        $pdo=Database::connection();try{$pdo->beginTransaction();Database::execute("INSERT INTO campaigns(name,cutoff_date,start_date,end_date,status,scope_type,instructions,scope_json,asset_categories_json,requires_evidence,allow_overlap,created_by) SELECT CONCAT(name,' · Copia'),cutoff_date,NULL,NULL,'borrador',scope_type,instructions,scope_json,asset_categories_json,requires_evidence,allow_overlap,? FROM campaigns WHERE id=?",[Auth::id(),$campaignId]);$newId=(int)$pdo->lastInsertId();Database::execute('INSERT INTO campaign_departments(campaign_id,cod_dd) SELECT ?,cod_dd FROM campaign_departments WHERE campaign_id=?',[$newId,$campaignId]);Database::execute('INSERT INTO campaign_sedes(campaign_id,sede_id) SELECT ?,sede_id FROM campaign_sedes WHERE campaign_id=?',[$newId,$campaignId]);Database::execute('INSERT INTO campaign_equipment(campaign_id,equipment_id,sede_id,asset_category) SELECT ?,equipment_id,sede_id,asset_category FROM campaign_equipment WHERE campaign_id=?',[$newId,$campaignId]);$pdo->commit();audit('duplicate_campaign','campaign',$newId,null,['source_campaign_id'=>$campaignId]);flash('success','Se creó una copia en borrador. Defina nuevas fechas antes de publicarla.');}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }elseif(in_array($action,['notify_pending','notify_all'],true)){
        if(!in_array((string)$campaign['status'],['programada','activa'],true)){flash('danger','Solo se notifican campañas programadas o activas.');redirect('campanias');}
        $extra=$action==='notify_pending'?" AND cs.notification_status NOT IN ('enviada','encolada')":'';
        $rows=Database::fetchAll("SELECT s.id sede_id,s.identificador,s.nombre_sede,s.email_contacto,s.email_institucional,cs.responsible_email,cs.responsible_name,(SELECT u.email FROM users u WHERE u.sede_id=s.id AND u.role='registrador' AND u.active=1 ORDER BY u.id LIMIT 1) user_email,(SELECT u.name FROM users u WHERE u.sede_id=s.id AND u.role='registrador' AND u.active=1 ORDER BY u.id LIMIT 1) user_name FROM campaign_sedes cs JOIN sedes s ON s.id=cs.sede_id WHERE cs.campaign_id=? {$extra}",[$campaignId]);
        $sent=0;$queued=0;$missing=0;$errors=0;
        foreach($rows as $r){
            $email=trim((string)($r['responsible_email']?:($r['email_contacto']?:($r['email_institucional']?:$r['user_email']))));
            if(!filter_var($email,FILTER_VALIDATE_EMAIL)){
                $missing++;
                Database::execute("UPDATE campaign_sedes SET notification_status='sin_correo',notification_error='El correo se solicitará al iniciar la validación de la sede' WHERE campaign_id=? AND sede_id=?",[$campaignId,$r['sede_id']]);
                continue;
            }
            $url=NotificationService::appUrl('equipos',['campaign_id'=>$campaignId,'sede_id'=>$r['sede_id']]);
            $ok=NotificationService::sendTemplate('campaign_published',$email,[
                'responsable_nombre'=>$r['responsible_name']?:($r['user_name']?:'Responsable de la sede'),
                'campania'=>$campaign['name'],
                'sede'=>$r['identificador'].' · '.$r['nombre_sede'],
                'fecha_limite'=>$campaign['end_date']?:'Sin definir',
                'url_accion'=>$url,
            ],$campaignId,(int)$r['sede_id']);
            if($ok){
                $mailStatus=Mailer::lastStatus();
                if($mailStatus==='encolado'){$queued++;$siteStatus='encolada';}else{$sent++;$siteStatus='enviada';}
                Database::execute("UPDATE campaign_sedes SET notified_at=IF(?='enviada',NOW(),notified_at),notification_email=?,notification_status=?,notification_error=NULL WHERE campaign_id=? AND sede_id=?",[$siteStatus,$email,$siteStatus,$campaignId,$r['sede_id']]);
            }else{
                $errors++;
                Database::execute("UPDATE campaign_sedes SET notification_email=?,notification_status='error',notification_error='Error del servicio de correo' WHERE campaign_id=? AND sede_id=?",[$email,$campaignId,$r['sede_id']]);
            }
        }
        audit('notify_campaign_sedes','campaign',$campaignId,null,['enviadas'=>$sent,'encoladas'=>$queued,'sin_correo'=>$missing,'errores'=>$errors]);flash($errors?'warning':'success',"Enviadas: {$sent}. En cola: {$queued}. Sin correo: {$missing}. Errores: {$errors}.");
    }
    redirect('campanias');
}


function internal_notifications_page(): void
{
    $userId=(int)Auth::id();
    if(request_method('POST')){verify_csrf();$id=(int)($_POST['id']??0);Database::execute('UPDATE internal_notifications SET read_at=NOW() WHERE id=? AND user_id=?',[$id,$userId]);redirect('notificaciones');}
    $rows=Database::fetchAll('SELECT n.*,c.name campaign_name,s.nombre_sede FROM internal_notifications n LEFT JOIN campaigns c ON c.id=n.campaign_id LEFT JOIN sedes s ON s.id=n.sede_id WHERE n.user_id=? ORDER BY n.created_at DESC LIMIT 100',[$userId]);
    $unread=(int)(Database::fetchOne('SELECT COUNT(*) total FROM internal_notifications WHERE user_id=? AND read_at IS NULL',[$userId])['total']??0);
    $content='<div class="card"><div class="toolbar"><div><div class="kicker">Centro de notificaciones</div><h3>'.$unread.' sin leer</h3></div></div>';
    if(!$rows){$content.=empty_state('Sin notificaciones','Aquí aparecerán campañas, correcciones, traslados y recordatorios.');}
    else{$content.='<div class="notification-list">';foreach($rows as $r){$content.='<article class="notification-item '.($r['read_at']?'':'notification-unread').'"><div><strong>'.e($r['title']).'</strong><p>'.e($r['message']).'</p><small>'.e($r['created_at']).($r['campaign_name']?' · '.e($r['campaign_name']):'').'</small></div>'.(!$r['read_at']?'<form method="post">'.csrf_field().'<input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn btn-sm">Marcar como leída</button></form>':'').'</article>';}$content.='</div>';}
    $content.='</div>';render_page('Notificaciones',$content,['subtitle'=>'Avisos internos y tareas pendientes.']);
}

function reopening_requests_page(): void
{
    $isAdmin=Auth::is('admin_gi')||Auth::is('superadmin')||Auth::is('formador');
    if(request_method('POST')){verify_csrf();$campaignId=(int)($_POST['campaign_id']??0);$sedeId=(int)($_POST['sede_id']??0);$reason=trim((string)($_POST['reason']??''));if(!$reason){flash('danger','La justificación es obligatoria.');redirect('reaperturas');}if(!Scope::canAccessSede($sedeId)){render_error('Acceso denegado','No puede solicitar la reapertura de esta sede.');return;}Database::execute("INSERT INTO reopening_requests(campaign_id,sede_id,requested_by,reason,status) VALUES (?,?,?,?,'pendiente')",[$campaignId,$sedeId,Auth::id(),$reason]);Database::execute("INSERT INTO internal_notifications(user_id,campaign_id,sede_id,title,message,notification_type) SELECT id,?,?,'Solicitud de reapertura',?,'reapertura' FROM users WHERE active=1 AND role IN ('admin_gi','superadmin')",[$campaignId,$sedeId,$reason]);audit('request_site_reopening','campaign_sede',$sedeId,null,['campaign_id'=>$campaignId,'reason'=>$reason]);flash('success','Solicitud de reapertura registrada.');redirect('reaperturas');}
    $where=$isAdmin?'1=1':'r.requested_by='.(int)Auth::id();
    $rows=Database::fetchAll("SELECT r.*,c.name campaign_name,s.identificador,s.nombre_sede,u.name requester FROM reopening_requests r JOIN campaigns c ON c.id=r.campaign_id JOIN sedes s ON s.id=r.sede_id JOIN users u ON u.id=r.requested_by WHERE {$where} ORDER BY r.id DESC LIMIT 100");
    [$closedWhere,$closedParams]=Scope::sedeCondition('s');
    $closed=Database::fetchAll(
        "SELECT cs.campaign_id,cs.sede_id,c.name campaign_name,"
        . "s.identificador,s.nombre_sede "
        . "FROM campaign_sedes cs "
        . "JOIN campaigns c ON c.id=cs.campaign_id "
        . "JOIN sedes s ON s.id=cs.sede_id "
        . "WHERE cs.status='cerrado' AND {$closedWhere} "
        . "ORDER BY cs.closed_at DESC LIMIT 100",
        $closedParams
    );
    $content='<div class="card"><h3>Solicitar reapertura</h3><form method="post">'.csrf_field().'<div class="form-grid"><label class="field"><span class="form-label">Sede cerrada</span><select class="form-select" name="selection" data-reopening-selection required><option value="">Seleccione</option>';foreach($closed as $c){$content.='<option value="'.(int)$c['campaign_id'].'|'.(int)$c['sede_id'].'">'.e($c['campaign_name'].' · '.$c['identificador'].' · '.$c['nombre_sede']).'</option>';}$content.='</select></label><input type="hidden" name="campaign_id"><input type="hidden" name="sede_id">'.field('reason','Justificación','','textarea',['required'=>true,'wide'=>true]).'</div><button class="btn">Enviar solicitud</button></form></div><div class="card"><h3>Solicitudes</h3><div class="table-wrap"><table><thead><tr><th>Campaña / sede</th><th>Solicitante</th><th>Justificación</th><th>Estado</th><th>Acción</th></tr></thead><tbody>';foreach($rows as $r){$content.='<tr><td>'.e($r['campaign_name'].' · '.$r['identificador'].' · '.$r['nombre_sede']).'</td><td>'.e($r['requester']).'</td><td>'.e($r['reason']).'</td><td>'.status_badge($r['status']).'</td><td>'.($isAdmin&&$r['status']==='pendiente'?'<form method="post" action="'.e(route_url('reapertura_accion')).'">'.csrf_field().'<input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn btn-sm" name="decision" value="aprobar">Aprobar</button> <button class="btn btn-sm btn-secondary" name="decision" value="rechazar">Rechazar</button></form>':'').'</td></tr>';}$content.='</tbody></table></div></div>';render_page('Reaperturas',$content,['subtitle'=>'Reapertura controlada de sedes cerradas.']);
}

function reopening_action_page(): void
{
    Auth::requireRole(['formador','admin_gi','superadmin']);
    if(!request_method('POST')) redirect('reaperturas');
    verify_csrf();
    $id=(int)($_POST['id']??0);
    $decision=(string)($_POST['decision']??'');
    $r=Database::fetchOne(
        'SELECT rr.*,c.name campaign_name,s.nombre_sede,s.identificador,u.email requester_email FROM reopening_requests rr JOIN campaigns c ON c.id=rr.campaign_id JOIN sedes s ON s.id=rr.sede_id JOIN users u ON u.id=rr.requested_by WHERE rr.id=?',
        [$id]
    );
    if(!$r){flash('danger','Solicitud no encontrada.');redirect('reaperturas');}
    if($decision==='aprobar'){
        Database::execute("UPDATE reopening_requests SET status='aprobada',reviewed_by=?,reviewed_at=NOW() WHERE id=?",[Auth::id(),$id]);
        Database::execute("UPDATE campaign_sedes SET status='en_diligenciamiento',reopened_at=NOW() WHERE campaign_id=? AND sede_id=?",[$r['campaign_id'],$r['sede_id']]);
        $msg='La solicitud de reapertura fue aprobada.';
        $result='aprobada';
    }else{
        Database::execute("UPDATE reopening_requests SET status='rechazada',reviewed_by=?,reviewed_at=NOW() WHERE id=?",[Auth::id(),$id]);
        $msg='La solicitud de reapertura fue rechazada.';
        $result='rechazada';
    }
    Database::execute("INSERT INTO internal_notifications(user_id,campaign_id,sede_id,title,message,notification_type) VALUES (?,?,?,?,?,'reapertura')",[$r['requested_by'],$r['campaign_id'],$r['sede_id'],'Resultado de reapertura',$msg]);
    try{
        $recipient=strtolower(trim((string)$r['requester_email']));
        if(filter_var($recipient,FILTER_VALIDATE_EMAIL)){
            NotificationService::sendTemplate('reopening_resolved',$recipient,[
                'sede'=>trim((string)$r['identificador'].' · '.(string)$r['nombre_sede']),
                'campania'=>(string)$r['campaign_name'],
                'resultado'=>$result,
                'url_accion'=>NotificationService::appUrl('reaperturas'),
            ],(int)$r['campaign_id'],(int)$r['sede_id']);
        }
    }catch(Throwable $mailError){log_exception_reference($mailError,'notification_reopening_resolved');}
    audit('review_site_reopening','reopening_request',$id,null,['decision'=>$decision]);
    flash('success',$msg);
    redirect('reaperturas');
}

function directory_page(): void
{
    Auth::requireRole(['admin_gi','superadmin']);
    if (request_method('POST')) {
        verify_csrf();
        if (empty($_FILES['directory_file']['tmp_name']) || !is_uploaded_file($_FILES['directory_file']['tmp_name'])) {flash('danger','Seleccione el archivo Excel del Directorio Institucional.');redirect('directorio');}
        $original=(string)($_FILES['directory_file']['name']??'Directorio-RNEC.xlsx');
        if(strtolower(pathinfo($original,PATHINFO_EXTENSION))!=='xlsx'){flash('danger','El archivo debe estar en formato .xlsx.');redirect('directorio');}
        try{
            UploadSecurity::validateXlsx((array)$_FILES['directory_file'], 50 * 1024 * 1024);
            $dir=dirname(__DIR__).'/storage/import-previews';if(!is_dir($dir))mkdir($dir,0770,true);
            $token=bin2hex(random_bytes(16));$saved=$dir.'/'.$token.'.xlsx';if(!move_uploaded_file($_FILES['directory_file']['tmp_name'],$saved))throw new RuntimeException('No fue posible guardar el archivo temporal.');
            $preview=DirectoryImporter::preview($saved,$original);$_SESSION['directory_preview_'.$token]=['path'=>$saved,'name'=>$original,'preview'=>$preview,'created_at'=>time()];
            redirect('directorio',['preview'=>$token]);
        }catch(Throwable $e){$reference=log_exception_reference($e,'directory_preview');flash('danger',safe_error_message($e->getMessage()?:'No fue posible analizar el Directorio',$reference));redirect('directorio');}
    }
    $previewToken=(string)($_GET['preview']??'');$previewData=$previewToken!==''?($_SESSION['directory_preview_'.$previewToken]??null):null;
    $stats=Database::fetchOne("SELECT COUNT(*) total,SUM(directorio_sincronizado_en IS NOT NULL) sincronizadas,SUM(directorio_sincronizado_en IS NULL) pendientes,SUM(NULLIF(TRIM(email_institucional),'') IS NULL) sin_correo FROM sedes");
    $imports=Database::fetchAll('SELECT di.*,u.name created_name FROM directory_imports di JOIN users u ON u.id=di.created_by ORDER BY di.id DESC LIMIT 15');
    $content='<div class="metrics">'.metric_card('Sedes',$stats['total']??0,'Maestro nacional').metric_card('Sincronizadas',$stats['sincronizadas']??0,'Con directorio oficial','green').metric_card('Pendientes',$stats['pendientes']??0,'Por revisar','orange').metric_card('Sin correo',$stats['sin_correo']??0,'Institucional','purple').'</div>';
    $content.='<div class="card"><div class="kicker">Proceso seguro en dos pasos</div><h3>Analizar Directorio Institucional RNEC</h3><p>Primero se genera una previsualización. Ningún dato se modifica hasta confirmar expresamente la aplicación.</p><form method="post" enctype="multipart/form-data">'.csrf_field().field('directory_file','Archivo Excel','','file',['required'=>true,'accept'=>'.xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']).'<button class="btn">1. Analizar archivo</button></form></div>';
    if($previewData){$p=$previewData['preview'];$content.='<div class="card"><h3>Previsualización</h3><div class="metrics">'.metric_card('Procesados',$p['processed'],'Filas válidas').metric_card('Nuevos',$p['created'],'Se crearán','green').metric_card('Actualizados',$p['updated'],'Cambios detectados','orange').metric_card('Sin cambios',$p['unchanged'],'Coinciden').metric_card('Errores',$p['invalid'],'Críticos','purple').metric_card('Duplicados',$p['duplicates'],'Dentro del archivo').'</div>';
        if(!empty($p['errors'])){$content.='<div class="alert alert-warning"><strong>Hallazgos:</strong><ul>';foreach(array_slice($p['errors'],0,20) as $e)$content.='<li>Fila '.e($e['fila']).': '.e($e['mensaje']).'</li>';$content.='</ul></div>';}
        if(!empty($p['changes'])){$content.='<div class="table-wrap"><table><thead><tr><th>Fila</th><th>Acción</th><th>Id sede</th><th>Sede</th><th>Ubicación</th><th>Campos</th></tr></thead><tbody>';foreach(array_slice($p['changes'],0,100) as $c)$content.='<tr><td>'.e($c['fila']).'</td><td>'.status_badge($c['accion']).'</td><td>'.e($c['id_sede']?:'Nuevo').'</td><td>'.e($c['sede']).'</td><td>'.e($c['ubicacion']).'</td><td>'.e(implode(', ',$c['campos']??[])).'</td></tr>';$content.='</tbody></table></div>';}
        $content.='<form method="post" action="'.e(route_url('directorio_accion')).'">'.csrf_field().'<input type="hidden" name="action" value="apply"><input type="hidden" name="token" value="'.e($previewToken).'"><button class="btn btn-success" data-confirm="¿Confirma aplicar los cambios al maestro de sedes?">2. Confirmar y aplicar</button> <a class="btn btn-secondary" href="'.e(route_url('directorio')).'">Cancelar</a></form></div>';}
    $content.='<div class="card"><h3>Historial y reversión</h3><div class="table-wrap"><table><thead><tr><th>Archivo</th><th>Fecha</th><th>Procesados</th><th>Nuevos</th><th>Actualizados</th><th>Estado</th><th>Acción</th></tr></thead><tbody>';foreach($imports as $r){$content.='<tr><td><strong>'.e($r['original_name']).'</strong><br><span class="muted">'.e($r['created_name']).'</span></td><td>'.e($r['created_at']).'</td><td>'.e($r['rows_processed']).'</td><td>'.e($r['rows_created']).'</td><td>'.e($r['rows_updated']).'</td><td>'.status_badge($r['status']).'</td><td>'.($r['status']==='completado'?'<form method="post" action="'.e(route_url('directorio_accion')).'">'.csrf_field().'<input type="hidden" name="action" value="rollback"><input type="hidden" name="import_id" value="'.(int)$r['id'].'"><button class="btn btn-sm btn-secondary" data-confirm="¿Revertir esta importación? Las sedes nuevas con equipos asociados no serán eliminadas.">Revertir</button></form>':'—').'</td></tr>';}$content.='</tbody></table></div></div>';
    render_page('Directorio Institucional',$content,['subtitle'=>'Previsualización, confirmación, catálogos normalizados y reversión controlada.']);
}

function directory_action_page(): void
{
    Auth::requireRole(['admin_gi','superadmin']);if(!request_method('POST'))redirect('directorio');verify_csrf();$action=(string)($_POST['action']??'');
    try{
        if($action==='apply'){$token=(string)($_POST['token']??'');$data=$_SESSION['directory_preview_'.$token]??null;if(!$data||!is_file($data['path']))throw new RuntimeException('La previsualización venció o no existe.');$result=DirectoryImporter::import($data['path'],$data['name'],(int)Auth::id());@unlink($data['path']);unset($_SESSION['directory_preview_'.$token]);audit('sync_institutional_directory','directory',$result['import_id']??null,null,$result);flash('success','Directorio aplicado: '.$result['created'].' sedes creadas y '.$result['updated'].' actualizadas.');}
        elseif($action==='rollback'){$id=(int)($_POST['import_id']??0);$result=DirectoryImporter::rollback($id);audit('rollback_institutional_directory','directory',$id,null,$result);flash('success','Importación revertida: '.$result['restored'].' sedes restauradas y '.$result['deleted'].' sedes nuevas eliminadas.');}
    }catch(Throwable $e){$ref=log_exception_reference($e,'directory_action');flash('danger',safe_error_message($e->getMessage()?:'No fue posible completar la acción',$ref));}
    redirect('directorio');
}


/** @param array<string,mixed> $result */
function additional_identity_conflict_html(array $result): string
{
    if (empty($result['has_conflicts']) || empty($result['conflicts']) || !is_array($result['conflicts'])) return '';
    $html = '<div class="additional-conflict-card" role="alert"><div class="additional-conflict-heading"><span aria-hidden="true">⚠</span><div><strong>Este elemento ya está registrado</strong><small>No puede crearse como equipo adicional hasta resolver la coincidencia.</small></div></div>';
    if (!empty($result['identity_split'])) {
        $html .= '<div class="additional-conflict-critical"><strong>Atención:</strong> el serial y la placa apuntan a registros diferentes. Verifique físicamente ambas etiquetas.</div>';
    }
    $html .= '<div class="additional-conflict-list">';
    foreach ($result['conflicts'] as $conflict) {
        if (!is_array($conflict)) continue;
        $location = trim((string)($conflict['sede_identificador'] ?? '') . ' · ' . (string)($conflict['sede_nombre'] ?? ''), ' ·');
        $territory = trim((string)($conflict['municipio'] ?? '') . ' / ' . (string)($conflict['departamento'] ?? ''), ' /');
        $html .= '<div class="additional-conflict-item"><div><span class="badge text-bg-danger">Coincidencia por ' . e($conflict['matched_by'] ?? 'identificador') . '</span> <span class="badge text-bg-secondary">' . e($conflict['source_label'] ?? 'Registro existente') . '</span></div>'
            . '<strong>' . e($conflict['name'] ?? 'Elemento registrado') . '</strong>'
            . '<span>' . e(($conflict['category_label'] ?? 'Otra categoría') . (($conflict['equipment_type'] ?? '') !== '' ? ' · ' . $conflict['equipment_type'] : '')) . '</span>'
            . '<span><b>Sede:</b> ' . e($location !== '' ? $location : 'Pendiente de asociación') . ($territory !== '' ? ' · ' . e($territory) : '') . '</span>'
            . '<span><b>Registro:</b> #' . e((string)($conflict['id'] ?? '')) . ' · <b>Placa:</b> ' . e($conflict['placa_rnec'] ?: 'Sin placa') . ' · <b>Serial:</b> ' . e($conflict['serial_number'] ?: 'Sin serial') . '</span>';
        if (!empty($conflict['category_mismatch'])) {
            $html .= '<div class="additional-category-mismatch"><strong>Categoría diferente:</strong> seleccionó ' . e($result['selected_category_label'] ?? '') . ', pero el elemento está registrado como ' . e($conflict['category_label'] ?? '') . '.</div>';
        }
        if (!empty($conflict['campaign_name'])) {
            $html .= '<span><b>Campaña:</b> ' . e($conflict['campaign_name']) . ' · Estado ' . e($conflict['status'] ?? '') . '</span>';
        }
        if (!empty($conflict['view_url'])) {
            $html .= '<a class="btn btn-sm btn-outline-primary" href="' . e($conflict['view_url']) . '">Ver equipo registrado</a>';
        }
        $html .= '</div>';
    }
    $html .= '<div class="additional-conflict-actions">'
        . '<button class="btn btn-sm btn-primary" type="button" data-additional-conflict-open>'
        . 'Ver ubicación del equipo</button></div>';
    return $html . '</div></div>';
}


/** @param array<string,mixed> $identityCheck */
function additional_duplicate_redirect(
    array $identityCheck,
    int $campaignId,
    int $sedeId,
    string $category
): never {
    $_SESSION['additional_form_data'] = $_POST;
    $_SESSION['additional_identity_conflicts'] = $identityCheck;

    $first = is_array($identityCheck['conflicts'][0] ?? null)
        ? $identityCheck['conflicts'][0]
        : [];
    $location = trim(
        (string)($first['sede_identificador'] ?? '')
        . ' · '
        . (string)($first['sede_nombre'] ?? ''),
        ' ·'
    );
    $territory = trim(
        (string)($first['municipio'] ?? '')
        . ' / '
        . (string)($first['departamento'] ?? ''),
        ' /'
    );
    $source = trim((string)($first['source_label'] ?? 'Registro existente'));
    $recordId = (int)($first['id'] ?? 0);
    $matchedBy = (string)($first['matched_by'] ?? 'serial o placa');
    $categoryNotice = !empty($first['category_mismatch'])
        ? ' La categoría seleccionada (' . asset_category_label($category)
            . ') no coincide con la registrada ('
            . (string)($first['category_label'] ?? '') . ').'
        : '';

    audit('duplicate_additional_equipment_blocked', 'additional_equipment', $recordId ?: null, null, [
        'matched_by'=>$matchedBy,
        'source'=>$first['source'] ?? null,
        'source_label'=>$source,
        'sede_id'=>$first['sede_id'] ?? null,
        'serial_number'=>$first['serial_number'] ?? null,
        'placa_rnec'=>$first['placa_rnec'] ?? null,
        'campaign_id'=>$campaignId,
        'reported_sede_id'=>$sedeId,
    ]);

    $message = 'El equipo ya está registrado en ' . $source
        . ($recordId > 0 ? ' con registro #' . $recordId : '')
        . ($location !== '' ? ', sede ' . $location : '')
        . ($territory !== '' ? ' (' . $territory . ')' : '')
        . '. Coincidencia por ' . $matchedBy . '.'
        . $categoryNotice;

    flash('warning', $message);
    redirect('adicionales', ['campaign_id'=>$campaignId,'sede_id'=>$sedeId]);
}


function additional_identity_check_page(): void
{
    $startedAt = microtime(true);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    try {
        $serial = trim((string)($_GET['serial_number'] ?? ''));
        $plateRaw = trim((string)($_GET['placa_rnec'] ?? ''));
        $category = trim((string)($_GET['asset_category'] ?? 'otro'));
        $plate = $plateRaw === '' ? null : normalize_placa_rnec($plateRaw);
        if ($plateRaw !== '' && $plate === null) {
            echo json_encode([
                'ok'=>false,
                'message'=>'La Placa RNEC no cumple la política vigente. '.placa_rnec_help(),
                'has_conflicts'=>false,
                'conflicts'=>[],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }
        /*
         * La comprobación previa no debe retener el bloqueo de sesión, porque
         * el usuario puede enviar el formulario mientras esta consulta termina.
         */
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        @set_time_limit(8);

        $result = AdditionalEquipmentIntegrity::checkFast(
            $serial,
            $plate,
            $category
        );
        $elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);
        header('X-SIVI-Validation-Time-Ms: ' . $elapsedMs);
        if ($elapsedMs >= 750) {
            error_log(
                'SIVI slow identity validation: '
                . $elapsedMs . 'ms; serial='
                . substr(SerialIntegrity::normalize($serial), 0, 6)
                . '***; plate='
                . substr((string)$plate, 0, 3)
                . '***'
            );
        }

        echo json_encode(
            ['ok'=>true,'elapsed_ms'=>$elapsedMs] + $result,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    } catch (Throwable $e) {
        $reference = log_exception_reference($e, 'additional_identity_check');
        http_response_code(500);
        echo json_encode([
            'ok'=>false,
            'message'=>'No fue posible comprobar el serial y la placa. Referencia: '.$reference.'.',
            'has_conflicts'=>false,
            'conflicts'=>[],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}


function additional_catalog_page(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, max-age=60');
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    try {
        $category = trim((string)($_GET['asset_category'] ?? ''));
        $manufacturer = trim((string)($_GET['manufacturer'] ?? ''));
        if (!array_key_exists($category, asset_category_labels(true))) {
            http_response_code(422);
            echo json_encode(['ok'=>false,'message'=>'Seleccione una categoría válida.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($manufacturer === '') {
            $rows = Database::fetchAll(
                "SELECT manufacturer FROM (\n"
                . " SELECT TRIM(manufacturer) manufacturer FROM equipment WHERE active=1 AND asset_category=? AND NULLIF(TRIM(manufacturer),'') IS NOT NULL\n"
                . " UNION ALL\n"
                . " SELECT TRIM(manufacturer) manufacturer FROM additional_equipment WHERE review_status<>'rechazado' AND asset_category=? AND NULLIF(TRIM(manufacturer),'') IS NOT NULL\n"
                . ") catalog GROUP BY LOWER(manufacturer),manufacturer ORDER BY manufacturer LIMIT 150",
                [$category,$category]
            );
            echo json_encode(['ok'=>true,'manufacturers'=>array_values(array_map(static fn(array $r): string => (string)$r['manufacturer'],$rows)),'models'=>[]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        $rows = Database::fetchAll(
            "SELECT model FROM (\n"
            . " SELECT TRIM(model) model FROM equipment WHERE active=1 AND asset_category=? AND LOWER(TRIM(manufacturer))=LOWER(TRIM(?)) AND NULLIF(TRIM(model),'') IS NOT NULL\n"
            . " UNION ALL\n"
            . " SELECT TRIM(model) model FROM additional_equipment WHERE review_status<>'rechazado' AND asset_category=? AND LOWER(TRIM(manufacturer))=LOWER(TRIM(?)) AND NULLIF(TRIM(model),'') IS NOT NULL\n"
            . ") catalog GROUP BY LOWER(model),model ORDER BY model LIMIT 250",
            [$category,$manufacturer,$category,$manufacturer]
        );
        echo json_encode(['ok'=>true,'manufacturers'=>[],'models'=>array_values(array_map(static fn(array $r): string => (string)$r['model'],$rows))], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        $reference = log_exception_reference($e, 'additional_catalog');
        http_response_code(500);
        echo json_encode(['ok'=>false,'message'=>'No fue posible cargar marcas y modelos. Referencia: '.$reference.'.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}


function additional_page(): void
{
    $campaignId = selected_campaign_id();
    if($campaignId<1){render_error('Campaña requerida','Seleccione una campaña activa para registrar equipos adicionales.');return;}
    $formDefaults = is_array($_SESSION['additional_form_data'] ?? null) ? $_SESSION['additional_form_data'] : [];
    $identityConflicts = is_array($_SESSION['additional_identity_conflicts'] ?? null) ? $_SESSION['additional_identity_conflicts'] : [];
    unset($_SESSION['additional_form_data'], $_SESSION['additional_identity_conflicts']);
    $requiresSelection = profile_requires_sede_selection();
    $selectedSedeId = Auth::is('registrador')
        ? (int)(Auth::user()['sede_id'] ?? 0)
        : (int)($_POST['sede_id'] ?? $_GET['sede_id'] ?? 0);
    if (Auth::is('registrador') && $selectedSedeId < 1) {
        render_error('Sede no asignada', 'Su usuario Registrador no tiene una sede asociada. Solicite la corrección al administrador.');
        return;
    }

    [$scopeWhere, $scopeParams] = Scope::sedeCondition('s');
    $sedes = Database::fetchAll(
        "SELECT s.id,s.identificador,s.cod_dd,s.departamento,s.municipio,s.tipo_sede,s.nombre_sede FROM sedes s WHERE {$scopeWhere} ORDER BY s.tipo_sede,s.departamento,s.municipio,s.nombre_sede",
        $scopeParams
    );
    if ($selectedSedeId > 0 && !Scope::canAccessSede($selectedSedeId)) {
        render_error('Acceso denegado', 'La sede seleccionada no pertenece a su alcance territorial.');
        return;
    }

    if (request_method('POST')) {
        verify_csrf();
        $sedeId = $selectedSedeId;
        $fail = static function(string $message) use ($campaignId,$sedeId): never {
            $_SESSION['additional_form_data'] = $_POST;
            flash('danger', $message);
            redirect('adicionales', ['campaign_id'=>$campaignId,'sede_id'=>$sedeId]);
        };
        if(!campaign_accepts_responses($campaignId)) $fail('La campaña no está activa para recibir equipos adicionales.');
        if ($sedeId < 1 || !Scope::canAccessSede($sedeId) || !Database::fetchOne('SELECT 1 ok FROM campaign_sedes WHERE campaign_id=? AND sede_id=?',[$campaignId,$sedeId])) {
            $fail('Seleccione primero el Departamento, Municipio, Tipo de sede y Sede.');
        }

        $category = trim((string)($_POST['asset_category'] ?? ''));
        $categoryRules = additional_equipment_category_rules();
        if (!isset($categoryRules[$category])) $fail('Seleccione una categoría válida.');
        $rule = $categoryRules[$category];

        $technicalDetails = trim((string)($_POST['technical_details'] ?? 'no'));
        if (!array_key_exists($technicalDetails, additional_equipment_technical_detail_choices())) {
            $fail('Indique si va a diligenciar las características técnicas del equipo.');
        }
        $technicalCatalogs = additional_equipment_technical_catalogs();
        $categoryCatalog = $technicalCatalogs[$category] ?? [];
        $technicalValues = [];
        foreach ((array)($rule['technical'] ?? []) as $technicalField) {
            $value = $technicalDetails === 'si' ? trim((string)($_POST[$technicalField] ?? '')) : '';
            $choices = (array)($categoryCatalog[$technicalField] ?? []);
            if ($value !== '' && !array_key_exists($value, $choices)) {
                $fail('Seleccione una opción válida para ' . str_replace('_', ' ', $technicalField) . '.');
            }
            if ($technicalDetails === 'si' && in_array($technicalField, (array)($rule['technical_required'] ?? []), true) && $value === '') {
                $labels = ['equipment_type'=>'Tipo','os_name'=>'Sistema operativo','os_version'=>'Versión del sistema operativo','processor'=>'Procesador','memory'=>'Memoria RAM','screen_size'=>'Tamaño','connection_type'=>'Tipo de conexión','print_technology'=>'Tecnología de impresión'];
                $fail('Seleccione ' . strtolower((string)($labels[$technicalField] ?? $technicalField)) . ' para la categoría elegida.');
            }
            $technicalValues[$technicalField] = $value;
        }

        $ownershipType = trim((string)($_POST['ownership_type'] ?? ''));
        $allowedOwnership = array_filter(array_keys(additional_equipment_ownership_choices()), static fn(string $v): bool => $v !== '');
        if (!in_array($ownershipType, $allowedOwnership, true)) $fail('Seleccione cuál es el tipo de propiedad del equipo.');

        $equipmentState = trim((string)($_POST['equipment_state'] ?? ''));
        $allowedStates = array_filter(array_keys(additional_equipment_state_choices()), static fn(string $v): bool => $v !== '');
        if (!in_array($equipmentState, $allowedStates, true)) $fail('Seleccione cuál es el estado actual del equipo.');

        $additionalSerial = trim((string)($_POST['serial_number'] ?? ''));
        $serialConfirmation = trim((string)($_POST['serial_confirmation'] ?? ''));
        if ($additionalSerial === '') $fail('Registre el número de serie del equipo.');
        // La confirmación se conserva como apoyo opcional. Si el usuario la diligencia, debe coincidir.
        if ($serialConfirmation !== '' && SerialIntegrity::normalize($additionalSerial) !== SerialIntegrity::normalize($serialConfirmation)) {
            $fail('La confirmación del serial no coincide. Revise físicamente la etiqueta y vuelva a escribir ambos valores.');
        }

        $additionalPlateRaw = trim((string)($_POST['placa_rnec'] ?? ''));
        $plateConfirmationRaw = trim((string)($_POST['placa_confirmation'] ?? ''));
        if ($additionalPlateRaw === '') $fail('Registre la Placa RNEC del equipo.');
        $additionalPlate = normalize_placa_rnec($additionalPlateRaw);
        $plateConfirmation = $plateConfirmationRaw === '' ? null : normalize_placa_rnec($plateConfirmationRaw);
        if ($additionalPlate === null) $fail(placa_rnec_help());
        if ($plateConfirmationRaw !== '' && $plateConfirmation === null) $fail('La confirmación no cumple la política vigente. '.placa_rnec_help());
        // La confirmación se conserva como apoyo opcional. Si se diligencia, debe coincidir.
        if ($plateConfirmation !== null && $additionalPlate !== $plateConfirmation) {
            $fail('La confirmación de la Placa RNEC no coincide. Revise la etiqueta patrimonial y vuelva a escribir ambos valores.');
        }

        $manufacturer = trim((string)($_POST['manufacturer'] ?? ''));
        $model = trim((string)($_POST['model'] ?? ''));
        $physicalLocation = trim((string)($_POST['physical_location'] ?? ''));
        $additionalImagesMode = AppSettings::additionalEquipmentImagesMode();
        if ($additionalImagesMode === 'required' && additional_equipment_upload_count() < 1) {
            $fail('La configuración administrativa exige adjuntar al menos una imagen del equipo adicional.');
        }

        /*
         * Primera validación: ofrece una respuesta inmediata cuando el elemento
         * ya se encuentra en el inventario o fue reportado anteriormente.
         */
        $identityCheck = AdditionalEquipmentIntegrity::check(
            $additionalSerial,
            $additionalPlate,
            $category
        );
        if (!empty($identityCheck['has_conflicts'])) {
            additional_duplicate_redirect($identityCheck, $campaignId, $sedeId, $category);
        }

        /*
         * Segunda validación protegida por un bloqueo de MySQL. Esto evita que
         * dos usuarios registren simultáneamente el mismo serial o la misma placa.
         */
        $pdo = Database::connection();
        $identityLock = AdditionalEquipmentIntegrity::lockName(
            $additionalSerial,
            $additionalPlate
        );
        $lockAcquired = false;
        $storedImages = [];
        $createdRecord = null;
        $duplicateAfterLock = null;
        $errorReference = null;

        try {
            $lockRow = Database::fetchOne(
                'SELECT GET_LOCK(?,10) acquired',
                [$identityLock]
            );
            $lockAcquired = (int)($lockRow['acquired'] ?? 0) === 1;
            if (!$lockAcquired) {
                throw new RuntimeException(
                    'No fue posible reservar la validación del equipo. Intente nuevamente.'
                );
            }

            $duplicateAfterLock = AdditionalEquipmentIntegrity::check(
                $additionalSerial,
                $additionalPlate,
                $category
            );

            if (empty($duplicateAfterLock['has_conflicts'])) {
                $automaticName = trim($manufacturer . ' ' . $model);
                if ($automaticName === '') {
                    $automaticName = asset_category_label($category)
                        . ' · '
                        . $additionalSerial;
                }

                $pdo->beginTransaction();
                Database::execute(
                    'INSERT INTO additional_equipment '
                    . '(campaign_id,sede_id,equipment_type,asset_category,ownership_type,'
                    . 'name,manufacturer,model,screen_size,connection_type,print_technology,'
                    . 'serial_number,placa_rnec,os_name,os_version,processor,memory,'
                    . 'assigned_user,equipment_state,physical_location,notes,created_by) '
                    . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [
                        $campaignId,
                        $sedeId,
                        (string)($technicalValues['equipment_type'] ?? ''),
                        $category,
                        $ownershipType,
                        $automaticName,
                        $manufacturer,
                        $model,
                        (string)($technicalValues['screen_size'] ?? ''),
                        (string)($technicalValues['connection_type'] ?? ''),
                        (string)($technicalValues['print_technology'] ?? ''),
                        $additionalSerial,
                        $additionalPlate,
                        (string)($technicalValues['os_name'] ?? ''),
                        (string)($technicalValues['os_version'] ?? ''),
                        (string)($technicalValues['processor'] ?? ''),
                        (string)($technicalValues['memory'] ?? ''),
                        '',
                        $equipmentState,
                        '',
                        '',
                        Auth::id(),
                    ]
                );

                $id = (int)$pdo->lastInsertId();
                if ($id < 1) {
                    throw new RuntimeException(
                        'La base de datos no devolvió el identificador del equipo creado.'
                    );
                }

                if ($additionalImagesMode !== 'none') {
                    $storedImages = process_additional_equipment_images();
                    if ($storedImages) {
                        Database::execute(
                            'UPDATE additional_equipment SET evidence_path=? WHERE id=?',
                            [
                                json_encode(
                                    $storedImages,
                                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                                ),
                                $id,
                            ]
                        );
                    }
                }

                /*
                 * Verificar el registro dentro de la misma transacción antes de
                 * confirmar. Si no aparece o sus identificadores no coinciden,
                 * se revierte la operación.
                 */
                $createdRecord = Database::fetchOne(
                    'SELECT ae.id,ae.campaign_id,ae.sede_id,ae.asset_category,'
                    . 'ae.serial_number,ae.placa_rnec,ae.review_status,'
                    . 's.identificador,s.nombre_sede,s.municipio,s.departamento '
                    . 'FROM additional_equipment ae '
                    . 'JOIN sedes s ON s.id=ae.sede_id '
                    . 'WHERE ae.id=? LIMIT 1',
                    [$id]
                );

                if (!$createdRecord) {
                    throw new RuntimeException(
                        'El equipo no pudo verificarse después del guardado.'
                    );
                }
                if (
                    (int)$createdRecord['campaign_id'] !== $campaignId
                    || (int)$createdRecord['sede_id'] !== $sedeId
                    || SerialIntegrity::normalize(
                        (string)$createdRecord['serial_number']
                    ) !== SerialIntegrity::normalize($additionalSerial)
                    || normalize_placa_rnec(
                        (string)$createdRecord['placa_rnec']
                    ) !== $additionalPlate
                ) {
                    throw new RuntimeException(
                        'La información guardada no coincide con el equipo reportado.'
                    );
                }

                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            foreach ($storedImages as $relativePath) {
                $absolutePath = dirname(__DIR__) . '/' . ltrim(
                    (string)$relativePath,
                    '/'
                );
                if (is_file($absolutePath)) {
                    @unlink($absolutePath);
                }
            }
            $errorReference = log_exception_reference(
                $e,
                'additional_equipment_create'
            );
        } finally {
            if ($lockAcquired) {
                try {
                    Database::fetchOne(
                        'SELECT RELEASE_LOCK(?) released',
                        [$identityLock]
                    );
                } catch (Throwable $releaseError) {
                    log_exception_reference(
                        $releaseError,
                        'additional_equipment_release_lock'
                    );
                }
            }
        }

        if (!empty($duplicateAfterLock['has_conflicts'])) {
            additional_duplicate_redirect(
                $duplicateAfterLock,
                $campaignId,
                $sedeId,
                $category
            );
        }

        if ($errorReference !== null || !$createdRecord) {
            $fail(
                'No fue posible guardar el equipo adicional.'
                . ($errorReference !== null
                    ? ' Referencia: ' . $errorReference . '.'
                    : '')
            );
        }

        $id = (int)$createdRecord['id'];
        audit('create_additional_equipment', 'additional_equipment', $id, null, [
            'asset_category'=>$category,
            'ownership_type'=>$ownershipType,
            'equipment_state'=>$equipmentState,
            'sede_id'=>$sedeId,
            'manufacturer'=>$manufacturer,
            'model'=>$model,
            'technical_details'=>$technicalDetails,
            'images_mode'=>$additionalImagesMode,
            'images_count'=>count($storedImages),
            'persistence_verified'=>true,
        ]);

        $savedLocation = trim(
            (string)$createdRecord['identificador']
            . ' · '
            . (string)$createdRecord['nombre_sede'],
            ' ·'
        );
        flash(
            'success',
            'El equipo adicional fue registrado correctamente con el número #'
            . $id
            . ($savedLocation !== '' ? ' en ' . $savedLocation : '')
            . ' y quedó pendiente de revisión.'
        );
        redirect('adicionales', ['campaign_id'=>$campaignId,'sede_id'=>$sedeId]);
    }

    $rows = Database::fetchAll(
        "SELECT ae.*,s.identificador,s.nombre_sede,s.departamento,s.municipio,u.name created_name FROM additional_equipment ae JOIN sedes s ON s.id=ae.sede_id JOIN users u ON u.id=ae.created_by WHERE ae.campaign_id=? AND {$scopeWhere} ORDER BY ae.created_at DESC",
        array_merge([$campaignId], $scopeParams)
    );

    $plateTotal=placa_rnec_total_characters();
    $plateExample=PlatePolicy::example($plateTotal);
    $platePattern=placa_rnec_pattern();
    $default = static fn(string $key, string $fallback=''): string => trim((string)($formDefaults[$key] ?? $fallback));
    $conflictHtml = additional_identity_conflict_html($identityConflicts);
    $categoryRulesJson = json_encode(additional_equipment_category_rules(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    $technicalCatalogsJson = json_encode(additional_equipment_technical_catalogs(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    $additionalImagesMode = AppSettings::additionalEquipmentImagesMode();
    $technicalDetailsEnabled = $default('technical_details','no') === 'si';
    $wrap = static fn(string $key, string $html): string => '<div data-additional-field="'.e($key).'">'.$html.'</div>';
    $conflictModal = '<div class="modal fade" id="additionalIdentityConflictModal" tabindex="-1" aria-labelledby="additionalIdentityConflictTitle" aria-hidden="true" data-additional-conflict-modal data-auto-show="' . ($conflictHtml !== '' ? '1' : '0') . '">'
        . '<div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">'
        . '<div class="modal-content additional-conflict-modal-content">'
        . '<div class="modal-header">'
        . '<div><div class="kicker">Control de duplicidad</div><h5 class="modal-title" id="additionalIdentityConflictTitle">Equipo ya registrado</h5></div>'
        . '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>'
        . '</div>'
        . '<div class="modal-body" data-additional-conflict-modal-body>' . $conflictHtml . '</div>'
        . '<div class="modal-footer">'
        . '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>'
        . '</div></div></div></div>';

    $form = '<form method="post" enctype="multipart/form-data" data-no-submit-lock="1" data-additional-equipment-form data-mobile-scan-form data-images-mode="' . e($additionalImagesMode) . '" data-identity-check-url="' . e(route_url('additional_identity_check')) . '" data-catalog-url="' . e(route_url('additional_catalog')) . '" data-category-rules="' . e($categoryRulesJson) . '" data-technical-catalogs="' . e($technicalCatalogsJson) . '">' . csrf_field()
        . '<input type="hidden" name="campaign_id" value="' . e($campaignId) . '">'
        . '<input type="hidden" name="sede_id" value="' . e($selectedSedeId) . '" data-sede-hidden>'
        . '<div class="additional-identity-status" data-additional-identity-status' . ($conflictHtml === '' ? ' hidden' : '') . '>' . $conflictHtml . '</div>'
        . '<noscript><div class="alert alert-warning">Active JavaScript para desplegar las características técnicas según la categoría.</div></noscript>'
        . '<div class="additional-category-guide" data-additional-category-guide role="status" aria-live="polite"></div>'
        . mobile_scan_connection_panel($campaignId, $selectedSedeId)
        . '<section class="additional-basic-section">'
        . '<div class="wizard-step-title"><span>1</span><div><strong>Información básica del equipo</strong><small>Complete los campos obligatorios para habilitar el registro.</small></div></div>'
        . '<div class="form-grid additional-equipment-grid">'
        . $wrap('asset_category', field('asset_category', 'Categoría del equipo', $default('asset_category',''), 'select', ['required'=>true,'choices'=>[''=>'Seleccione una categoría'] + asset_category_choices(false, true),'attributes'=>['data-additional-category'=>'1']]))
        . $wrap('ownership_type', field('ownership_type', '¿Cuál es el tipo de propiedad?', $default('ownership_type'), 'select', ['required'=>true,'choices'=>additional_equipment_ownership_choices(),'attributes'=>['data-additional-ownership'=>'1']]))
        . $wrap('equipment_state', field('equipment_state', '¿Cuál es el estado actual del equipo?', $default('equipment_state'), 'select', ['required'=>true,'choices'=>additional_equipment_state_choices(),'attributes'=>['data-additional-state'=>'1']]))
        . $wrap('manufacturer', field('manufacturer', 'Marca', $default('manufacturer'), 'text', ['help'=>'Opcional. Seleccione una marca existente o escriba una nueva.','attributes'=>['list'=>'additional-brand-options','autocomplete'=>'off','data-additional-manufacturer'=>'1']]))
        . '<datalist id="additional-brand-options" data-additional-brand-options></datalist>'
        . $wrap('model', field('model', 'Modelo', $default('model'), 'text', ['help'=>'Opcional. La lista se actualiza según la categoría y la marca.','attributes'=>['list'=>'additional-model-options','autocomplete'=>'off','data-additional-model'=>'1']]))
        . '<datalist id="additional-model-options" data-additional-model-options></datalist>'
        . $wrap('serial_number', field('serial_number', 'Número de serie', $default('serial_number'), 'text', ['required'=>true,'help'=>'Escríbalo exactamente como aparece en la etiqueta.','attributes'=>['autocomplete'=>'off','data-additional-serial'=>'1','data-mobile-scan-target'=>'serial_number']]))
        . $wrap('placa_rnec', field('placa_rnec', 'Placa RNEC', $default('placa_rnec'), 'text', ['required'=>true,'placeholder'=>'Inicie con 000; el guion se agrega automáticamente','help'=>placa_rnec_help(),'attributes'=>['autocomplete'=>'off','data-placa-rnec'=>'1','data-sivi-plate-input'=>'1','data-plate-total-characters'=>(string)$plateTotal,'data-additional-plate'=>'1','data-mobile-scan-target'=>'placa_rnec']]))
        . '</div></section>'
        . ($additionalImagesMode === 'none' ? '' : '<section class="additional-images-card" data-additional-images-section><div class="wizard-step-title"><span>📷</span><div><strong>Imágenes del equipo adicional'.($additionalImagesMode==='required'?' <span class="text-danger">*</span>':'').'</strong><small>'.($additionalImagesMode==='required'?'Adjunte al menos una imagen para poder registrar el equipo.':'Las imágenes son opcionales y facilitan la revisión administrativa.').'</small></div></div><div class="evidence-grid intuitive-evidence-grid"><label class="evidence-upload-card"><span class="evidence-upload-icon">📷</span><strong>Foto general</strong><small>Muestre el equipo completo.</small><input type="file" name="additional_image_general" accept="image/jpeg,image/png,image/webp" capture="environment" data-file-preview><span class="evidence-file-name" data-file-name>Ningún archivo seleccionado</span></label><label class="evidence-upload-card"><span class="evidence-upload-icon">🏷️</span><strong>Foto de la placa</strong><small>Permita leer todos los números.</small><input type="file" name="additional_image_placa" accept="image/jpeg,image/png,image/webp" capture="environment" data-file-preview><span class="evidence-file-name" data-file-name>Ningún archivo seleccionado</span></label><label class="evidence-upload-card"><span class="evidence-upload-icon">🔎</span><strong>Foto del serial</strong><small>Enfoque la etiqueta del fabricante.</small><input type="file" name="additional_image_serial" accept="image/jpeg,image/png,image/webp" capture="environment" data-file-preview><span class="evidence-file-name" data-file-name>Ningún archivo seleccionado</span></label></div><p class="muted small">Máximo 8 MB por imagen.</p></section>')
        . $wrap('technical_details', '<section class="additional-technical-toggle"><input type="hidden" name="technical_details" value="no"><label class="settings-switch"><input type="checkbox" name="technical_details" value="si" data-additional-technical-choice'.($technicalDetailsEnabled?' checked':'').'><span><strong>¿Va a diligenciar las características técnicas?</strong><small>Por defecto está en NO. Active el switch para desplegar los campos técnicos al final.</small></span></label></section>')
        . '<section class="additional-technical-panel" data-additional-technical-panel'.($technicalDetailsEnabled?'':' hidden').'>'
        . '<div class="wizard-step-title"><span>2</span><div><strong>Características técnicas</strong><small>Se muestran únicamente los campos aplicables a la categoría seleccionada.</small></div></div>'
        . '<div class="form-grid additional-equipment-grid">'
        . $wrap('equipment_type', field('equipment_type', 'Tipo, capacidad o referencia técnica', $default('equipment_type'), 'select', ['choices'=>[''=>'Seleccione una opción'],'attributes'=>['data-additional-technical-select'=>'equipment_type','data-initial-value'=>$default('equipment_type')]]))
        . $wrap('screen_size', field('screen_size', 'Tamaño / diagonal', $default('screen_size'), 'select', ['choices'=>[''=>'Seleccione una opción'],'attributes'=>['data-additional-technical-select'=>'screen_size','data-initial-value'=>$default('screen_size')]]))
        . $wrap('connection_type', field('connection_type', 'Tipo de conexión', $default('connection_type'), 'select', ['choices'=>[''=>'Seleccione una opción'],'attributes'=>['data-additional-technical-select'=>'connection_type','data-initial-value'=>$default('connection_type')]]))
        . $wrap('print_technology', field('print_technology', 'Tecnología de impresión', $default('print_technology'), 'select', ['choices'=>[''=>'Seleccione una opción'],'attributes'=>['data-additional-technical-select'=>'print_technology','data-initial-value'=>$default('print_technology')]]))
        . $wrap('os_name', field('os_name', 'Sistema operativo', $default('os_name'), 'select', ['choices'=>[''=>'Seleccione una opción'],'attributes'=>['data-additional-technical-select'=>'os_name','data-initial-value'=>$default('os_name')]]))
        . $wrap('os_version', field('os_version', 'Versión del sistema operativo', $default('os_version'), 'select', ['choices'=>[''=>'Seleccione una opción'],'attributes'=>['data-additional-technical-select'=>'os_version','data-initial-value'=>$default('os_version')]]))
        . $wrap('processor', field('processor', 'Procesador', $default('processor'), 'select', ['choices'=>[''=>'Seleccione una opción'],'attributes'=>['data-additional-technical-select'=>'processor','data-initial-value'=>$default('processor')]]))
        . $wrap('memory', field('memory', 'Memoria RAM', $default('memory'), 'select', ['choices'=>[''=>'Seleccione una opción'],'attributes'=>['data-additional-technical-select'=>'memory','data-initial-value'=>$default('memory')]]))
        . '</div></section>'
        . '<div class="additional-form-summary" data-additional-form-summary aria-live="polite"></div>'
        . '<div class="form-actions"><button class="btn btn-success" type="submit" data-additional-submit>Registrar equipo adicional</button></div></form>'
        . $conflictModal;

    $table = '<div class="card"><h3>Equipos adicionales reportados en la sede</h3>';
    if (!$rows) {
        $table .= empty_state('Sin equipos adicionales', 'Aún no se han reportado elementos fuera del inventario base.');
    } else {
        $table .= '<div class="table-wrap"><table><thead><tr><th>Equipo</th><th>Identificación</th><th>Propiedad / Estado</th><th>Características</th><th>Sede</th><th>Revisión</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $categoryLabel = asset_category_label((string)$row['asset_category']);
            if ($row['asset_category'] === 'monitor') {
                $features = 'Tamaño: ' . e($row['screen_size'] ?: 'No reportado') . '<br><span class="muted">Conexión: ' . e($row['connection_type'] ?: 'No reportada') . '</span>';
            } elseif ($row['asset_category'] === 'impresora') {
                $features = 'Tecnología: ' . e($row['print_technology'] ?: 'No reportada') . '<br><span class="muted">Conexión: ' . e($row['connection_type'] ?: 'No reportada') . '</span>';
            } elseif (is_computer_category((string)$row['asset_category'])) {
                $features = e($row['os_name'] ?: 'SO no reportado') . ' ' . e($row['os_version']) . '<br><span class="muted">' . e(trim($row['processor'] . ' · ' . $row['memory'], ' ·')) . '</span>';
            } else {
                $features = e($row['equipment_type'] ?: 'Sin descripción técnica');
            }
            $table .= '<tr data-sede-row="' . (int)$row['sede_id'] . '"><td><strong>' . e($row['name'] ?: $categoryLabel) . '</strong><br><span class="muted">' . e($categoryLabel . ' · ' . trim($row['manufacturer'] . ' ' . $row['model'])) . '</span></td>'
                . '<td><strong>Serial:</strong> ' . e($row['serial_number'] ?: 'Pendiente') . '<br><strong>Placa:</strong> ' . e($row['placa_rnec'] ?: 'No aplica / pendiente') . '</td>'
                . '<td>' . status_badge((string)($row['ownership_type'] ?? 'desconocido')) . '<br>' . status_badge((string)($row['equipment_state'] ?? 'pendiente')) . '</td>'
                . '<td>' . $features . '</td><td>' . e($row['identificador'] . ' · ' . $row['nombre_sede']) . '<br><span class="muted">' . e($row['municipio'] . ' / ' . $row['departamento']) . '</span></td>'
                . '<td>' . status_badge($row['review_status']) . '<br><span class="muted">' . e($row['created_name']) . ' · ' . e($row['created_at']) . '</span></td></tr>';
        }
        $table .= '</tbody></table></div>';
    }
    $table .= '</div>';

    $body = '<div class="sede-selected-banner"><div><div class="kicker">Sede seleccionada</div><strong data-selected-sede-name></strong><small data-selected-sede-location></small></div></div>'
        . '<div class="card"><div class="kicker">Registro guiado</div><h3>Registrar equipo encontrado que no aparece en el inventario</h3><p class="muted">Seleccione la categoría. SIVI ocultará las opciones de las demás categorías y mostrará solamente los campos aplicables al elemento seleccionado.</p>' . $form . '</div>'
        . $table;

    if ($requiresSelection) {
        $content = '<div data-sede-gate><div class="card sede-selection-card"><div class="kicker">Ubicación obligatoria</div><h3>Seleccione la sede antes de registrar el equipo</h3><p class="sede-selection-help">Orden requerido: Departamento, Municipio, Tipo de sede y Sede. El formulario se habilitará al completar la selección.</p>'
            . module_sede_selector_fields($sedes, $selectedSedeId, 'additional_scope', 'sede_scope_id', 'Sede', ['gate'=>true])
            . '</div><div class="sede-dependent-panel" data-sede-dependent' . ($selectedSedeId > 0 ? '' : ' hidden') . '>' . $body . '</div></div>';
    } else {
        $ownSede = Database::fetchOne('SELECT id,identificador,cod_dd,departamento,municipio,tipo_sede,nombre_sede FROM sedes WHERE id=?', [$selectedSedeId]) ?: [];
        $content = fixed_sede_summary($ownSede) . '<div class="card"><div class="kicker">Registro guiado</div><h3>Registrar equipo encontrado que no aparece en el inventario</h3><p class="muted">El elemento quedará asociado automáticamente a su sede. Solo se mostrarán las opciones de la categoría seleccionada.</p>' . $form . '</div>' . $table;
    }
    render_page('Equipos adicionales', $content, ['subtitle'=>'Registro dinámico por categoría, propiedad, estado e identidad única.']);
}

function import_page(): void
{
    Auth::requireRole('admin_gi');

    if (request_method('POST')) {
        verify_csrf();
        $action = trim((string)($_POST['import_action'] ?? 'validate'));
        $type = trim((string)($_POST['import_type'] ?? ''));
        $field = match ($type) {
            'sedes' => 'sedes_file',
            'glpi_computers' => 'glpi_computers_file',
            'warehouse' => 'warehouse_file',
            'glpi_asset' => 'asset_file',
            default => '',
        };

        try {
            if (!in_array($type, ['sedes','glpi_computers','warehouse','glpi_asset'], true)) {
                throw new InvalidArgumentException('Seleccione una etapa de importación válida.');
            }
            if ($type === 'glpi_computers') InitializationState::assertSedesComplete();
            elseif ($type === 'warehouse') InitializationState::assertGlpiComplete();
            elseif ($type === 'glpi_asset' && !InitializationState::isReady()) {
                throw new RuntimeException('Los reportes complementarios solo se habilitan después de completar las tres etapas de inicialización.');
            }

            if ($action === 'apply') {
                $validationId = (int)($_POST['validation_id'] ?? 0);
                $validation = ImportQuality::applicableValidation($validationId, $type);
                $target = (string)$validation['path'];
                $originalName = (string)$validation['original_name'];
                $fileHash = (string)$validation['file_hash'];
                $userId = (int)Auth::id();

                if ($type === 'sedes') {
                    $result = DirectoryImporter::import($target, $originalName, $userId, true);
                    InitializationState::markSedesComplete((int)$result['import_id'], (int)$result['processed'], $originalName, $fileHash);
                    ImportQuality::markApplied($validationId, 'directory_import', (int)$result['import_id']);
                    audit('complete_initialization_sedes', 'directory_import', (int)$result['import_id'], null, $result);
                    flash('success', 'Etapa 1 aplicada: ' . (int)$result['processed'] . ' sedes procesadas. GLPI y Almacén quedaron pendientes para garantizar la secuencia oficial.');
                } elseif ($type === 'glpi_computers') {
                    $result = Importer::importAssetReport($target, $originalName, $userId, 'computador', false);
                    InitializationState::markGlpiComplete((int)$result['importId'], (int)$result['equipmentCount'], $originalName, $fileHash);
                    ImportQuality::markApplied($validationId, 'import', (int)$result['importId']);
                    $counts = $result['categoryCounts'] ?? [];
                    $serialStats = $result['serialStats'] ?? [];
                    $duplicateNotice = (int)($serialStats['duplicate_records_cleared'] ?? 0) > 0
                        ? ' Se dejaron en blanco ' . (int)$serialStats['duplicate_records_cleared'] . ' registros pertenecientes a ' . (int)($serialStats['duplicate_values'] ?? 0) . ' grupos de seriales repetidos para verificación del usuario.'
                        : '';
                    flash('success', 'Etapa 2 aplicada: ' . (int)$result['equipmentCount'] . ' computadores importados (CPU ' . (int)($counts['cpu'] ?? 0) . ', portátiles ' . (int)($counts['portatil'] ?? 0) . ', Todo en Uno ' . (int)($counts['pc_todo_en_uno'] ?? 0) . ').' . $duplicateNotice . ' La conciliación con Almacén quedó pendiente.');
                } elseif ($type === 'warehouse') {
                    $result = WarehouseImporter::import($target, $originalName, $userId);
                    InitializationState::markWarehouseComplete((int)$result['importId'], (int)$result['rows'], $originalName, $fileHash);
                    ImportQuality::markApplied($validationId, 'warehouse_import', (int)$result['importId']);
                    $warehouseDuplicateNotice = (int)($result['duplicateEquipmentSerialsCleared'] ?? 0) > 0
                        ? ' Se eliminaron los seriales de ' . (int)$result['duplicateEquipmentSerialsCleared'] . ' elementos distribuidos en ' . (int)($result['duplicateSerialGroupsCleared'] ?? 0) . ' grupos duplicados.'
                        : '';
                    flash('success', 'Etapa 3 aplicada: ' . (int)$result['rows'] . ' activos procesados; ' . (int)$result['matched'] . ' cruces con GLPI, ' . (int)$result['warehouseOnly'] . ' activos adicionales incorporados desde Almacén (' . (int)$result['warehouseExactAssigned'] . ' con sede identificada, ' . (int)$result['warehouseDepartmentAssigned'] . ' asignados por contingencia territorial y ' . (int)$result['warehouseUnassigned'] . ' pendientes).' . $warehouseDuplicateNotice);
                } else {
                    $category = (string)($validation['asset_category'] ?? '');
                    if (!in_array($category, ['monitor','impresora'], true)) throw new RuntimeException('La validación no contiene una categoría complementaria válida.');
                    $result = Importer::importAssetReport($target, $originalName, $userId, $category, true);
                    ImportQuality::markApplied($validationId, 'import', (int)$result['importId']);
                    flash('success', 'Reporte complementario aplicado: ' . (int)$result['equipmentCount'] . ' activos procesados.');
                }
            } else {
                if ($field === '' || empty($_FILES[$field]['name']) || (int)($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('Seleccione un archivo XLSX válido.');
                }
                UploadSecurity::validateXlsx((array)$_FILES[$field], 120 * 1024 * 1024);
                $originalName = trim((string)$_FILES[$field]['name']);
                if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'xlsx') {
                    throw new RuntimeException('Solo se admiten archivos .xlsx.');
                }
                $uploadDir = dirname(__DIR__) . '/storage/uploads';
                if (!is_dir($uploadDir) && !mkdir($uploadDir, 0770, true) && !is_dir($uploadDir)) {
                    throw new RuntimeException('No fue posible preparar la carpeta de cargas.');
                }
                $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '.xlsx';
                $target = $uploadDir . '/' . $filename;
                if (!move_uploaded_file($_FILES[$field]['tmp_name'], $target)) {
                    throw new RuntimeException('No fue posible almacenar el archivo para validación.');
                }
                $category = $type === 'glpi_asset' ? trim((string)($_POST['asset_category'] ?? '')) : null;
                if ($type === 'glpi_asset' && !in_array($category, ['monitor','impresora'], true)) {
                    throw new InvalidArgumentException('Seleccione Monitores o Impresoras.');
                }
                $result = ImportQuality::validateFile($type, $target, $originalName, (int)Auth::id(), $category);
                $message = 'Validación terminada: ' . (int)$result['rows_read'] . ' filas leídas, ' . (int)$result['valid_rows'] . ' válidas, ' . (int)$result['critical_count'] . ' errores críticos y ' . (int)$result['warning_count'] . ' advertencias.';
                if ($result['traffic_light'] === 'rojo') flash('danger', $message . ' Corrija el archivo y vuelva a validarlo.');
                elseif ($result['traffic_light'] === 'amarillo') flash('warning', $message . ' Puede aplicar la carga, pero se recomienda revisar el reporte.');
                else flash('success', $message . ' El archivo está listo para aplicar.');
            }
        } catch (Throwable $e) {
            $reference = log_exception_reference($e, 'initialization_import_' . ($type !== '' ? $type : 'unknown'));
            flash('danger', inventory_import_error_message($e, $reference));
        }
        redirect('importar');
    }

    $state = InitializationState::status();
    $quality = ImportQuality::currentQuality();
    $validations = Database::fetchAll('SELECT v.*,u.name created_name FROM import_validations v JOIN users u ON u.id=v.created_by ORDER BY v.id DESC LIMIT 40');
    $directoryImports = Database::fetchAll('SELECT di.*,u.name created_name FROM directory_imports di JOIN users u ON u.id=di.created_by ORDER BY di.id DESC LIMIT 10');
    $imports = Database::fetchAll('SELECT i.*,u.name created_name FROM imports i JOIN users u ON u.id=i.created_by ORDER BY i.id DESC LIMIT 15');
    $warehouseImports = Database::fetchAll('SELECT i.*,u.name created_name FROM warehouse_imports i JOIN users u ON u.id=i.created_by ORDER BY i.id DESC LIMIT 10');

    $trafficLabel = match ($quality['traffic_light']) {'verde'=>'Datos listos','amarillo'=>'Revisión recomendada',default=>'Corrección obligatoria'};
    $content = '<div class="quality-banner quality-' . e($quality['traffic_light']) . '"><div><div class="kicker">Semáforo de calidad</div><h2>' . e($trafficLabel) . '</h2><p>' . (int)$quality['critical_count'] . ' hallazgos críticos · ' . (int)$quality['warning_count'] . ' advertencias. Las campañas solo pueden crearse cuando no existan inconsistencias críticas.</p></div><a class="btn btn-light" href="' . e(route_url('diagnostico')) . '">Abrir diagnóstico</a></div>';
    $content .= initialization_progress_panel($state, false);
    $content .= '<div class="alert alert-info"><strong>Proceso seguro en dos pasos:</strong> primero valide el archivo sin modificar la base de datos. Después, desde el historial de validaciones, confirme la aplicación.</div>';

    $sedesComplete = !empty($state['sedes']['complete']);
    $glpiComplete = !empty($state['glpi']['complete']);
    $warehouseComplete = !empty($state['warehouse']['complete']);
    $content .= '<div class="row g-4 initialization-import-grid">';

    $content .= import_stage_card(1, 'sedes', 'Importar Maestro de Sedes', '1. Sedes RNEC.xlsx', 'sedes_file', $sedesComplete, true, 'Valida identificadores, campos territoriales, duplicados y direcciones. Volver a aplicar esta etapa deja pendientes GLPI y Almacén.');
    $content .= import_stage_card(2, 'glpi_computers', 'Importar GLPI · Computadores', '2. Inventario Equipos glpi.xlsx', 'glpi_computers_file', $glpiComplete, $sedesComplete, 'Valida CPU, portátiles y Todo en Uno; detecta seriales duplicados, seriales genéricos, equipos sin localización y registros no físicos.');
    $content .= import_stage_card(3, 'warehouse', 'Importar Inventario de Almacén', '3. INVENTARIO ALMACEN.xlsx', 'warehouse_file', $warehouseComplete, $glpiComplete, 'Valida placas, seriales, referencias y categorías; cruza primero con GLPI y, para los activos sin coincidencia, usa Nombre Sucursal y Nombre Centro de Costo para identificar la sede o el departamento.');
    $content .= '</div>';

    if ($state['ready']) {
        $content .= '<div class="card"><div class="kicker">Carga posterior</div><h2>Reportes complementarios GLPI</h2><p>Valide y aplique reportes separados de monitores o impresoras.</p><form method="post" enctype="multipart/form-data">' . csrf_field() . '<input type="hidden" name="import_action" value="validate"><input type="hidden" name="import_type" value="glpi_asset">' . field('asset_category','Categoría','monitor','select',['required'=>true,'choices'=>['monitor'=>'Monitores','impresora'=>'Impresoras']]) . '<label class="field"><span>Reporte GLPI complementario (.xlsx)</span><input type="file" name="asset_file" accept=".xlsx" required></label><div class="form-actions"><button class="btn">Validar archivo</button></div></form></div>';
    }

    $content .= '<div class="card"><div class="toolbar"><div><div class="kicker">Trazabilidad</div><h2>Validaciones previas</h2><p class="muted">Los archivos con semáforo rojo no se pueden aplicar. El reporte Excel conserva el detalle por fila.</p></div></div>';
    if (!$validations) {
        $content .= empty_state('Sin validaciones', 'Cargue el primer archivo para iniciar el proceso.');
    } else {
        $content .= '<div class="table-wrap"><table><thead><tr><th>Archivo</th><th>Etapa</th><th>Resultado</th><th>Filas</th><th>Hallazgos</th><th>Fecha</th><th>Acciones</th></tr></thead><tbody>';
        foreach ($validations as $v) {
            $typeLabel = match ((string)$v['import_type']) {'sedes'=>'1 · Sedes','glpi_computers'=>'2 · GLPI computadores','warehouse'=>'3 · Almacén','glpi_asset'=>'Complementaria',default=>(string)$v['import_type']};
            $canApply = in_array((string)$v['status'], ['aprobada','advertencias'], true) && empty($v['applied_at']);
            if ($v['import_type'] === 'glpi_computers' && !$sedesComplete) $canApply = false;
            if ($v['import_type'] === 'warehouse' && !$glpiComplete) $canApply = false;
            if ($v['import_type'] === 'glpi_asset' && !$state['ready']) $canApply = false;
            $actions = '';
            if (!empty($v['error_report_path'])) $actions .= '<a class="btn btn-sm btn-outline-secondary" href="' . e(route_url('import_validation_report',['id'=>$v['id']])) . '">Descargar reporte</a> ';
            if ($canApply) $actions .= '<form class="d-inline" method="post">' . csrf_field() . '<input type="hidden" name="import_action" value="apply"><input type="hidden" name="import_type" value="' . e($v['import_type']) . '"><input type="hidden" name="validation_id" value="' . (int)$v['id'] . '"><button class="btn btn-sm btn-success" data-confirm="¿Confirma aplicar este archivo validado a la base de datos?">Aplicar importación</button></form>';
            elseif (!empty($v['applied_at'])) $actions .= '<span class="badge text-bg-success">Aplicada</span>';
            else $actions .= '<span class="muted">No aplicable</span>';
            $content .= '<tr><td><strong>' . e($v['original_name']) . '</strong><br><span class="muted">' . e($v['created_name']) . '</span></td><td>' . e($typeLabel) . '</td><td><span class="quality-dot quality-dot-' . e($v['traffic_light']) . '"></span>' . status_badge($v['status']) . '</td><td>' . (int)$v['valid_rows'] . ' / ' . (int)$v['rows_read'] . '</td><td><strong class="text-danger">' . (int)$v['critical_count'] . '</strong> críticos<br><span class="text-warning">' . (int)$v['warning_count'] . ' advertencias</span></td><td>' . e($v['created_at']) . '</td><td>' . $actions . '</td></tr>';
        }
        $content .= '</tbody></table></div>';
    }
    $content .= '</div>';

    $content .= import_applied_history_panel($directoryImports, $imports, $warehouseImports);
    render_page('Importar inventarios', $content, ['subtitle'=>'Validación previa, aplicación controlada y trazabilidad de las tres fuentes obligatorias.']);
}

function import_stage_card(int $step, string $type, string $title, string $filename, string $field, bool $complete, bool $enabled, string $description): string
{
    $tone = $complete ? 'border-success' : ($enabled ? 'border-primary' : 'border-secondary-subtle');
    $badge = $complete ? '<span class="badge text-bg-success">Aplicada</span>' : ($enabled ? '<span class="badge text-bg-primary">Disponible</span>' : '<span class="badge text-bg-secondary">Bloqueada</span>');
    return '<div class="col-xl-4"><div class="card h-100 ' . $tone . '"><div class="d-flex justify-content-between align-items-center"><div class="kicker">Etapa ' . $step . '</div>' . $badge . '</div><h2>' . e($title) . '</h2><p>' . e($description) . '</p><div class="note note-info"><strong>Archivo de referencia:</strong> <code>' . e($filename) . '</code></div><form method="post" enctype="multipart/form-data">' . csrf_field() . '<input type="hidden" name="import_action" value="validate"><input type="hidden" name="import_type" value="' . e($type) . '"><label class="field"><span>Archivo XLSX</span><input type="file" name="' . e($field) . '" accept=".xlsx" required ' . (!$enabled ? 'disabled' : '') . '></label><div class="form-actions"><button class="btn ' . ($complete ? 'btn-outline-primary' : 'btn-primary') . '" ' . (!$enabled ? 'disabled' : '') . '>1. Validar archivo</button></div></form>' . (!$enabled ? '<p class="small text-danger mt-3">Complete y aplique la etapa anterior.</p>' : '<p class="small muted mt-3">La validación no modifica la información actual.</p>') . '</div></div>';
}

function import_applied_history_panel(array $directoryImports, array $imports, array $warehouseImports): string
{
    $html = '<div class="card"><div class="kicker">Archivos aplicados</div><h2>Historial consolidado de importaciones</h2><div class="table-wrap"><table><thead><tr><th>Fuente</th><th>Archivo</th><th>Fecha</th><th>Resultado</th><th>Estado</th></tr></thead><tbody>';
    foreach ($directoryImports as $r) $html .= '<tr><td>Sedes</td><td><strong>' . e($r['original_name']) . '</strong><br><span class="muted">' . e($r['created_name']) . '</span></td><td>' . e($r['created_at']) . '</td><td>' . (int)$r['rows_processed'] . ' procesadas · ' . (int)$r['rows_invalid'] . ' inválidas</td><td>' . status_badge($r['status']) . '</td></tr>';
    foreach ($imports as $r) $html .= '<tr><td>GLPI</td><td><strong>' . e($r['original_name']) . '</strong><br><span class="muted">' . e($r['created_name']) . '</span></td><td>' . e($r['created_at']) . '</td><td>' . (int)$r['rows_equipment'] . ' activos · ' . (int)$r['unassigned_equipment'] . ' sin sede</td><td>' . status_badge($r['status']) . '</td></tr>';
    foreach ($warehouseImports as $r) $html .= '<tr><td>Almacén</td><td><strong>' . e($r['original_name']) . '</strong><br><span class="muted">' . e($r['created_name']) . '</span></td><td>' . e($r['created_at']) . '</td><td>' . (int)$r['rows_assets'] . ' activos · ' . (int)$r['matched_equipment'] . ' cruces GLPI · ' . (int)($r['warehouse_exact_assigned'] ?? 0) . ' sede exacta · ' . (int)($r['warehouse_department_assigned'] ?? 0) . ' por contingencia territorial · ' . (int)($r['warehouse_unassigned'] ?? 0) . ' pendientes</td><td>' . status_badge($r['status']) . '</td></tr>';
    if (!$directoryImports && !$imports && !$warehouseImports) $html .= '<tr><td colspan="5">No hay importaciones aplicadas.</td></tr>';
    return $html . '</tbody></table></div></div>';
}

function import_validation_report_page(): void
{
    Auth::requireRole('admin_gi');
    $id = (int)($_GET['id'] ?? 0);
    $row = Database::fetchOne('SELECT error_report_path,original_name FROM import_validations WHERE id=?', [$id]);
    if (!$row || empty($row['error_report_path'])) { http_response_code(404); render_error('Reporte no disponible','La validación no tiene un reporte descargable.'); return; }
    $base = realpath(dirname(__DIR__) . '/storage/reports');
    $path = realpath(dirname(__DIR__) . '/' . ltrim((string)$row['error_report_path'], '/'));
    if ($base === false || $path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) { http_response_code(404); render_error('Reporte no disponible','El archivo de reporte ya no está disponible.'); return; }
    while (ob_get_level() > 0) ob_end_clean();
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $name = 'validacion_' . $id . '_' . preg_replace('/[^A-Za-z0-9._-]/','_',pathinfo((string)$row['original_name'],PATHINFO_FILENAME)) . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, no-store, no-cache, must-revalidate');
    readfile($path); exit;
}

function diagnostics_page(): void
{
    Auth::requireRole('admin_gi');
    if (request_method('POST')) {
        verify_csrf();
        $quality = ImportQuality::currentQuality();
        Database::execute('INSERT INTO data_quality_snapshots(traffic_light,critical_count,warning_count,metrics_json,created_by) VALUES(?,?,?,?,?)', [$quality['traffic_light'],$quality['critical_count'],$quality['warning_count'],json_encode($quality,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),Auth::id()]);
        audit('create_quality_snapshot','data_quality_snapshot',(int)Database::connection()->lastInsertId(),null,$quality);
        flash('success','Se guardó una fotografía del estado de calidad.'); redirect('diagnostico');
    }
    $quality = ImportQuality::currentQuality();
    $schema = Database::schemaStatus();
    $dbVersion = (string)(Database::fetchOne('SELECT VERSION() version')['version'] ?? 'No disponible');
    $diskPath = dirname(__DIR__) . '/storage';
    $free = @disk_free_space($diskPath); $total = @disk_total_space($diskPath);
    $last = [
        'sedes'=>Database::fetchOne('SELECT original_name,completed_at,status FROM directory_imports ORDER BY id DESC LIMIT 1'),
        'glpi'=>Database::fetchOne('SELECT original_name,completed_at,status FROM imports ORDER BY id DESC LIMIT 1'),
        'warehouse'=>Database::fetchOne('SELECT original_name,completed_at,status FROM warehouse_imports ORDER BY id DESC LIMIT 1'),
    ];
    $warehouseTerritorial = Database::fetchOne('SELECT matched_equipment,warehouse_only_equipment,warehouse_exact_assigned,warehouse_department_assigned,warehouse_unassigned,warehouse_glpi_enhanced FROM warehouse_imports WHERE status="completado" ORDER BY id DESC LIMIT 1') ?: [];
    $snapshots = Database::fetchAll('SELECT q.*,u.name created_name FROM data_quality_snapshots q LEFT JOIN users u ON u.id=q.created_by ORDER BY q.id DESC LIMIT 15');
    $errors = [];
    $log = dirname(__DIR__) . '/storage/logs/security.log';
    if (is_file($log) && is_readable($log)) {
        $lines = file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach (array_slice(array_reverse($lines),0,20) as $line) { $j=json_decode($line,true); if(is_array($j)&&!empty($j['reference']))$errors[]=$j; }
    }
    $trafficLabel = match($quality['traffic_light']){'verde'=>'Verde · listo para campañas','amarillo'=>'Amarillo · operación permitida con revisión',default=>'Rojo · campañas bloqueadas'};
    $content='<div class="quality-banner quality-' . e($quality['traffic_light']) . '"><div><div class="kicker">Estado integral</div><h2>' . e($trafficLabel) . '</h2><p>Generado el ' . e($quality['generated_at']) . '.</p></div><form method="post">' . csrf_field() . '<button class="btn btn-light">Guardar fotografía</button></form></div>';
    $content.='<div class="metrics">'.metric_card('Versión',AppVersion::package(),'Compilación instalada').metric_card('Sedes',$quality['metrics']['sedes_total'],'Maestro activo','green').metric_card('Equipos',$quality['metrics']['equipos_activos'],'Registros activos').metric_card('Críticos',$quality['critical_count'],'Bloquean campañas','purple').metric_card('Advertencias',$quality['warning_count'],'Requieren revisión','orange').'</div>';
    if ($warehouseTerritorial) {
        $content.='<div class="metrics">'.metric_card('Cruces GLPI',(int)($warehouseTerritorial['matched_equipment']??0),'Serial o referencia').metric_card('Sede identificada',(int)($warehouseTerritorial['warehouse_exact_assigned']??0),'Sucursal y Centro de Costo','green').metric_card('Contingencia territorial',(int)($warehouseTerritorial['warehouse_department_assigned']??0),'Asignación provisional','orange').metric_card('Pendientes Almacén',(int)($warehouseTerritorial['warehouse_unassigned']??0),'Revisión manual','purple').metric_card('GLPI mejorado',(int)($warehouseTerritorial['warehouse_glpi_enhanced']??0),'Sede complementada').'</div>';
    }
    $content.='<div class="row g-4"><div class="col-xl-6"><div class="card h-100"><h3>Controles críticos</h3><div class="quality-rule-list">';
    foreach($quality['critical_rules'] as $rule)$content.='<div class="quality-rule"><span>'.e($rule['label']).'</span><strong class="'.((int)$rule['count']>0?'text-danger':'text-success').'">'.(int)$rule['count'].'</strong></div>';
    $content.='</div></div></div><div class="col-xl-6"><div class="card h-100"><h3>Advertencias</h3><div class="quality-rule-list">';
    foreach($quality['warning_rules'] as $rule)$content.='<div class="quality-rule"><span>'.e($rule['label']).'</span><strong class="'.((int)$rule['count']>0?'text-warning':'text-success').'">'.(int)$rule['count'].'</strong></div>';
    $content.='</div></div></div></div>';
    $content.='<div class="card"><h3>Estado técnico</h3><div class="table-wrap"><table><tbody><tr><th>Base de datos</th><td>MySQL ' . e($dbVersion) . '</td></tr><tr><th>Esquema</th><td>' . ($schema['ok']?'<span class="badge text-bg-success">Actualizado</span>':'<span class="badge text-bg-danger">Pendiente</span>') . '</td></tr><tr><th>Espacio disponible</th><td>' . e($free!==false?number_format($free/1073741824,2,',','.').' GB':'No disponible') . ' de ' . e($total!==false?number_format($total/1073741824,2,',','.').' GB':'No disponible') . '</td></tr><tr><th>Correo</th><td>' . e(AppSettings::notificationsEnabled() ? ('Activo · ' . AppSettings::notificationProvider() . ' · ' . (AppSettings::get('microsoft_graph.sender_address') ?: 'remitente pendiente')) : 'Notificaciones externas deshabilitadas') . '</td></tr><tr><th>Campañas</th><td>' . ($quality['campaigns_allowed']?'<span class="badge text-bg-success">Habilitadas</span>':'<span class="badge text-bg-danger">Bloqueadas por calidad</span>') . '</td></tr></tbody></table></div></div>';
    $content.='<div class="card"><h3>Últimas fuentes aplicadas</h3><div class="table-wrap"><table><thead><tr><th>Fuente</th><th>Archivo</th><th>Fecha</th><th>Estado</th></tr></thead><tbody>';foreach($last as $label=>$row){$content.='<tr><td>'.e(mb_strtoupper($label)).'</td><td>'.e($row['original_name']??'Sin carga').'</td><td>'.e($row['completed_at']??'—').'</td><td>'.status_badge($row['status']??'pendiente').'</td></tr>';}$content.='</tbody></table></div></div>';
    $content.='<div class="card"><h3>Referencias técnicas recientes</h3>';if(!$errors)$content.=empty_state('Sin referencias recientes','No se encontraron errores registrados en security.log.');else{$content.='<div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Referencia</th><th>Contexto</th></tr></thead><tbody>';foreach($errors as $error)$content.='<tr><td>'.e($error['timestamp']??'').'</td><td><code>'.e($error['reference']).'</code></td><td>'.e($error['context']??'No informado').'</td></tr>';$content.='</tbody></table></div>';}$content.='</div>';
    $content.='<div class="card"><h3>Fotografías de calidad</h3>';if(!$snapshots)$content.=empty_state('Sin fotografías','Use el botón Guardar fotografía para registrar el estado actual.');else{$content.='<div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Semáforo</th><th>Críticos</th><th>Advertencias</th><th>Usuario</th></tr></thead><tbody>';foreach($snapshots as $s)$content.='<tr><td>'.e($s['created_at']).'</td><td><span class="quality-dot quality-dot-'.e($s['traffic_light']).'"></span>'.e($s['traffic_light']).'</td><td>'.(int)$s['critical_count'].'</td><td>'.(int)$s['warning_count'].'</td><td>'.e($s['created_name']??'Sistema').'</td></tr>';$content.='</tbody></table></div>';}$content.='</div>';
    render_page('Centro de diagnóstico',$content,['subtitle'=>'Estado técnico, calidad de datos, trazabilidad y referencias de error.']);
}


function configuration_page(): void
{
    Auth::requireRole(['admin_gi','superadmin']);
    if (request_method('POST')) {
        verify_csrf();
        $enabled = (string)($_POST['mobile_capture_enabled'] ?? '') === '1';
        $live = (string)($_POST['mobile_live_camera'] ?? '') === '1';
        $image = (string)($_POST['mobile_image_upload'] ?? '') === '1';
        $manual = (string)($_POST['mobile_manual_entry'] ?? '') === '1';
        $minutes = max(5, min(30, (int)($_POST['mobile_session_minutes'] ?? 10)));
        $pwa = (string)($_POST['pwa_install_enabled'] ?? '') === '1';
        $validationDraftsEnabled = (string)($_POST['validation_drafts_enabled'] ?? '') === '1';
        $validationDisableImages = (string)($_POST['validation_disable_images'] ?? '') === '1';
        $validationImagesMode = $validationDisableImages ? 'none' : 'optional';
        $disableAdditionalImages = (string)($_POST['additional_equipment_disable_images'] ?? '') === '1';
        $enabledImagesMode = strtolower(trim((string)($_POST['additional_equipment_images_enabled_mode'] ?? 'optional')));
        if (!in_array($enabledImagesMode, ['optional','required'], true)) $enabledImagesMode = 'optional';
        $additionalImagesMode = $disableAdditionalImages ? 'none' : $enabledImagesMode;
        if ($enabled && !$live && !$image && !$manual) {
            flash('danger', 'Seleccione al menos un método de captura móvil.');
            redirect('configuracion');
        }
        $before = [
            'mobile_capture' => AppSettings::mobileCaptureConfig(),
            'validation' => AppSettings::validationExperienceConfig(),
            'pwa_install_enabled' => AppSettings::pwaInstallEnabled(),
            'additional_equipment' => AppSettings::additionalEquipmentConfig(),
        ];
        AppSettings::setMany([
            'mobile_capture.enabled' => $enabled,
            'mobile_capture.live_camera' => $live,
            'mobile_capture.image_upload' => $image,
            'mobile_capture.manual_entry' => $manual,
            'mobile_capture.session_minutes' => $minutes,
            'pwa.install_enabled' => $pwa,
            'validation.drafts_enabled' => $validationDraftsEnabled,
            'validation.images_mode' => $validationImagesMode,
            'additional_equipment.images_mode' => $additionalImagesMode,
        ]);
        audit('update_application_settings', 'app_settings', null, $before, [
            'mobile_capture' => AppSettings::mobileCaptureConfig(),
            'validation' => AppSettings::validationExperienceConfig(),
            'pwa_install_enabled' => AppSettings::pwaInstallEnabled(),
            'additional_equipment' => AppSettings::additionalEquipmentConfig(),
        ]);
        flash('success', 'La configuración de experiencia, captura, evidencias y PWA fue actualizada.');
        redirect('configuracion');
    }

    $config = AppSettings::mobileCaptureConfig();
    $validationConfig = AppSettings::validationExperienceConfig();
    $pwaEnabled = AppSettings::pwaInstallEnabled();
    $additionalImagesMode = AppSettings::additionalEquipmentImagesMode();
    $appUrl = rtrim((string)Env::get('APP_URL', ''), '/');
    $httpsReady = str_starts_with(strtolower($appUrl), 'https://');
    $commandExists = static function (string $command): bool {
        if (!function_exists('shell_exec')) return false;
        return trim((string)@shell_exec('command -v '.escapeshellarg($command).' 2>/dev/null')) !== '';
    };
    $binaryChecks = [
        'QR de conexión' => $commandExists('qrencode'),
        'Lectura de códigos en imagen' => $commandExists('zbarimg'),
        'Reconocimiento de texto OCR' => $commandExists('tesseract'),
    ];
    $checks = '<div class="settings-readiness">'
        . '<div><span>Dominio HTTPS</span><strong class="'.($httpsReady?'text-success':'text-danger').'">'.($httpsReady?'Disponible':'Pendiente').'</strong><small>'.e($appUrl !== '' ? $appUrl : 'APP_URL no configurada').'</small></div>';
    foreach ($binaryChecks as $label => $ok) {
        $checks .= '<div><span>'.e($label).'</span><strong class="'.($ok?'text-success':'text-danger').'">'.($ok?'Disponible':'No disponible').'</strong></div>';
    }
    $checks .= '</div>';

    $content = '<section class="card settings-hero"><div><div class="kicker">Administración</div><h2>Configuración de experiencia, captura y PWA</h2><p>Defina centralmente cómo funciona la validación para que los usuarios operativos vean solo las opciones necesarias.</p></div><button type="button" class="btn btn-outline-primary" data-pwa-install hidden>Instalar SIVI en este dispositivo</button></section>'
        . '<form method="post" class="card settings-form">'.csrf_field()
        . '<div class="settings-section"><div><h3>Captura desde celular</h3><p>Habilita el código QR temporal en Validar inventario y Equipos adicionales.</p></div>'
        . '<label class="settings-switch"><input type="checkbox" name="mobile_capture_enabled" value="1"'.($config['enabled']?' checked':'').'><span><strong>Permitir captura móvil</strong><small>Los usuarios podrán conectar un celular para enviar serial y Placa RNEC.</small></span></label></div>'
        . '<div class="settings-options" data-mobile-capture-options>'
        . '<label class="settings-switch"><input type="checkbox" name="mobile_live_camera" value="1"'.($config['live_camera']?' checked':'').'><span><strong>Cámara en vivo</strong><small>Lectura automática cuando el navegador sea compatible.</small></span></label>'
        . '<label class="settings-switch"><input type="checkbox" name="mobile_image_upload" value="1"'.($config['image_upload']?' checked':'').'><span><strong>Fotografía y galería</strong><small>Procesa QR, códigos de barras y texto visible en etiquetas.</small></span></label>'
        . '<label class="settings-switch"><input type="checkbox" name="mobile_manual_entry" value="1"'.($config['manual_entry']?' checked':'').'><span><strong>Digitación manual</strong><small>Permite corregir o escribir el valor antes de enviarlo.</small></span></label>'
        . '<label class="field"><span class="form-label">Duración de la conexión</span><select class="form-select" name="mobile_session_minutes">';
    foreach ([5,10,15,20,30] as $minute) {
        $content .= '<option value="'.$minute.'"'.((int)$config['session_minutes']===$minute?' selected':'').'>'.$minute.' minutos</option>';
    }
    $content .= '</select></label></div>'
        . '<hr><div class="settings-section"><div><h3>Experiencia de Validar inventario</h3><p>Estas opciones son administradas únicamente desde Configuración. El usuario operativo no puede activarlas ni desactivarlas.</p></div>'
        . '<label class="settings-switch"><input type="checkbox" name="validation_drafts_enabled" value="1"'.($validationConfig['drafts_enabled']?' checked':'').'><span><strong>Guardado automático de borradores</strong><small>Cuando está activo, SIVI guarda silenciosamente el avance. El usuario no verá controles para descartar o deshabilitar borradores.</small></span></label>'
        . '<label class="settings-switch"><input type="checkbox" name="validation_disable_images" value="1"'.($validationConfig['images_mode']==='none'?' checked':'').'><span><strong>No solicitar imágenes en Validar inventario</strong><small>Oculta por completo los campos de carga de evidencias del paso final, incluso si una campaña había marcado la fotografía general como requerida.</small></span></label></div>'
        . '<hr><div class="settings-section" data-additional-images-settings><div><h3>Imágenes de equipos adicionales</h3><p>Controle si el formulario de equipos adicionales debe mostrar campos para fotografías.</p></div>'
        . '<label class="settings-switch"><input type="checkbox" name="additional_equipment_disable_images" value="1" data-additional-images-disabled'.($additionalImagesMode==='none'?' checked':'').'><span><strong>No solicitar imágenes</strong><small>Cuando se habilita, el formulario no muestra foto general, foto de placa ni foto de serial.</small></span></label>'
        . '<div class="settings-options" data-additional-images-options'.($additionalImagesMode==='none'?' hidden':'').'>'
        . '<label class="field"><span class="form-label">Cuando se soliciten imágenes</span><select class="form-select" name="additional_equipment_images_enabled_mode">'
        . '<option value="optional"'.($additionalImagesMode!=='required'?' selected':'').'>Solicitar imágenes de forma opcional</option>'
        . '<option value="required"'.($additionalImagesMode==='required'?' selected':'').'>Solicitar al menos una imagen obligatoria</option>'
        . '</select></label></div></div>'
        . '<hr><div class="settings-section"><div><h3>Aplicación instalable</h3><p>Muestra la opción para instalar SIVI como PWA sin descargarla desde una tienda.</p></div>'
        . '<label class="settings-switch"><input type="checkbox" name="pwa_install_enabled" value="1"'.($pwaEnabled?' checked':'').'><span><strong>Permitir instalación de SIVI</strong><small>Android mostrará el instalador; en iOS se mostrarán instrucciones para “Añadir a pantalla de inicio”.</small></span></label></div>'
        . '<div class="form-actions"><button class="btn btn-success" type="submit">Guardar configuración</button></div></form>'
        . '<section class="card"><h3>Disponibilidad técnica</h3>'.$checks.'<div class="note mt-3">La cámara y la instalación PWA requieren un dominio HTTPS válido. Después de modificar esta configuración no es necesario importar nuevamente el inventario.</div></section>';
    render_page('Configuración', $content, ['subtitle'=>'Experiencia de validación, captura móvil, evidencias e instalación PWA.']);
}


function mail_notifications_page(): void
{
    Auth::requireRole(['admin_gi','superadmin']);
    NotificationTemplate::ensureDefaults();

    if (request_method('POST')) {
        verify_csrf();
        $action = (string)($_POST['action'] ?? 'save_settings');
        try {
            if ($action === 'save_settings') {
                $before = AppSettings::notificationConfig();
                $enabled = (string)($_POST['notifications_enabled'] ?? '') === '1';
                $queueEnabled = (string)($_POST['queue_enabled'] ?? '') === '1';
                $provider = (string)($_POST['provider'] ?? 'log');
                if (!in_array($provider,['log','mail','smtp','microsoft_graph'],true)) $provider='log';
                $tenantId = trim((string)($_POST['tenant_id'] ?? ''));
                $clientId = trim((string)($_POST['client_id'] ?? ''));
                $sender = strtolower(trim((string)($_POST['sender_address'] ?? '')));
                $senderName = trim((string)($_POST['sender_name'] ?? 'SIVI-RNEC'));
                $replyTo = strtolower(trim((string)($_POST['reply_to'] ?? '')));
                $testRecipient = strtolower(trim((string)($_POST['test_recipient'] ?? '')));
                $secretExpires = trim((string)($_POST['secret_expires_on'] ?? ''));
                $maxAttempts = max(1,min(12,(int)($_POST['max_attempts'] ?? 5)));
                $retryMinutes = max(1,min(1440,(int)($_POST['retry_minutes'] ?? 10)));
                $newSecret = trim((string)($_POST['client_secret'] ?? ''));

                if ($provider === 'microsoft_graph') {
                    if (!preg_match('/^[0-9a-f-]{36}$/i',$tenantId)) throw new InvalidArgumentException('Ingrese un Tenant ID válido.');
                    if (!preg_match('/^[0-9a-f-]{36}$/i',$clientId)) throw new InvalidArgumentException('Ingrese un Client ID válido.');
                    if (!filter_var($sender,FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Ingrese el buzón remitente de Microsoft 365.');
                    if ($replyTo !== '' && !filter_var($replyTo,FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('El correo de respuesta no es válido.');
                    if ($testRecipient !== '' && !filter_var($testRecipient,FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('El destinatario de prueba no es válido.');
                    if (!SecretVault::isConfigured()) throw new RuntimeException('Configure APP_ENCRYPTION_KEY en Dokploy antes de guardar Microsoft 365.');
                    if ($newSecret === '' && AppSettings::get('microsoft_graph.client_secret') === '') throw new InvalidArgumentException('Ingrese el secreto de cliente de Microsoft Entra.');
                }

                $values = [
                    'notifications.enabled'=>$enabled,
                    'notifications.provider'=>$provider,
                    'notifications.queue_enabled'=>$queueEnabled,
                    'notifications.max_attempts'=>$maxAttempts,
                    'notifications.retry_minutes'=>$retryMinutes,
                    'microsoft_graph.tenant_id'=>$tenantId,
                    'microsoft_graph.client_id'=>$clientId,
                    'microsoft_graph.sender_address'=>$sender,
                    'microsoft_graph.sender_name'=>$senderName,
                    'microsoft_graph.reply_to'=>$replyTo,
                    'microsoft_graph.test_recipient'=>$testRecipient,
                    'microsoft_graph.secret_expires_on'=>$secretExpires,
                ];
                if ($newSecret !== '') $values['microsoft_graph.client_secret']=SecretVault::encrypt($newSecret);
                AppSettings::setMany($values);
                audit('update_mail_notifications_settings','app_settings',null,$before,AppSettings::notificationConfig());
                flash('success','La configuración de correo y Microsoft 365 fue actualizada.');
            } elseif ($action === 'test_connection') {
                $result=(new MicrosoftGraphClient())->testConnection();
                audit('test_microsoft_graph_connection','mail_configuration',null,null,['sender'=>$result['sender'],'roles'=>$result['roles']]);
                flash('success','Conexión correcta. Microsoft Entra emitió un token con Mail.Send para el buzón '.$result['sender'].'.');
            } elseif ($action === 'send_test') {
                $recipient=strtolower(trim((string)($_POST['test_recipient'] ?? AppSettings::get('microsoft_graph.test_recipient'))));
                if(!filter_var($recipient,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Defina un destinatario válido para la prueba.');
                $subject='Prueba de notificaciones SIVI '.AppVersion::package();
                $html='<div><h2>SIVI-RNEC</h2><p>La configuración de Microsoft 365 está funcionando correctamente.</p><p><strong>Versión:</strong> '.e(AppVersion::package()).'<br><strong>Fecha:</strong> '.e(date('Y-m-d H:i:s')).'</p></div>';
                $delivery=(new MicrosoftGraphClient())->send($recipient,$subject,$html);
                Database::execute("INSERT INTO notifications(recipient,subject,event_key,status,sent_at,provider_request_id) VALUES(?,?,'configuration_test','enviado',NOW(),?)",[$recipient,$subject,$delivery['request_id']]);
                audit('send_microsoft_graph_test','mail_configuration',null,null,['recipient'=>$recipient,'request_id'=>$delivery['request_id']]);
                flash('success','Correo de prueba aceptado por Microsoft Graph para '.$recipient.'.');
            } elseif ($action === 'process_queue') {
                $result=NotificationQueue::processBatch(50);
                audit('process_notification_queue','notification_queue',null,null,$result);
                flash($result['failed']>0?'warning':'success','Procesados: '.$result['processed'].'. Enviados: '.$result['sent'].'. Fallidos: '.$result['failed'].'.');
            } elseif ($action === 'retry_queue') {
                $queueId=(int)($_POST['queue_id']??0);
                if($queueId<1)throw new InvalidArgumentException('Mensaje de cola no válido.');
                NotificationQueue::retry($queueId);
                audit('retry_notification_queue','notification_queue',$queueId,null,['status'=>'pendiente']);
                flash('success','El mensaje fue devuelto a la cola para un nuevo intento.');
            } elseif ($action === 'save_template') {
                $key=(string)($_POST['template_key']??'');
                NotificationTemplate::update($key,(string)($_POST['subject_template']??''),(string)($_POST['html_template']??''),(string)($_POST['template_active']??'')==='1');
                audit('update_notification_template','notification_template',null,null,['template_key'=>$key]);
                flash('success','Plantilla de notificación actualizada.');
            }
        } catch(Throwable $e) {
            $reference=log_exception_reference($e,'mail_notifications_admin');
            flash('danger',safe_error_message($e->getMessage()?:'No fue posible completar la operación',$reference));
        }
        redirect('correo');
    }

    $config=AppSettings::notificationConfig();
    $stats=NotificationQueue::stats();
    $recent=NotificationQueue::recent(100);
    $templates=NotificationTemplate::all();
    $encrypted=AppSettings::get('microsoft_graph.client_secret');
    $secretFingerprint=$encrypted!==''?SecretVault::fingerprint($encrypted):'';
    $expiryDays=null;
    if($config['secret_expires_on']!==''){
        try{$expiryDays=(int)floor((strtotime((string)$config['secret_expires_on'].' 23:59:59')-time())/86400);}catch(Throwable){}
    }
    $connectionState=$config['graph_configured']?'<span class="badge text-bg-success">Configuración completa</span>':'<span class="badge text-bg-warning">Configuración pendiente</span>';
    $enabledState=$config['enabled']?'<span class="badge text-bg-success">Envíos habilitados</span>':'<span class="badge text-bg-secondary">Envíos deshabilitados</span>';

    $content='<section class="card settings-hero"><div><div class="kicker">Administración</div><h2>Correo y notificaciones Microsoft 365</h2><p>Configure el envío saliente de SIVI mediante Microsoft Graph. Este módulo no lee buzones ni utiliza IMAP.</p></div><div class="d-flex gap-2 flex-wrap">'.$enabledState.$connectionState.'</div></section>';
    if(!$config['encryption_key_configured'])$content.='<div class="alert alert-danger"><strong>Falta APP_ENCRYPTION_KEY.</strong> Configure una llave aleatoria en Dokploy antes de guardar el secreto de Microsoft Entra.</div>';
    if($expiryDays!==null&&$expiryDays<=30)$content.='<div class="alert '.($expiryDays<0?'alert-danger':'alert-warning').'"><strong>Credencial de Microsoft Entra:</strong> '.($expiryDays<0?'está vencida':'vence en '.$expiryDays.' día(s)').'.</div>';

    $providerOptions=['log'=>'Solo registrar en log','mail'=>'PHP mail()','smtp'=>'SMTP tradicional','microsoft_graph'=>'Microsoft Graph · recomendado'];
    $content .= '<form method="post" class="card settings-form">' . csrf_field() . '<input type="hidden" name="action" value="save_settings">';
    $content .= '<div class="settings-section"><div><h3>Servicio de notificaciones</h3><p>Puede conservar los envíos deshabilitados mientras termina la configuración en Microsoft Entra.</p></div>';
    $content .= '<label class="settings-switch"><input type="checkbox" name="notifications_enabled" value="1"' . ($config['enabled']?' checked':'') . '><span><strong>Habilitar notificaciones externas</strong><small>Las operaciones de SIVI continuarán aunque un correo falle.</small></span></label></div>';
    $content .= '<div class="form-grid"><label class="field"><span class="form-label">Proveedor</span><select class="form-select" name="provider">';
    foreach($providerOptions as $value=>$label){
        $content .= '<option value="'.e($value).'"'.($config['provider']===$value?' selected':'').'>'.e($label).'</option>';
    }
    $content .= '</select></label>';
    $content .= field('tenant_id','Tenant ID',(string)$config['tenant_id'],'text',['placeholder'=>'00000000-0000-0000-0000-000000000000']);
    $content .= field('client_id','Client ID',(string)$config['client_id'],'text',['placeholder'=>'00000000-0000-0000-0000-000000000000']);
    $content .= field('client_secret','Secreto de cliente','','password',['placeholder'=>($config['secret_configured']?'Dejar vacío para conservarlo':'Ingrese el valor del secreto'),'autocomplete'=>'new-password']);
    $content .= field('sender_address','Buzón remitente',(string)$config['sender_address'],'email',['placeholder'=>'sivi@registraduria.gov.co']);
    $content .= field('sender_name','Nombre del remitente',(string)$config['sender_name'],'text');
    $content .= field('reply_to','Correo de respuesta',(string)$config['reply_to'],'email',['placeholder'=>'soporte@registraduria.gov.co']);
    $content .= field('test_recipient','Destinatario de prueba',(string)$config['test_recipient'],'email');
    $content .= field('secret_expires_on','Vencimiento del secreto',(string)$config['secret_expires_on'],'date');
    $content .= field('max_attempts','Intentos máximos',(string)$config['max_attempts'],'number',['min'=>1,'max'=>12]);
    $content .= field('retry_minutes','Minutos base entre reintentos',(string)$config['retry_minutes'],'number',['min'=>1,'max'=>1440]);
    $content .= '</div>';
    $content .= '<label class="settings-switch"><input type="checkbox" name="queue_enabled" value="1"'.($config['queue_enabled']?' checked':'').'><span><strong>Usar cola de envío</strong><small>El trabajador de notificaciones envía los mensajes sin bloquear la validación de inventario.</small></span></label>';
    if($config['secret_configured']){
        $content .= '<div class="note mt-3">Secreto cifrado configurado · huella <code>'.e($secretFingerprint).'</code>. El valor no vuelve a mostrarse.</div>';
    }
    $content .= '<div class="form-actions"><button class="btn btn-success" type="submit">Guardar configuración</button></div></form>';

    $content.='<section class="card"><div class="toolbar"><div><div class="kicker">Comprobación</div><h3>Pruebas de Microsoft 365</h3><p class="muted">La prueba de conexión valida la credencial y el permiso Mail.Send; el correo de prueba confirma el buzón remitente.</p></div></div><div class="d-flex gap-2 flex-wrap"><form method="post">'.csrf_field().'<input type="hidden" name="action" value="test_connection"><button class="btn btn-outline-primary">Probar conexión</button></form><form method="post" class="d-flex gap-2 flex-wrap">'.csrf_field().'<input type="hidden" name="action" value="send_test"><input class="form-control" type="email" name="test_recipient" value="'.e((string)$config['test_recipient']).'" placeholder="correo@dominio.gov.co" required><button class="btn btn-primary">Enviar correo de prueba</button></form></div></section>';

    $content.='<div class="metrics">'.metric_card('Pendientes',$stats['pendientes'],'En cola','orange').metric_card('Procesando',$stats['procesando'],'Trabajador activo').metric_card('Enviados',$stats['enviados'],'Aceptados por Graph','green').metric_card('Errores',$stats['errores'],'Con reintento','purple').'</div>';
    $content.='<section class="card"><div class="toolbar"><div><h3>Cola de mensajes</h3><p class="muted">El servicio <code>notifications</code> procesa automáticamente la cola.</p></div><form method="post">'.csrf_field().'<input type="hidden" name="action" value="process_queue"><button class="btn btn-outline-primary">Procesar ahora</button></form></div>';
    if(!$recent)$content.=empty_state('Sin mensajes','Los correos encolados aparecerán aquí.');else{$content.='<div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Evento</th><th>Destinatario</th><th>Asunto</th><th>Estado</th><th>Intentos</th><th>Detalle</th><th>Acción</th></tr></thead><tbody>';foreach($recent as $row){$retry=(string)$row['status']==='error'?'<form method="post">'.csrf_field().'<input type="hidden" name="action" value="retry_queue"><input type="hidden" name="queue_id" value="'.(int)$row['id'].'"><button class="btn btn-sm btn-outline-primary">Reintentar</button></form>':'—';$content.='<tr><td>'.e($row['created_at']).'</td><td><code>'.e($row['event_key']).'</code></td><td>'.e($row['recipient']).'</td><td>'.e($row['subject']).'</td><td>'.status_badge($row['status']).'</td><td>'.(int)$row['attempts'].' / '.(int)$row['max_attempts'].'</td><td>'.e(mb_substr((string)($row['last_error']??''),0,180)?:($row['provider_request_id']?:'—')).'</td><td>'.$retry.'</td></tr>';}$content.='</tbody></table></div>';}$content.='</section>';

    $content.='<section class="card"><div class="kicker">Contenido administrable</div><h3>Plantillas de correo</h3><p class="muted">Variables disponibles: <code>{{campania}}</code>, <code>{{sede}}</code>, <code>{{responsable_nombre}}</code>, <code>{{fecha_limite}}</code>, <code>{{url_accion}}</code>, <code>{{equipo}}</code>, <code>{{detalle}}</code>, <code>{{resultado}}</code> y <code>{{numero_constancia}}</code>.</p><div class="accordion" id="mailTemplates">';
    foreach($templates as $i=>$tpl){$content.='<div class="accordion-item"><h2 class="accordion-header"><button class="accordion-button '.($i?'collapsed':'').'" type="button" data-bs-toggle="collapse" data-bs-target="#template'.(int)$tpl['id'].'">'.e($tpl['name']).' · <code class="ms-2">'.e($tpl['template_key']).'</code></button></h2><div id="template'.(int)$tpl['id'].'" class="accordion-collapse collapse '.(!$i?'show':'').'" data-bs-parent="#mailTemplates"><div class="accordion-body"><form method="post">'.csrf_field().'<input type="hidden" name="action" value="save_template"><input type="hidden" name="template_key" value="'.e($tpl['template_key']).'">'.field('subject_template','Asunto',(string)$tpl['subject_template'],'text',['required'=>true]).field('html_template','Contenido HTML',(string)$tpl['html_template'],'textarea',['required'=>true,'wide'=>true]).'<label class="form-check mb-3"><input class="form-check-input" type="checkbox" name="template_active" value="1"'.((int)$tpl['active']?' checked':'').'><span class="form-check-label">Plantilla activa</span></label><button class="btn btn-sm btn-success">Guardar plantilla</button></form></div></div></div>';}
    $content.='</div></section>';

    $content.='<section class="card"><h3>Configuración requerida en Microsoft Entra</h3><ol><li>Registrar la aplicación <strong>SIVI Notificaciones</strong>.</li><li>Agregar Microsoft Graph → Permisos de aplicación → <code>Mail.Send</code>.</li><li>Conceder consentimiento de administrador.</li><li>Crear un secreto de cliente y registrar su fecha de vencimiento.</li><li>Restringir la aplicación al buzón remitente mediante RBAC para aplicaciones de Exchange Online.</li></ol><div class="note">SIVI usa el flujo de credenciales de cliente y el endpoint <code>/users/{buzón}/sendMail</code>. No requiere URL de retorno ni autorización interactiva.</div></section>';
    render_page('Correo y notificaciones',$content,['subtitle'=>'Microsoft Graph, cola de envío, reintentos, pruebas y plantillas administrables.']);
}

function users_page(): void
{
    Auth::requireRole(['admin_gi','superadmin','formador']);
    $isAdmin = Auth::is('admin_gi') || Auth::is('superadmin');

    if (request_method('POST')) {
        Auth::requireRole(['admin_gi','superadmin']);
        verify_csrf();
        $role = (string)($_POST['role'] ?? 'registrador');
        $name = trim((string)($_POST['name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        $sedeId = $role === 'registrador' ? (int)($_POST['sede_id'] ?? 0) : null;
        $departmentsSelected = $role === 'formador' ? array_values(array_unique(array_filter(array_map('strval', (array)($_POST['departments'] ?? []))))) : [];

        $errors = [];
        if ($name === '') $errors[] = 'Ingrese el nombre completo.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Ingrese un correo electrónico válido.';
        if (strlen($password) < 8) $errors[] = 'La contraseña inicial debe tener mínimo 8 caracteres.';
        if (!in_array($role, ['registrador','formador','admin_gi'], true)) $errors[] = 'Seleccione un rol válido.';
        if ($role === 'registrador' && (!$sedeId || !Database::fetchOne('SELECT id FROM sedes WHERE id=?', [$sedeId]))) $errors[] = 'Seleccione la sede del Registrador.';
        if ($role === 'formador' && !$departmentsSelected) $errors[] = 'Seleccione al menos un departamento para el Formador.';

        if ($errors) {
            flash('danger', implode(' ', $errors));
            redirect('usuarios');
        }

        $pdo = Database::connection();
        try {
            $pdo->beginTransaction();
            Database::execute(
                'INSERT INTO users (name,email,password_hash,role,sede_id,active) VALUES (?,?,?,?,?,1)',
                [$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $sedeId ?: null]
            );
            $id = (int)$pdo->lastInsertId();
            foreach ($departmentsSelected as $dd) {
                if (!Database::fetchOne('SELECT id FROM sedes WHERE cod_dd=? LIMIT 1', [$dd])) {
                    throw new RuntimeException("El departamento {$dd} no existe en el maestro de sedes.");
                }
                Database::execute('INSERT INTO user_departments (user_id,cod_dd) VALUES (?,?)', [$id, $dd]);
            }
            $pdo->commit();
            audit('create_user', 'user', $id, null, ['role' => $role, 'sede_id' => $sedeId, 'departments' => $departmentsSelected]);
            flash('success', 'El usuario fue creado.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $reference = log_exception_reference($e, 'user_create');
            flash('danger', safe_error_message('No fue posible crear el usuario', $reference));
        }
        redirect('usuarios');
    }

    if ($isAdmin) {
        $users = Database::fetchAll(
            'SELECT u.*,s.identificador,s.nombre_sede,s.departamento,s.municipio,(SELECT GROUP_CONCAT(ud.cod_dd ORDER BY ud.cod_dd SEPARATOR ", ") FROM user_departments ud WHERE ud.user_id=u.id) departments FROM users u LEFT JOIN sedes s ON s.id=u.sede_id ORDER BY u.role,u.name'
        );
        $sedes = Database::fetchAll('SELECT id,identificador,cod_dd,departamento,municipio,tipo_sede,nombre_sede FROM sedes ORDER BY tipo_sede,departamento,municipio,nombre_sede');
        $departments = Database::fetchAll('SELECT DISTINCT cod_dd,departamento FROM sedes WHERE NULLIF(cod_dd,"") IS NOT NULL ORDER BY departamento');
    } else {
        $assignedDepartments = array_values(array_filter(array_map('strval', Auth::user()['departments'] ?? [])));
        if ($assignedDepartments) {
            $placeholders = implode(',', array_fill(0, count($assignedDepartments), '?'));
            $users = Database::fetchAll(
                "SELECT u.*,s.identificador,s.nombre_sede,s.departamento,s.municipio,NULL departments FROM users u JOIN sedes s ON s.id=u.sede_id WHERE u.role='registrador' AND s.cod_dd IN ({$placeholders}) ORDER BY s.departamento,s.municipio,s.nombre_sede,u.name",
                $assignedDepartments
            );
        } else {
            $users = [];
        }
        $sedes = [];
        $departments = [];
    }

    $content = '';
    if ($isAdmin) {
        $checks = '<div class="checkbox-list">';
        foreach ($departments as $department) {
            $checks .= '<label><input type="checkbox" name="departments[]" value="' . e($department['cod_dd']) . '"> ' . e($department['cod_dd'] . ' · ' . $department['departamento']) . '</label>';
        }
        $checks .= '</div>';

        $roleSelect = '<label class="field"><span>Rol</span><select name="role" data-user-role required>'
            . '<option value="registrador">Registrador — una sede</option>'
            . '<option value="formador">Formador — departamento(s)</option>'
            . '<option value="admin_gi">Admin GI — todo el país</option>'
            . '</select></label>';

        $content .= '<div class="card"><div class="kicker">Creación individual</div><h3>Crear usuario</h3>'
            . '<p class="muted">Para un Registrador seleccione Departamento, Municipio, Tipo de sede y finalmente la Sede.</p>'
            . '<form method="post" data-user-form>' . csrf_field()
            . '<div class="form-grid">'
            . field('name', 'Nombre completo', '', 'text', ['required' => true])
            . field('email', 'Correo electrónico', '', 'email', ['required' => true])
            . field('password', 'Contraseña inicial', '', 'password', ['required' => true, 'placeholder' => 'Mínimo 8 caracteres'])
            . $roleSelect
            . '</div>'
            . '<div data-role-panel="registrador"><h4>Asignación del Registrador</h4><div class="form-grid" data-user-sede-filters>' . user_sede_assignment_fields($sedes) . '</div></div>'
            . '<div data-role-panel="formador" hidden><h4>Departamentos del Formador</h4>' . $checks . '</div>'
            . '<div data-role-panel="admin_gi" hidden><div class="note">El Admin GI tendrá cobertura nacional y no requiere asignación territorial.</div></div>'
            . '<div class="form-actions"><button class="btn">Crear usuario</button></div></form></div>';

        $content .= '<div class="split"><div class="card"><div class="kicker">Carga masiva</div><h3>Importar usuarios desde Excel</h3>'
            . '<p>Descargue la plantilla generada por la plataforma. El archivo incluye las columnas requeridas, instrucciones y un catálogo actualizado de sedes.</p>'
            . '<a class="btn btn-secondary" href="' . e(route_url('usuarios_plantilla')) . '">Descargar plantilla Excel</a></div>'
            . '<div class="card"><h3>Cargar plantilla diligenciada</h3><form method="post" enctype="multipart/form-data" action="' . e(route_url('usuarios_importar')) . '">' . csrf_field()
            . '<label class="field"><span>Archivo de usuarios (.xlsx)</span><input type="file" name="users_file" accept=".xlsx" required></label>'
            . '<div class="form-actions"><button class="btn btn-success">Importar usuarios</button></div></form></div></div>';
    } else {
        $content .= '<div class="card"><div class="kicker">Gestión departamental</div><h3>Restablecimiento de contraseñas</h3>'
            . '<p>Como Formador puede restablecer únicamente la contraseña de los usuarios Registradores asignados a sedes de sus departamentos. No puede crear usuarios, cambiar estados ni intervenir cuentas de Formadores o Admin GI.</p></div>';
    }

    $importErrors = $isAdmin ? ($_SESSION['user_import_errors'] ?? []) : [];
    if ($isAdmin) unset($_SESSION['user_import_errors']);
    if ($importErrors) {
        $content .= '<div class="card"><h3>Novedades de la última importación</h3><div class="table-wrap"><table><thead><tr><th>Fila</th><th>Correo</th><th>Detalle</th></tr></thead><tbody>';
        foreach (array_slice($importErrors, 0, 200) as $error) {
            $content .= '<tr><td>' . e($error['fila'] ?? '') . '</td><td>' . e($error['correo'] ?? '') . '</td><td>' . e($error['errores'] ?? '') . '</td></tr>';
        }
        $content .= '</tbody></table></div>';
        if (count($importErrors) > 200) $content .= '<p class="muted">Se muestran las primeras 200 novedades de ' . count($importErrors) . '.</p>';
        $content .= '</div>';
    }

    $content .= '<div class="card"><h3>' . ($isAdmin ? 'Usuarios registrados' : 'Usuarios de sus departamentos') . '</h3>';
    if (!$users) {
        $content .= empty_state('No hay usuarios disponibles', $isAdmin ? 'Cree o importe usuarios para comenzar.' : 'No existen Registradores asociados a las sedes de sus departamentos.');
    } else {
        $content .= '<div class="table-wrap"><table><thead><tr><th>Usuario</th><th>Rol</th><th>Alcance</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>';
        foreach ($users as $user) {
            $scope = $user['role'] === 'registrador'
                ? trim((string)$user['identificador'] . ' · ' . (string)$user['nombre_sede'] . ' · ' . (string)$user['municipio'] . ' · ' . (string)$user['departamento'])
                : ($user['role'] === 'formador' ? ($user['departments'] ?: 'Sin departamentos') : 'Todo el país');
            $actions = '';
            if (Scope::canResetUserPassword((int)$user['id'])) {
                $actions .= '<a class="btn btn-sm" href="' . e(route_url('usuario_clave', ['id' => $user['id']])) . '">Restablecer clave</a> ';
            }
            if ($isAdmin && (string)$user['role'] !== 'superadmin') {
                $actions .= '<a class="btn btn-sm btn-outline-primary" href="' . e(route_url('usuario_editar', ['id' => $user['id']])) . '">Editar perfil</a> ';
            }
            if ($isAdmin && (int)$user['id'] !== (int)Auth::user()['id'] && (string)$user['role'] !== 'superadmin') {
                $actions .= '<a class="btn btn-sm btn-secondary" data-confirm="¿Cambiar el estado de este usuario?" href="' . e(route_url('usuario_estado', ['id' => $user['id'], 'csrf' => csrf_token()])) . '">' . ($user['active'] ? 'Desactivar' : 'Activar') . '</a>';
            }
            $content .= '<tr><td><strong>' . e($user['name']) . '</strong><br><span class="muted">' . e($user['email']) . '</span></td>'
                . '<td>' . e(role_label($user['role'])) . '</td><td>' . e($scope) . '</td>'
                . '<td>' . ($user['active'] ? '<span class="badge badge-success">Activo</span>' : '<span class="badge badge-danger">Inactivo</span>') . '</td>'
                . '<td>' . ($actions !== '' ? $actions : '<span class="muted">Sin acciones disponibles</span>') . '</td></tr>';
        }
        $content .= '</tbody></table></div>';
    }
    $content .= '</div>';
    render_page('Usuarios', $content, ['subtitle' => $isAdmin ? 'Creación, importación, asignación territorial y restablecimiento de claves.' : 'Restablecimiento de claves dentro de su alcance departamental.']);
}


function user_edit_page(): void
{
    Auth::requireRole(['admin_gi','superadmin']);

    $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    $user = $id > 0 ? Database::fetchOne('SELECT * FROM users WHERE id=?', [$id]) : null;
    if (!$user) {
        render_error('Usuario no encontrado', 'El usuario solicitado no existe.');
        return;
    }
    if ((string)$user['role'] === 'superadmin') {
        render_error('Perfil protegido', 'El rol Superadministrador no se modifica desde esta pantalla.');
        return;
    }

    $sedes = Database::fetchAll(
        'SELECT id,identificador,cod_dd,departamento,municipio,tipo_sede,nombre_sede '
        . 'FROM sedes ORDER BY departamento,municipio,tipo_sede,nombre_sede'
    );
    $departments = Database::fetchAll(
        'SELECT DISTINCT cod_dd,departamento FROM sedes '
        . 'WHERE NULLIF(TRIM(cod_dd),"") IS NOT NULL ORDER BY departamento'
    );
    $assignedRows = Database::fetchAll(
        'SELECT cod_dd FROM user_departments WHERE user_id=? ORDER BY cod_dd',
        [$id]
    );
    $assignedDepartments = array_values(array_map(
        static fn(array $row): string => (string)$row['cod_dd'],
        $assignedRows
    ));

    if (request_method('POST')) {
        verify_csrf();

        $role = trim((string)($_POST['role'] ?? ''));
        $sedeId = $role === 'registrador' ? (int)($_POST['sede_id'] ?? 0) : null;
        $departmentsSelected = $role === 'formador'
            ? array_values(array_unique(array_filter(array_map(
                'strval',
                (array)($_POST['departments'] ?? [])
            ))))
            : [];

        $errors = [];
        if (!in_array($role, ['registrador','formador','admin_gi'], true)) {
            $errors[] = 'Seleccione un rol válido.';
        }
        if ($role === 'registrador' && (
            !$sedeId
            || !Database::fetchOne('SELECT id FROM sedes WHERE id=?', [$sedeId])
        )) {
            $errors[] = 'Seleccione la sede del Registrador.';
        }
        if ($role === 'formador' && !$departmentsSelected) {
            $errors[] = 'Seleccione al menos un departamento para el Formador.';
        }
        foreach ($departmentsSelected as $departmentCode) {
            if (!Database::fetchOne(
                'SELECT id FROM sedes WHERE cod_dd=? LIMIT 1',
                [$departmentCode]
            )) {
                $errors[] = 'El departamento ' . $departmentCode . ' no existe en el catálogo de sedes.';
            }
        }

        if ($errors) {
            flash('danger', implode(' ', array_unique($errors)));
            redirect('usuario_editar', ['id'=>$id]);
        }

        $pdo = Database::connection();
        $before = [
            'role'=>(string)$user['role'],
            'sede_id'=>$user['sede_id'] !== null ? (int)$user['sede_id'] : null,
            'departments'=>$assignedDepartments,
        ];

        try {
            $pdo->beginTransaction();
            Database::execute(
                'UPDATE users SET role=?,sede_id=?,updated_at=NOW() WHERE id=?',
                [$role, $sedeId ?: null, $id]
            );
            Database::execute('DELETE FROM user_departments WHERE user_id=?', [$id]);
            foreach ($departmentsSelected as $departmentCode) {
                Database::execute(
                    'INSERT INTO user_departments(user_id,cod_dd) VALUES(?,?)',
                    [$id, $departmentCode]
                );
            }
            $pdo->commit();

            $after = [
                'role'=>$role,
                'sede_id'=>$sedeId ?: null,
                'departments'=>$departmentsSelected,
            ];
            audit('update_user_scope', 'user', $id, $before, $after);
            Auth::forgetCachedUser();
            flash('success', 'El rol y el alcance territorial del usuario fueron actualizados.');
            redirect('usuarios');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $reference = log_exception_reference($e, 'user_scope_update');
            flash('danger', safe_error_message(
                'No fue posible actualizar el perfil del usuario',
                $reference
            ));
            redirect('usuario_editar', ['id'=>$id]);
        }
    }

    $departmentChecks = '<div class="checkbox-list">';
    foreach ($departments as $department) {
        $code = (string)$department['cod_dd'];
        $checked = in_array($code, $assignedDepartments, true) ? ' checked' : '';
        $departmentChecks .= '<label><input type="checkbox" name="departments[]" value="'
            . e($code) . '"' . $checked . '> '
            . e($code . ' · ' . $department['departamento']) . '</label>';
    }
    $departmentChecks .= '</div>';

    $roleOptions = [
        'registrador'=>'Registrador — una sede',
        'formador'=>'Formador — departamento(s)',
        'admin_gi'=>'Admin GI — todo el país',
    ];
    $roleSelect = '<label class="field"><span>Rol <span class="text-danger">*</span></span>'
        . '<select name="role" data-user-role required>';
    foreach ($roleOptions as $value=>$label) {
        $roleSelect .= '<option value="' . e($value) . '"'
            . ((string)$user['role'] === $value ? ' selected' : '') . '>'
            . e($label) . '</option>';
    }
    $roleSelect .= '</select></label>';

    $content = '<div class="card"><div class="kicker">Perfil de usuario</div>'
        . '<h3>Modificar rol y alcance</h3>'
        . '<div class="note"><strong>' . e($user['name']) . '</strong><br>'
        . e($user['email']) . '</div>'
        . '<form method="post" data-user-form>' . csrf_field()
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<div class="form-grid">' . $roleSelect . '</div>'
        . '<div data-role-panel="registrador">'
        . '<h4>Sede asignada al Registrador</h4>'
        . module_sede_selector_fields(
            $sedes,
            (int)($user['sede_id'] ?? 0),
            'user_edit_scope',
            'sede_id',
            'Sede del Registrador'
        )
        . '</div>'
        . '<div data-role-panel="formador" hidden>'
        . '<h4>Departamentos asignados al Formador</h4>'
        . $departmentChecks
        . '</div>'
        . '<div data-role-panel="admin_gi" hidden>'
        . '<div class="note">El Admin GI tendrá cobertura nacional y no requiere sede ni departamentos.</div>'
        . '</div>'
        . '<div class="form-actions">'
        . '<button class="btn btn-success" type="submit">Guardar cambios</button>'
        . '<a class="btn btn-secondary" href="' . e(route_url('usuarios')) . '">Cancelar</a>'
        . '</div></form></div>';

    render_page('Editar perfil de usuario', $content, [
        'subtitle'=>'Modificación de rol, sede y departamentos autorizados.',
    ]);
}


function users_template_page(): never
{
    Auth::requireRole('admin_gi');
    $sedes = Database::fetchAll('SELECT identificador,cod_dd,departamento,municipio,tipo_sede,nombre_sede FROM sedes ORDER BY tipo_sede,departamento,municipio,nombre_sede');

    $userRows = [[
        'Nombre Completo','Correo Electronico','Contrasena Inicial','Rol','Tipo de Sede','Codigo Departamento','Departamento','Municipio','Identificador Sede','Nombre Sede','Departamentos Formador','Activo'
    ]];
    $instructionRows = [
        ['Campo','Instrucción'],
        ['Rol','Valores permitidos: Registrador, Formador o Admin GI.'],
        ['Registrador','Diligencie Identificador Sede. También puede diligenciar Tipo de Sede, Departamento, Municipio y Nombre Sede.'],
        ['Formador','Diligencie Departamentos Formador con los códigos separados por coma; ejemplo: 13, 25, 44.'],
        ['Admin GI','No requiere sede ni departamentos.'],
        ['Contraseña Inicial','Mínimo 8 caracteres. El usuario deberá cambiarla según la política definida por la Entidad.'],
        ['Activo','Use SI o NO. Si se deja vacío, se crea activo.'],
        ['Importante','No cambie los nombres de las columnas de la hoja Usuarios. Elimine filas de ejemplo antes de importar.'],
    ];
    $catalogRows = [['Tipo de Sede','Codigo Departamento','Departamento','Municipio','Identificador Sede','Nombre Sede']];
    foreach ($sedes as $sede) {
        $catalogRows[] = [$sede['tipo_sede'],$sede['cod_dd'],$sede['departamento'],$sede['municipio'],$sede['identificador'],$sede['nombre_sede']];
    }

    XlsxWriter::download('plantilla_importacion_usuarios_sivi_rnec_' . date('Ymd') . '.xlsx', [
        ['name' => 'Usuarios', 'rows' => $userRows, 'header_row' => 1, 'freeze_row' => 1, 'autofilter' => true],
        ['name' => 'Instrucciones', 'rows' => $instructionRows, 'header_row' => 1, 'freeze_row' => 1, 'autofilter' => false],
        ['name' => 'Catalogo Sedes', 'rows' => $catalogRows, 'header_row' => 1, 'freeze_row' => 1, 'autofilter' => true],
    ]);
}

function users_import_page(): void
{
    Auth::requireRole('admin_gi');
    if (!request_method('POST')) redirect('usuarios');
    verify_csrf();
    if (empty($_FILES['users_file']['tmp_name']) || !is_uploaded_file($_FILES['users_file']['tmp_name'])) {
        flash('danger', 'Seleccione un archivo XLSX válido.');
        redirect('usuarios');
    }
    $originalName = (string)($_FILES['users_file']['name'] ?? 'usuarios.xlsx');
    if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'xlsx') {
        flash('danger', 'La plantilla debe estar en formato .xlsx.');
        redirect('usuarios');
    }
    try {
        UploadSecurity::validateXlsx((array)$_FILES['users_file'], 25 * 1024 * 1024);
        $result = UserImporter::import((string)$_FILES['users_file']['tmp_name']);
        $_SESSION['user_import_errors'] = $result['errors'];
        $message = $result['created'] . ' usuario(s) creados.';
        if ($result['errors']) $message .= ' ' . count($result['errors']) . ' fila(s) presentaron novedades y no fueron importadas.';
        flash($result['created'] > 0 ? 'success' : 'danger', $message);
        audit('import_users', 'users', null, null, ['filename' => $originalName, 'created' => $result['created'], 'errors' => count($result['errors'])]);
    } catch (Throwable $e) {
        $reference = log_exception_reference($e, 'users_bulk_import');
        flash('danger', safe_error_message('No fue posible importar los usuarios', $reference));
    }
    redirect('usuarios');
}

function user_status_page(): void
{
    Auth::requireRole(['admin_gi','superadmin']);verify_get_csrf();$id=(int)($_GET['id'] ?? 0);if($id===(int)Auth::user()['id']){flash('danger','No puede desactivar su propio usuario.');redirect('usuarios');}$u=Database::fetchOne('SELECT * FROM users WHERE id=?',[$id]);if($u){Database::execute('UPDATE users SET active=IF(active=1,0,1) WHERE id=?',[$id]);audit('toggle_user','user',$id,['active'=>$u['active']],['active'=>!$u['active']]);flash('success','El estado del usuario fue actualizado.');}redirect('usuarios');
}

function user_password_page(): void
{
    Auth::requireRole(['admin_gi','superadmin','formador']);
    $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    if ($id < 1 || !Scope::canResetUserPassword($id)) {
        http_response_code(403);
        render_error('Acceso denegado', 'No puede restablecer la contraseña de este usuario.');
        return;
    }

    $target = Database::fetchOne(
        'SELECT u.id,u.name,u.email,u.role,u.active,s.identificador,s.nombre_sede,s.departamento,s.municipio FROM users u LEFT JOIN sedes s ON s.id=u.sede_id WHERE u.id=?',
        [$id]
    );
    if (!$target) {
        http_response_code(404);
        render_error('Usuario no encontrado', 'El usuario solicitado no existe.');
        return;
    }

    if (request_method('POST')) {
        verify_csrf();
        $password = (string)($_POST['new_password'] ?? '');
        $confirmation = (string)($_POST['confirm_password'] ?? '');
        $errors = [];
        if (strlen($password) < 8) $errors[] = 'La nueva contraseña debe tener mínimo 8 caracteres.';
        if (strlen($password) > 128) $errors[] = 'La nueva contraseña no puede superar 128 caracteres.';
        if ($password !== $confirmation) $errors[] = 'La confirmación de la contraseña no coincide.';
        if ($errors) {
            flash('danger', implode(' ', $errors));
            redirect('usuario_clave', ['id' => $id]);
        }

        try {
            Database::execute('UPDATE users SET password_hash=?,updated_at=NOW() WHERE id=?', [password_hash($password, PASSWORD_DEFAULT), $id]);
            Auth::clearLoginThrottleForEmail((string)$target['email']);
            audit('reset_user_password', 'user', $id, null, [
                'target_email' => $target['email'],
                'target_role' => $target['role'],
                'target_sede' => $target['identificador'],
            ]);
            flash('success', 'La contraseña de ' . $target['name'] . ' fue restablecida correctamente.');
            redirect('usuarios');
        } catch (Throwable $e) {
            $reference = log_exception_reference($e, 'user_password_reset');
            flash('danger', safe_error_message('No fue posible restablecer la contraseña', $reference));
            redirect('usuario_clave', ['id' => $id]);
        }
    }

    $scope = $target['role'] === 'registrador'
        ? trim((string)$target['identificador'] . ' · ' . (string)$target['nombre_sede'] . ' · ' . (string)$target['municipio'] . ' · ' . (string)$target['departamento'])
        : role_label((string)$target['role']);
    $content = '<div class="card"><div class="kicker">Gestión de acceso</div><h3>Restablecer contraseña</h3>'
        . '<p><strong>' . e($target['name']) . '</strong><br><span class="muted">' . e($target['email']) . '</span></p>'
        . '<p>Alcance: ' . e($scope) . '</p>'
        . '<div class="note">La contraseña debe comunicarse al usuario por un canal seguro. Esta acción quedará registrada en la auditoría.</div>'
        . '<form method="post">' . csrf_field() . '<input type="hidden" name="id" value="' . e($id) . '">'
        . '<div class="form-grid">'
        . field('new_password', 'Nueva contraseña', '', 'password', ['required' => true, 'placeholder' => 'Mínimo 8 caracteres', 'attributes' => ['autocomplete' => 'new-password']])
        . field('confirm_password', 'Confirmar nueva contraseña', '', 'password', ['required' => true, 'attributes' => ['autocomplete' => 'new-password']])
        . '</div><div class="form-actions"><button class="btn" type="submit">Restablecer contraseña</button><a class="btn btn-secondary" href="' . e(route_url('usuarios')) . '">Cancelar</a></div></form></div>';
    render_page('Restablecer contraseña', $content, ['subtitle' => 'Gestión segura de credenciales según alcance territorial.']);
}


function workflow_page(): void
{
    if (Auth::is('registrador')) {
        http_response_code(403);
        render_error('Acceso denegado', 'Este módulo corresponde al seguimiento territorial.');
        return;
    }
    $campaignId = selected_campaign_id();
    $selectedSedeId = (int)($_GET['sede_id'] ?? 0);
    if ($selectedSedeId > 0 && !Scope::canAccessSede($selectedSedeId)) {
        render_error('Acceso denegado', 'La sede seleccionada no pertenece a su alcance territorial.');
        return;
    }
    [$where,$params] = Scope::sedeCondition('s');
    $rows = Database::fetchAll(
        "SELECT cs.*,s.identificador,s.cod_dd,s.departamento,s.municipio,s.tipo_sede,s.nombre_sede,
        (SELECT COUNT(*) FROM equipment e WHERE e.current_sede_id=s.id AND e.active=1) total_equipos,
        (SELECT COUNT(*) FROM equipment_validations ev WHERE ev.campaign_id=cs.campaign_id AND ev.reported_by_sede_id=s.id AND ev.validation_status<>'pendiente') validados,
        (SELECT COUNT(*) FROM incidents i WHERE i.campaign_id=cs.campaign_id AND i.sede_id=s.id AND i.status NOT IN ('resuelta','cerrada')) novedades_abiertas
        FROM campaign_sedes cs JOIN sedes s ON s.id=cs.sede_id
        WHERE cs.campaign_id=? AND {$where}
        ORDER BY s.departamento,s.municipio,s.nombre_sede",
        array_merge([$campaignId],$params)
    );
    $selectorSedes = array_map(static fn(array $r): array => [
        'id'=>(int)$r['sede_id'],'identificador'=>$r['identificador'],'cod_dd'=>$r['cod_dd'],
        'departamento'=>$r['departamento'],'municipio'=>$r['municipio'],'tipo_sede'=>$r['tipo_sede'],'nombre_sede'=>$r['nombre_sede'],
    ], $rows);

    $table = '<div class="card"><div class="kicker">Flujo de aprobación</div><h3>Seguimiento de la sede seleccionada</h3>';
    if (!$rows) {
        $table .= empty_state('Sin sedes para seguimiento', 'La campaña seleccionada no tiene sedes disponibles dentro de su alcance.');
    } else {
        $table .= '<div class="table-wrap"><table class="table table-hover align-middle"><thead><tr><th>Sede</th><th>Avance</th><th>Novedades</th><th>Estado</th><th>Fechas</th><th>Acciones</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $pct = (int)$r['total_equipos'] > 0 ? round(((int)$r['validados']/(int)$r['total_equipos'])*100,1) : 0;
            $actions = '';
            if (in_array($r['status'],['enviado','en_revision'],true)) {
                $actions .= '<a class="btn btn-sm btn-success" href="' . e(route_url('seguimiento_accion',['id'=>$r['sede_id'],'sede_id'=>$r['sede_id'],'campaign_id'=>$campaignId,'action'=>'approve','csrf'=>csrf_token()])) . '" data-confirm="¿Aprobar la validación de esta sede?">Aprobar</a> '
                    . '<a class="btn btn-sm btn-warning" href="' . e(route_url('seguimiento_accion',['id'=>$r['sede_id'],'sede_id'=>$r['sede_id'],'campaign_id'=>$campaignId,'action'=>'return','csrf'=>csrf_token()])) . '">Devolver</a>';
            }
            if (Auth::is('admin_gi') && $r['status']==='aprobado') {
                $actions .= ' <a class="btn btn-sm btn-dark" href="' . e(route_url('seguimiento_accion',['id'=>$r['sede_id'],'sede_id'=>$r['sede_id'],'campaign_id'=>$campaignId,'action'=>'close','csrf'=>csrf_token()])) . '" data-confirm="¿Cerrar definitivamente esta sede?">Cerrar</a>';
            }
            $hidden = $selectedSedeId === (int)$r['sede_id'] ? '' : ' hidden';
            $table .= '<tr data-sede-row="' . (int)$r['sede_id'] . '"' . $hidden . '><td><strong>' . e($r['identificador'].' · '.$r['nombre_sede']) . '</strong><br><span class="muted">' . e($r['tipo_sede'].' · '.$r['departamento'].' · '.$r['municipio']) . '</span></td><td><div class="progress"><span class="' . progress_width_class(min(100,$pct)) . '"></span></div><small>' . e($r['validados'].'/'.$r['total_equipos'].' · '.$pct.'%') . '</small></td><td>' . ($r['novedades_abiertas'] ? '<span class="badge text-bg-warning">'.e($r['novedades_abiertas']).' abiertas</span>' : '<span class="badge text-bg-success">Sin abiertas</span>') . '</td><td>' . status_badge($r['status']) . '</td><td><small>Enviada: ' . e($r['submitted_at']?:'—') . '<br>Aprobada: ' . e($r['approved_at']?:'—') . '</small></td><td class="nowrap">' . ($actions ?: '—') . '</td></tr>';
        }
        $table .= '</tbody></table></div><div class="empty-state" data-sede-filter-empty hidden><h3>Sin seguimiento disponible</h3><p>No se encontró información para la sede seleccionada.</p></div>';
    }
    $table .= '</div>';

    $content = '<div data-sede-gate><div class="card sede-selection-card"><div class="kicker">Consulta territorial</div><h3>Seleccione la sede que desea revisar</h3><p class="sede-selection-help">Seleccione Departamento, Municipio, Tipo de sede y Sede. Después se mostrará el avance y las acciones de aprobación.</p>'
        . module_sede_selector_fields($selectorSedes, $selectedSedeId, 'workflow_scope', 'workflow_sede_id', 'Sede', ['gate'=>true])
        . '</div><div class="sede-dependent-panel" data-sede-dependent' . ($selectedSedeId > 0 ? '' : ' hidden') . '><div class="sede-selected-banner"><div><div class="kicker">Sede seleccionada</div><strong data-selected-sede-name></strong><small data-selected-sede-location></small></div></div>'
        . $table . '</div></div>';
    render_page('Seguimiento y aprobación', $content, ['subtitle'=>'Selección territorial previa y control progresivo de la campaña.']);
}

function workflow_action_page(): void
{
    if (Auth::is('registrador')) { http_response_code(403); exit('Acceso denegado'); }
    verify_get_csrf();
    $sedeId = (int)($_GET['id'] ?? 0);
    $campaignId = (int)($_GET['campaign_id'] ?? 0);
    $action = (string)($_GET['action'] ?? '');
    if (!Scope::canAccessSede($sedeId)) { http_response_code(403); exit('Acceso denegado'); }
    $map = ['approve'=>['aprobado','approved_at'],'return'=>['devuelto','reopened_at'],'close'=>['cerrado','approved_at']];
    if (!isset($map[$action]) || ($action==='close' && !Auth::is('admin_gi'))) {
        flash('danger','Acción no autorizada.');
        redirect('seguimiento', ['campaign_id'=>$campaignId,'sede_id'=>$sedeId]);
    }
    [$new,$dateField] = $map[$action];
    $old = Database::fetchOne('SELECT status FROM campaign_sedes WHERE campaign_id=? AND sede_id=?',[$campaignId,$sedeId]);
    Database::execute("UPDATE campaign_sedes SET status=?, {$dateField}=NOW() WHERE campaign_id=? AND sede_id=?",[$new,$campaignId,$sedeId]);
    Database::execute('INSERT INTO campaign_status_history(campaign_id,sede_id,previous_status,new_status,changed_by,notes) VALUES(?,?,?,?,?,?)',[$campaignId,$sedeId,$old['status']??null,$new,Auth::id(),$action==='return'?'Devuelta para corrección':null]);
    audit('campaign_status_changed','campaign_sede',$sedeId,$old,['status'=>$new,'campaign_id'=>$campaignId]);
    flash('success','Estado de la sede actualizado.');
    redirect('seguimiento', ['campaign_id'=>$campaignId,'sede_id'=>$sedeId]);
}

function inconsistencies_page(): void
{
    if(Auth::is('registrador')){http_response_code(403);render_error('Acceso denegado','Este módulo requiere perfil territorial o nacional.');return;}
    [$where,$params]=Scope::sedeCondition('s');
    $metrics=[];
    $plateSqlRegex='^[0-9]{3}-[0-9]{'.PlatePolicy::suffixDigits(placa_rnec_total_characters()).'}$';
    $metrics['Placas duplicadas']=(int)(Database::fetchOne("SELECT COUNT(*) total FROM (SELECT e.placa_rnec FROM equipment e LEFT JOIN sedes s ON s.id=e.current_sede_id WHERE e.active=1 AND e.placa_rnec IS NOT NULL AND e.placa_rnec<>'' AND {$where} GROUP BY e.placa_rnec HAVING COUNT(*)>1) x",$params)['total']??0);
    $metrics['Seriales duplicados']=(int)(Database::fetchOne("SELECT COUNT(*) total FROM (SELECT e.serial_number FROM equipment e LEFT JOIN sedes s ON s.id=e.current_sede_id WHERE e.active=1 AND e.serial_number IS NOT NULL AND e.serial_number<>'' AND {$where} GROUP BY e.serial_number HAVING COUNT(*)>1) x",$params)['total']??0);
    $metrics['Serial pendiente por duplicado']=(int)(Database::fetchOne("SELECT COUNT(*) total FROM equipment e LEFT JOIN sedes s ON s.id=e.current_sede_id WHERE e.active=1 AND e.serial_review_required=1 AND e.serial_review_reason='duplicado' AND {$where}",$params)['total']??0);
    $metrics['Sin sede']=(int)(Database::fetchOne("SELECT COUNT(*) total FROM equipment e LEFT JOIN sedes s ON s.id=e.current_sede_id WHERE e.active=1 AND e.current_sede_id IS NULL AND {$where}",$params)['total']??0);
    $metrics['Placa inválida']=(int)(Database::fetchOne("SELECT COUNT(*) total FROM equipment e LEFT JOIN sedes s ON s.id=e.current_sede_id WHERE e.active=1 AND e.placa_rnec IS NOT NULL AND e.placa_rnec<>'' AND e.placa_rnec NOT REGEXP ? AND {$where}",array_merge([$plateSqlRegex],$params))['total']??0);
    $metrics['Sin coincidencia Almacén']=(int)(Database::fetchOne("SELECT COUNT(*) total FROM equipment e LEFT JOIN sedes s ON s.id=e.current_sede_id WHERE e.active=1 AND e.warehouse_match_status IN ('no_encontrada','ambigua','sin_serial') AND {$where}",$params)['total']??0);
    $content='<div class="metrics">';foreach($metrics as $k=>$v)$content.=metric_card($k,$v,'Requieren revisión',$v?'orange':'green');$content.='</div>';
    $rows=Database::fetchAll("SELECT e.id,e.name,e.asset_category,e.placa_rnec,e.serial_number,e.serial_review_required,e.serial_review_reason,e.warehouse_match_status,s.identificador,s.departamento,s.municipio,s.nombre_sede FROM equipment e LEFT JOIN sedes s ON s.id=e.current_sede_id WHERE e.active=1 AND {$where} AND (e.current_sede_id IS NULL OR (e.placa_rnec IS NOT NULL AND e.placa_rnec<>'' AND e.placa_rnec NOT REGEXP ?) OR e.warehouse_match_status IN ('no_encontrada','ambigua','sin_serial') OR e.placa_rnec IN (SELECT placa_rnec FROM equipment WHERE active=1 AND placa_rnec IS NOT NULL AND placa_rnec<>'' GROUP BY placa_rnec HAVING COUNT(*)>1) OR e.serial_review_required=1 OR e.serial_number IN (SELECT serial_number FROM equipment WHERE active=1 AND serial_number IS NOT NULL AND serial_number<>'' GROUP BY serial_number HAVING COUNT(*)>1)) ORDER BY s.departamento,s.municipio,e.name LIMIT 500",array_merge($params,[$plateSqlRegex]));
    $content.='<div class="card"><h3>Bandeja de inconsistencias</h3><p class="muted">Se muestran hasta 500 registros prioritarios.</p><div class="table-wrap"><table class="table table-hover"><thead><tr><th>Activo</th><th>Placa / Serial</th><th>Sede</th><th>Conciliación</th><th></th></tr></thead><tbody>';
    foreach($rows as $r){$serialLabel=$r['serial_number']?:(((int)($r['serial_review_required']??0)===1&&($r['serial_review_reason']??'')==='duplicado')?'Pendiente por serial duplicado':'Sin serial');$content.='<tr><td><strong>'.e($r['name']).'</strong><br>'.e(asset_category_label($r['asset_category'])).'</td><td>'.e($r['placa_rnec']?:'Sin placa').'<br><span class="muted">'.e($serialLabel).'</span></td><td>'.e(($r['identificador']?:'Sin sede').' · '.($r['nombre_sede']?:'Pendiente')).'<br><span class="muted">'.e(($r['departamento']?:'').' '.($r['municipio']?:'')).'</span></td><td>'.status_badge($r['warehouse_match_status']).'</td><td><a class="btn btn-sm btn-outline-primary" href="'.e(route_url('equipo_validar',['id'=>$r['id']])).'">Revisar</a> <a class="btn btn-sm btn-outline-secondary" href="'.e(route_url('historial_equipo',['id'=>$r['id']])).'">Historial</a></td></tr>';}
    $content.='</tbody></table></div></div>';render_page('Inconsistencias',$content,['subtitle'=>'Control de calidad entre GLPI, Almacén y la validación territorial.']);
}

function incidents_page(): void
{
    $campaignId = selected_campaign_id();
    if($campaignId<1){render_error('Campaña requerida','Seleccione una campaña para gestionar novedades.');return;}
    $requiresSelection = profile_requires_sede_selection();
    $selectedSedeId = Auth::is('registrador')
        ? (int)(Auth::user()['sede_id'] ?? 0)
        : (int)($_POST['sede_id'] ?? $_GET['sede_id'] ?? 0);
    if (Auth::is('registrador') && $selectedSedeId < 1) {
        render_error('Sede no asignada', 'Su usuario Registrador no tiene una sede asociada. Solicite la corrección al administrador.');
        return;
    }
    [$where,$params] = Scope::sedeCondition('s');
    $sedes = Database::fetchAll("SELECT s.id,s.identificador,s.cod_dd,s.departamento,s.municipio,s.tipo_sede,s.nombre_sede FROM sedes s WHERE {$where} ORDER BY s.tipo_sede,s.departamento,s.municipio,s.nombre_sede",$params);
    if ($selectedSedeId > 0 && !Scope::canAccessSede($selectedSedeId)) {
        render_error('Acceso denegado','La sede seleccionada no pertenece a su alcance territorial.');
        return;
    }
    $types = ['equipo_no_encontrado'=>'Equipo no encontrado','no_pertenece'=>'No pertenece a la sede','equipo_adicional'=>'Equipo adicional','placa'=>'Novedad de placa','sin_placa'=>'Equipo sin placa','serial'=>'Novedad de serial','serial_ilegible'=>'Serial ilegible','traslado'=>'Traslado','cambio_ubicacion'=>'Cambio de ubicación','cambio_responsable'=>'Cambio de responsable','reparacion'=>'Reparación','mantenimiento'=>'En mantenimiento','baja'=>'Pendiente de baja','duplicado'=>'Posible duplicado','datos'=>'Información incorrecta','otro'=>'Otra novedad'];

    if (request_method('POST')) {
        verify_csrf();
        if(!campaign_accepts_responses($campaignId)){flash('danger','La campaña no está activa para recibir novedades.');redirect('novedades',['campaign_id'=>$campaignId]);}
        $sedeId = $selectedSedeId;
        $equipmentId = (int)($_POST['equipment_id'] ?? 0);
        if ($sedeId < 1 || !Scope::canAccessSede($sedeId) || !Database::fetchOne('SELECT 1 ok FROM campaign_sedes WHERE campaign_id=? AND sede_id=?',[$campaignId,$sedeId])) {
            flash('danger','Seleccione una sede incluida en la campaña.');
            redirect('novedades',['campaign_id'=>$campaignId]);
        }
        if ($equipmentId > 0) {
            $equipment = Database::fetchOne('SELECT id,current_sede_id FROM equipment WHERE id=? AND active=1',[$equipmentId]);
            if (!$equipment || (int)$equipment['current_sede_id'] !== $sedeId || !Scope::canAccessEquipment($equipmentId) || !campaign_equipment_exists($campaignId,$equipmentId)) {
                flash('danger','El equipo seleccionado no pertenece a la sede indicada.');
                redirect('novedades',['campaign_id'=>$campaignId,'sede_id'=>$sedeId]);
            }
        }
        $type = trim((string)($_POST['incident_type'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        if (!isset($types[$type]) || $description==='') {
            flash('danger','Seleccione el tipo y describa la novedad.');
        } else {
            Database::execute('INSERT INTO incidents(campaign_id,sede_id,equipment_id,incident_type,priority,status,description,reported_by) VALUES(?,?,?,?,?,?,?,?)',[$campaignId,$sedeId,$equipmentId?:null,$type,$_POST['priority']??'media','abierta',$description,Auth::id()]);
            $id = (int)Database::connection()->lastInsertId();
            audit('incident_created','incident',$id,null,['type'=>$type,'sede_id'=>$sedeId,'equipment_id'=>$equipmentId?:null]);
            flash('success','Novedad registrada para la sede seleccionada.');
        }
        redirect('novedades',['campaign_id'=>$campaignId,'sede_id'=>$sedeId]);
    }

    $equipment = Database::fetchAll("SELECT e.id,e.current_sede_id,e.name,e.placa_rnec,e.serial_number FROM campaign_equipment ce JOIN equipment e ON e.id=ce.equipment_id JOIN sedes s ON s.id=ce.sede_id WHERE ce.campaign_id=? AND {$where} ORDER BY s.departamento,s.municipio,s.nombre_sede,e.name",array_merge([$campaignId],$params));
    $rows = Database::fetchAll("SELECT i.*,s.identificador,s.nombre_sede,s.departamento,s.municipio,e.name equipment_name,u.name reporter_name FROM incidents i JOIN sedes s ON s.id=i.sede_id LEFT JOIN equipment e ON e.id=i.equipment_id LEFT JOIN users u ON u.id=i.reported_by WHERE i.campaign_id=? AND {$where} ORDER BY FIELD(i.status,'abierta','en_gestion','resuelta','cerrada'),FIELD(i.priority,'critica','alta','media','baja'),i.id DESC",array_merge([$campaignId],$params));

    $equipmentSelect = '<div class="field mb-3"><label class="form-label" for="incident-equipment">Equipo relacionado</label><select class="form-select" id="incident-equipment" name="equipment_id" data-equipment-sede-select' . ($requiresSelection && $selectedSedeId < 1 ? ' disabled' : '') . '><option value="">Novedad general de la sede</option>';
    foreach ($equipment as $eq) {
        $hidden = $selectedSedeId > 0 && $selectedSedeId === (int)$eq['current_sede_id'] ? '' : ($requiresSelection ? ' hidden disabled' : '');
        $label = trim((string)$eq['name']) . ' · ' . (($eq['placa_rnec'] ?: $eq['serial_number']) ?: 'sin placa/serial');
        $equipmentSelect .= '<option value="' . (int)$eq['id'] . '" data-sede-id="' . (int)$eq['current_sede_id'] . '"' . $hidden . '>' . e($label) . '</option>';
    }
    $equipmentSelect .= '</select><div class="form-text">Opcional. Solo se muestran equipos de la sede seleccionada.</div></div>';

    $form = '<form method="post">' . csrf_field() . '<input type="hidden" name="campaign_id" value="' . e($campaignId) . '"><input type="hidden" name="sede_id" value="' . e($selectedSedeId) . '" data-sede-hidden><div class="form-grid">'
        . $equipmentSelect
        . field('incident_type','Tipo de novedad','', 'select',['required'=>true,'choices'=>[''=>'Seleccione']+$types])
        . field('priority','Prioridad','media','select',['choices'=>['baja'=>'Baja','media'=>'Media','alta'=>'Alta','critica'=>'Crítica']])
        . field('description','Descripción','', 'textarea',['required'=>true,'wide'=>true])
        . '</div><button class="btn btn-primary">Registrar novedad</button></form>';

    $table = '<div class="card"><h3>Novedades registradas en la sede</h3>';
    if (!$rows) {
        $table .= empty_state('Sin novedades','No existen novedades registradas en la campaña.');
    } else {
        $table .= '<div class="table-wrap"><table class="table table-hover"><thead><tr><th>Novedad</th><th>Sede / Equipo</th><th>Prioridad</th><th>Estado</th><th>Responsable</th><th>Acciones</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $actions='';
            if (!Auth::is('registrador') && in_array($r['status'],['abierta','en_gestion'],true)) {
                $actions='<a class="btn btn-sm btn-outline-primary" href="'.e(route_url('novedad_accion',['id'=>$r['id'],'action'=>'manage','csrf'=>csrf_token()])).'">Gestionar</a> <a class="btn btn-sm btn-success" href="'.e(route_url('novedad_accion',['id'=>$r['id'],'action'=>'resolve','csrf'=>csrf_token()])).'">Resolver</a>';
            }
            $hidden = $requiresSelection && ($selectedSedeId < 1 || $selectedSedeId !== (int)$r['sede_id']) ? ' hidden' : '';
            $table .= '<tr data-sede-row="' . (int)$r['sede_id'] . '"' . $hidden . '><td><strong>'.e($types[$r['incident_type']]??$r['incident_type']).'</strong><br><span class="muted">'.e($r['description']).'</span></td><td>'.e($r['identificador'].' · '.$r['nombre_sede']).'<br><span class="muted">'.e($r['equipment_name']?:'Novedad general').'</span></td><td>'.status_badge($r['priority']).'</td><td>'.status_badge($r['status']).'</td><td>'.e($r['reporter_name']).'</td><td>'.($actions?:'—').'</td></tr>';
        }
        $table .= '</tbody></table></div><div class="empty-state" data-sede-filter-empty hidden><h3>Sin novedades</h3><p>La sede seleccionada no tiene novedades registradas.</p></div>';
    }
    $table .= '</div>';

    $body = '<div class="sede-selected-banner"><div><div class="kicker">Sede seleccionada</div><strong data-selected-sede-name></strong><small data-selected-sede-location></small></div></div><div class="card"><div class="kicker">Centro de novedades</div><h3>Registrar novedad</h3>' . $form . '</div>' . $table;
    if ($requiresSelection) {
        $content = '<div data-sede-gate><div class="card sede-selection-card"><div class="kicker">Ubicación obligatoria</div><h3>Seleccione la sede antes de registrar la novedad</h3><p class="sede-selection-help">Orden requerido: Departamento, Municipio, Tipo de sede y Sede.</p>'
            . module_sede_selector_fields($sedes,$selectedSedeId,'incident_scope','incident_scope_sede','Sede',['gate'=>true])
            . '</div><div class="sede-dependent-panel" data-sede-dependent' . ($selectedSedeId>0?'':' hidden') . '>' . $body . '</div></div>';
    } else {
        $ownSede = Database::fetchOne('SELECT id,identificador,cod_dd,departamento,municipio,tipo_sede,nombre_sede FROM sedes WHERE id=?',[$selectedSedeId]) ?: [];
        $content = fixed_sede_summary($ownSede) . '<div class="card"><div class="kicker">Centro de novedades</div><h3>Registrar novedad</h3>' . $form . '</div>' . $table;
    }
    render_page('Centro de novedades',$content,['subtitle'=>'Selección territorial previa, registro y seguimiento de hallazgos.']);
}

function incident_action_page(): void
{
    if(Auth::is('registrador')){http_response_code(403);exit('Acceso denegado');}verify_get_csrf();$id=(int)($_GET['id']??0);$action=(string)($_GET['action']??'');$row=Database::fetchOne('SELECT i.*,s.id sid FROM incidents i JOIN sedes s ON s.id=i.sede_id WHERE i.id=?',[$id]);if(!$row||!Scope::canAccessSede((int)$row['sid'])){http_response_code(403);exit('Acceso denegado');}$new=$action==='resolve'?'resuelta':'en_gestion';Database::execute('UPDATE incidents SET status=?,assigned_to=?,resolved_at=IF(?="resuelta",NOW(),resolved_at),updated_at=NOW() WHERE id=?',[$new,Auth::id(),$new,$id]);Database::execute('INSERT INTO incident_comments(incident_id,user_id,comment,new_status) VALUES(?,?,?,?)',[$id,Auth::id(),$action==='resolve'?'Novedad marcada como resuelta.':'Novedad tomada en gestión.',$new]);audit('incident_status_changed','incident',$id,['status'=>$row['status']],['status'=>$new]);flash('success','Novedad actualizada.');redirect('novedades',['campaign_id'=>$row['campaign_id'],'sede_id'=>$row['sede_id']]);
}

function equipment_history_page(): void
{
    $id=(int)($_GET['id']??0);$eq=Database::fetchOne('SELECT e.*,s.identificador,s.nombre_sede FROM equipment e LEFT JOIN sedes s ON s.id=e.current_sede_id WHERE e.id=?',[$id]);if(!$eq||($eq['current_sede_id']&&!Scope::canAccessSede((int)$eq['current_sede_id']))){http_response_code(404);render_error('Activo no encontrado','No está disponible dentro de su alcance.');return;}
    $history=Database::fetchAll('SELECT h.*,u.name user_name,so.nombre_sede origin_name,sd.nombre_sede destination_name FROM equipment_history h LEFT JOIN users u ON u.id=h.changed_by LEFT JOIN sedes so ON so.id=h.origin_sede_id LEFT JOIN sedes sd ON sd.id=h.destination_sede_id WHERE h.equipment_id=? ORDER BY h.id DESC',[$id]);
    $audits=Database::fetchAll("SELECT a.*,u.name user_name FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id WHERE a.entity_type IN ('equipment','equipment_validation') AND a.entity_id=? ORDER BY a.id DESC LIMIT 100",[$id]);
    $content='<div class="card"><div class="kicker">Activo</div><h3>'.e($eq['name']).'</h3><p>'.e(asset_category_label($eq['asset_category']).' · Placa '.($eq['placa_rnec']?:'sin registrar').' · Serial '.($eq['serial_number']?:'sin registrar')).'</p></div><div class="card"><h3>Línea de tiempo</h3>';
    if(!$history&&!$audits)$content.=empty_state('Sin movimientos','Todavía no existen eventos adicionales para este activo.');
    foreach($history as $h)$content.='<div class="border-start border-3 ps-3 mb-3"><strong>'.e($h['event_type']).'</strong> · '.e($h['created_at']).'<br><span class="muted">'.e($h['description']).' · '.e($h['user_name']?:'Sistema').'</span></div>';
    foreach($audits as $a)$content.='<div class="border-start border-3 ps-3 mb-3"><strong>'.e($a['action']).'</strong> · '.e($a['created_at']).'<br><span class="muted">'.e($a['user_name']?:'Sistema').'</span></div>';
    $content.='</div>';render_page('Historial del activo',$content,['subtitle'=>'Trazabilidad de validaciones, novedades y movimientos.']);
}

function quality_page(): void
{
    Auth::requireRole(['formador','admin_gi','superadmin']);
    $campaignId=selected_campaign_id();
    $ready=campaign_readiness($campaignId);
    [$where,$params]=Scope::sedeCondition('s');
    $rows=Database::fetchAll("SELECT s.id,s.identificador,s.nombre_sede,s.departamento,s.municipio,cs.status,
        (SELECT COUNT(*) FROM equipment e WHERE e.current_sede_id=s.id AND e.active=1) total_equipment,
        (SELECT COUNT(*) FROM equipment e WHERE e.current_sede_id=s.id AND e.active=1 AND NULLIF(TRIM(e.serial_number),'') IS NULL) no_serial,
        (SELECT COUNT(*) FROM equipment e WHERE e.current_sede_id=s.id AND e.active=1 AND e.ownership_type='propio' AND NULLIF(TRIM(e.placa_rnec),'') IS NULL) no_plate,
        NOT EXISTS(SELECT 1 FROM users u WHERE u.sede_id=s.id AND u.role='registrador' AND u.active=1) no_registrar,
        COALESCE(NULLIF(TRIM(s.email_contacto),''),NULLIF(TRIM(s.email_institucional),''),(SELECT NULLIF(TRIM(u.email),'') FROM users u WHERE u.sede_id=s.id AND u.role='registrador' AND u.active=1 LIMIT 1)) contact_email
        FROM campaign_sedes cs JOIN sedes s ON s.id=cs.sede_id WHERE cs.campaign_id=? AND {$where}
        ORDER BY no_registrar DESC,(contact_email IS NULL) DESC,total_equipment ASC,s.departamento,s.municipio",array_merge([$campaignId],$params));
    $content='<div class="metrics">'.metric_card('Sedes de la campaña',$ready['total'],'Cobertura incluida').metric_card('Errores críticos',$ready['criticos'],'Bloquean la publicación','red').metric_card('Advertencias',$ready['advertencias'],'Requieren revisión','amber').metric_card('Estado',$ready['apta']?'Lista':'Incompleta',$ready['apta']?'Puede publicarse':'Corrija los errores críticos',$ready['apta']?'green':'red').'</div>';
    $content.='<div class="card"><div class="toolbar"><div><div class="kicker">Control preventivo</div><h3>Calidad por sede</h3><p class="muted">Los errores críticos bloquean la publicación. Los datos faltantes de placa o serial se gestionan como advertencias para validación física.</p></div><a class="btn btn-secondary" href="'.e(route_url('inconsistencias',['campaign_id'=>$campaignId])).'">Revisar duplicados</a></div><div class="table-wrap"><table><thead><tr><th>Sede</th><th>Inventario</th><th>Registrador</th><th>Correo</th><th>Serial</th><th>Placa</th><th>Resultado</th></tr></thead><tbody>';
    foreach($rows as $r){$critical=((int)$r['total_equipment']===0)||((int)$r['no_registrar']===1)||empty($r['contact_email']);$warnings=(int)$r['no_serial']+(int)$r['no_plate'];$content.='<tr><td><strong>'.e($r['identificador'].' · '.$r['nombre_sede']).'</strong><br><span class="muted">'.e($r['departamento'].' / '.$r['municipio']).'</span></td><td>'.e($r['total_equipment']).'</td><td>'.((int)$r['no_registrar']?'<span class="badge text-bg-danger">Sin asignar</span>':'<span class="badge text-bg-success">Asignado</span>').'</td><td>'.($r['contact_email']?e($r['contact_email']):'<span class="badge text-bg-danger">Sin correo</span>').'</td><td>'.e($r['no_serial']).' faltantes</td><td>'.e($r['no_plate']).' faltantes</td><td>'.($critical?'<span class="badge text-bg-danger">Bloqueada</span>':($warnings?'<span class="badge text-bg-warning">Con advertencias</span>':'<span class="badge text-bg-success">Lista</span>')).'</td></tr>';}
    $content.='</tbody></table></div></div>';render_page('Control de calidad',$content,['subtitle'=>'Preparación y consistencia antes de publicar campañas.']);
}

function homologations_page(): void
{
    Auth::requireRole(['admin_gi','superadmin']);
    if(request_method('POST')){verify_csrf();$type=(string)($_POST['data_type']??'');$source=trim((string)($_POST['source_value']??''));$normalized=trim((string)($_POST['normalized_value']??''));$allowed=['departamento','municipio','tipo_sede','tipo_equipo','marca','modelo','estado'];if(!in_array($type,$allowed,true)||$source===''||$normalized===''){flash('danger','Complete todos los campos.');redirect('homologaciones');}Database::execute('INSERT INTO data_homologations(data_type,source_value,normalized_value,created_by) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE normalized_value=VALUES(normalized_value),active=1,updated_at=NOW()',[$type,$source,$normalized,Auth::id()]);audit('save_homologation','data_homologation',null,null,['type'=>$type,'source'=>$source,'normalized'=>$normalized]);flash('success','Regla de homologación guardada.');redirect('homologaciones');}
    $rows=Database::fetchAll('SELECT h.*,u.name user_name FROM data_homologations h LEFT JOIN users u ON u.id=h.created_by ORDER BY h.data_type,h.source_value LIMIT 500');
    $types=['departamento'=>'Departamento','municipio'=>'Municipio','tipo_sede'=>'Tipo de sede','tipo_equipo'=>'Tipo de equipo','marca'=>'Marca','modelo'=>'Modelo','estado'=>'Estado'];
    $content='<div class="card"><div class="kicker">Normalización de datos</div><h3>Nueva regla de homologación</h3><form method="post">'.csrf_field().'<div class="form-grid">'.field('data_type','Tipo de dato','','select',['required'=>true,'choices'=>[''=>'Seleccione']+$types]).field('source_value','Valor recibido','','text',['required'=>true]).field('normalized_value','Valor institucional','','text',['required'=>true]).'</div><button class="btn">Guardar regla</button></form></div><div class="card"><h3>Reglas configuradas</h3><div class="table-wrap"><table><thead><tr><th>Tipo</th><th>Valor recibido</th><th>Valor homologado</th><th>Responsable</th><th>Actualización</th></tr></thead><tbody>';
    foreach($rows as $r)$content.='<tr><td>'.e($types[$r['data_type']]??$r['data_type']).'</td><td>'.e($r['source_value']).'</td><td><strong>'.e($r['normalized_value']).'</strong></td><td>'.e($r['user_name']?:'Sistema').'</td><td>'.e($r['updated_at']).'</td></tr>';
    $content.='</tbody></table></div></div>';render_page('Homologaciones',$content,['subtitle'=>'Unificación de nombres provenientes de diferentes fuentes.']);
}

function transfers_page(): void
{
    $campaignId = selected_campaign_id();
    $requiresSelection = profile_requires_sede_selection();
    $selectedSedeId = Auth::is('registrador')
        ? (int)(Auth::user()['sede_id'] ?? 0)
        : (int)($_POST['sede_id'] ?? $_GET['sede_id'] ?? 0);
    if (Auth::is('registrador') && $selectedSedeId < 1) {
        render_error('Sede no asignada', 'Su usuario Registrador no tiene una sede asociada. Solicite la corrección al administrador.');
        return;
    }
    [$where,$params] = Scope::sedeCondition('so');
    [$sedeWhere,$sedeParams] = Scope::sedeCondition('s');
    $originSedes = Database::fetchAll("SELECT s.id,s.identificador,s.cod_dd,s.departamento,s.municipio,s.tipo_sede,s.nombre_sede FROM sedes s WHERE {$sedeWhere} ORDER BY s.tipo_sede,s.departamento,s.municipio,s.nombre_sede",$sedeParams);
    $allSedes = Database::fetchAll('SELECT id,identificador,cod_dd,departamento,municipio,tipo_sede,nombre_sede FROM sedes ORDER BY tipo_sede,departamento,municipio,nombre_sede');
    if ($selectedSedeId > 0 && !Scope::canAccessSede($selectedSedeId)) {
        render_error('Acceso denegado','La sede de origen no pertenece a su alcance territorial.');
        return;
    }

    if (request_method('POST')) {
        verify_csrf();
        $originSedeId = $selectedSedeId;
        $equipmentId = (int)($_POST['equipment_id'] ?? 0);
        $destination = (int)($_POST['destination_sede_id'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? ''));
        $eq = Database::fetchOne('SELECT * FROM equipment WHERE id=? AND active=1',[$equipmentId]);
        $destinationExists = $destination > 0 ? Database::fetchOne('SELECT id FROM sedes WHERE id=?',[$destination]) : null;
        if ($originSedeId < 1 || !Scope::canAccessSede($originSedeId)) {
            flash('danger','Seleccione primero la sede de origen.');
            redirect('traslados',['campaign_id'=>$campaignId]);
        }
        if (!$eq || (int)$eq['current_sede_id'] !== $originSedeId || !Scope::canAccessEquipment($equipmentId) || !$destinationExists || $destination === $originSedeId || $reason==='') {
            flash('danger','No fue posible registrar el traslado. Verifique el equipo, la sede destino y el motivo.');
            redirect('traslados',['campaign_id'=>$campaignId,'sede_id'=>$originSedeId]);
        }
        Database::execute("INSERT INTO equipment_transfers(campaign_id,equipment_id,origin_sede_id,destination_sede_id,reason,status,requested_by) VALUES(?,?,?,?,?,'pendiente_aprobacion',?)",[$campaignId,$equipmentId,$originSedeId,$destination,$reason,Auth::id()]);
        $id=(int)Database::connection()->lastInsertId();
        audit('request_equipment_transfer','equipment_transfer',$id,null,['equipment_id'=>$equipmentId,'origin_sede_id'=>$originSedeId,'destination_sede_id'=>$destination]);
        flash('success','Traslado enviado para aprobación.');
        redirect('traslados',['campaign_id'=>$campaignId,'sede_id'=>$originSedeId]);
    }

    $rows = Database::fetchAll("SELECT t.*,e.name,e.placa_rnec,e.serial_number,so.nombre_sede origin_name,sd.nombre_sede destination_name,u.name requester_name FROM equipment_transfers t JOIN equipment e ON e.id=t.equipment_id LEFT JOIN sedes so ON so.id=t.origin_sede_id JOIN sedes sd ON sd.id=t.destination_sede_id JOIN users u ON u.id=t.requested_by WHERE {$where} ORDER BY t.id DESC",$params);
    $equipment = Database::fetchAll("SELECT e.id,e.current_sede_id,e.name,e.placa_rnec,e.serial_number FROM equipment e JOIN sedes s ON s.id=e.current_sede_id WHERE e.active=1 AND {$sedeWhere} ORDER BY s.departamento,s.municipio,s.nombre_sede,e.name",$sedeParams);

    $equipmentSelect = '<div class="field mb-3"><label class="form-label" for="transfer-equipment">Equipo a trasladar <span class="text-danger">*</span></label><select class="form-select" id="transfer-equipment" name="equipment_id" data-equipment-sede-select required' . ($requiresSelection && $selectedSedeId<1?' disabled':'') . '><option value="">Seleccione el equipo</option>';
    foreach ($equipment as $eq) {
        $hidden = $selectedSedeId > 0 && $selectedSedeId === (int)$eq['current_sede_id'] ? '' : ($requiresSelection ? ' hidden disabled' : '');
        $label = trim((string)$eq['name']) . ' · ' . (($eq['placa_rnec'] ?: $eq['serial_number']) ?: 'sin placa/serial');
        $equipmentSelect .= '<option value="'.(int)$eq['id'].'" data-sede-id="'.(int)$eq['current_sede_id'].'"'.$hidden.'>'.e($label).'</option>';
    }
    $equipmentSelect .= '</select></div>';

    $destinationSelector = '<div class="field-wide"><div class="kicker">Sede destino</div><p class="muted">Seleccione también Departamento, Municipio, Tipo de sede y Sede destino.</p></div>'
        . module_sede_selector_fields($allSedes,0,'transfer_destination','destination_sede_id','Sede destino',['destination'=>true,'exclude_current_origin'=>true]);
    $form = '<form method="post">'.csrf_field().'<input type="hidden" name="campaign_id" value="'.e($campaignId).'"><input type="hidden" name="sede_id" value="'.e($selectedSedeId).'" data-sede-hidden><div class="form-grid">'.$equipmentSelect.'</div>'.$destinationSelector.'<div class="form-grid">'.field('reason','Motivo y soporte','', 'textarea',['required'=>true,'wide'=>true]).'</div><button class="btn">Enviar para aprobación</button></form>';

    $table = '<div class="card"><h3>Solicitudes de traslado de la sede</h3>';
    if (!$rows) {
        $table .= empty_state('Sin solicitudes','No existen traslados registrados dentro de su alcance.');
    } else {
        $table .= '<div class="table-wrap"><table><thead><tr><th>Equipo</th><th>Origen</th><th>Destino</th><th>Motivo</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $actions='';
            if (!Auth::is('registrador') && $r['status']==='pendiente_aprobacion') {
                $actions='<a class="btn btn-sm btn-success" href="'.e(route_url('traslado_accion',['id'=>$r['id'],'action'=>'approve','csrf'=>csrf_token()])).'">Aprobar y aplicar</a> <a class="btn btn-sm btn-outline-danger" href="'.e(route_url('traslado_accion',['id'=>$r['id'],'action'=>'reject','csrf'=>csrf_token()])).'">Rechazar</a>';
            }
            $hidden = $requiresSelection && ($selectedSedeId<1 || $selectedSedeId!==(int)$r['origin_sede_id']) ? ' hidden' : '';
            $table .= '<tr data-sede-row="'.(int)$r['origin_sede_id'].'"'.$hidden.'><td><strong>'.e($r['name']).'</strong><br><span class="muted">'.e($r['placa_rnec']?:$r['serial_number']).'</span></td><td>'.e($r['origin_name']).'</td><td>'.e($r['destination_name']).'</td><td>'.e($r['reason']).'</td><td>'.status_badge($r['status']).'</td><td>'.($actions?:'—').'</td></tr>';
        }
        $table .= '</tbody></table></div><div class="empty-state" data-sede-filter-empty hidden><h3>Sin solicitudes</h3><p>La sede seleccionada no tiene solicitudes de traslado.</p></div>';
    }
    $table .= '</div>';

    $body = '<div class="sede-selected-banner"><div><div class="kicker">Sede de origen</div><strong data-selected-sede-name></strong><small data-selected-sede-location></small></div></div><div class="card"><div class="kicker">Movimiento controlado</div><h3>Solicitar traslado</h3>'.$form.'</div>'.$table;
    if ($requiresSelection) {
        $content = '<div data-sede-gate><div class="card sede-selection-card"><div class="kicker">Origen obligatorio</div><h3>Seleccione la sede de origen</h3><p class="sede-selection-help">Orden requerido: Departamento, Municipio, Tipo de sede y Sede. Luego se mostrarán únicamente los equipos de esa sede.</p>'
            . module_sede_selector_fields($originSedes,$selectedSedeId,'transfer_origin','transfer_origin_scope','Sede de origen',['gate'=>true])
            . '</div><div class="sede-dependent-panel" data-sede-dependent'.($selectedSedeId>0?'':' hidden').'>'.$body.'</div></div>';
    } else {
        $ownSede = Database::fetchOne('SELECT id,identificador,cod_dd,departamento,municipio,tipo_sede,nombre_sede FROM sedes WHERE id=?',[$selectedSedeId]) ?: [];
        $content = fixed_sede_summary($ownSede).'<div class="card"><div class="kicker">Movimiento controlado</div><h3>Solicitar traslado</h3>'.$form.'</div>'.$table;
    }
    render_page('Traslados',$content,['subtitle'=>'Selección de sede de origen, equipo y destino con trazabilidad.']);
}

function transfer_action_page(): void
{
    Auth::requireRole(['formador','admin_gi','superadmin']);
    verify_get_csrf();
    $id=(int)($_GET['id']??0);
    $action=(string)($_GET['action']??'');
    $row=Database::fetchOne(
        'SELECT t.*,e.current_sede_id,e.name equipment_name,e.serial,e.placa_rnec,u.email requester_email,so.nombre_sede origin_name,sd.nombre_sede destination_name FROM equipment_transfers t JOIN equipment e ON e.id=t.equipment_id JOIN users u ON u.id=t.requested_by LEFT JOIN sedes so ON so.id=t.origin_sede_id JOIN sedes sd ON sd.id=t.destination_sede_id WHERE t.id=?',
        [$id]
    );
    if (!$row || $row['status']!=='pendiente_aprobacion' || !Scope::canAccessSede((int)$row['origin_sede_id'])) {
        flash('danger','La solicitud no está disponible dentro de su alcance.');
        redirect('traslados');
    }
    if ($action==='approve') {
        Database::connection()->beginTransaction();
        try {
            Database::execute("UPDATE equipment_transfers SET status='aplicado',reviewed_by=?,reviewed_at=NOW(),applied_at=NOW() WHERE id=?",[Auth::id(),$id]);
            Database::execute("UPDATE equipment SET current_sede_id=?,association_method='manual',association_confidence='alta',association_evidence=?,association_review_required=0,updated_at=NOW() WHERE id=?",[$row['destination_sede_id'],json_encode(['rule'=>'traslado_aprobado','transfer_id'=>$id,'approved_by'=>(int)Auth::id()],JSON_UNESCAPED_UNICODE),$row['equipment_id']]);
            Database::execute('INSERT INTO equipment_history(equipment_id,campaign_id,event_type,origin_sede_id,destination_sede_id,description,changed_by) VALUES(?,?,?,?,?,?,?)',[$row['equipment_id'],$row['campaign_id'],'traslado_aplicado',$row['origin_sede_id'],$row['destination_sede_id'],$row['reason'],Auth::id()]);
            Database::connection()->commit();
            $result='aprobada y aplicada';
            $flashType='success';
            $flashMessage='Traslado aprobado y aplicado al inventario.';
        } catch(Throwable $e) {
            Database::connection()->rollBack();
            throw $e;
        }
    } else {
        Database::execute("UPDATE equipment_transfers SET status='rechazado',reviewed_by=?,reviewed_at=NOW() WHERE id=?",[Auth::id(),$id]);
        $result='rechazada';
        $flashType='warning';
        $flashMessage='Traslado rechazado.';
    }
    try{
        $recipient=strtolower(trim((string)$row['requester_email']));
        if(filter_var($recipient,FILTER_VALIDATE_EMAIL)){
            $equipmentLabel=trim((string)$row['equipment_name']);
            if(trim((string)$row['serial'])!=='') $equipmentLabel.=' · Serial '.trim((string)$row['serial']);
            if(trim((string)$row['placa_rnec'])!=='') $equipmentLabel.=' · Placa '.trim((string)$row['placa_rnec']);
            NotificationService::sendTemplate('transfer_resolved',$recipient,[
                'equipo'=>$equipmentLabel,
                'sede_origen'=>(string)($row['origin_name']??'Sin sede de origen'),
                'sede_destino'=>(string)$row['destination_name'],
                'resultado'=>$result,
                'url_accion'=>NotificationService::appUrl('traslados',['campaign_id'=>(int)$row['campaign_id'],'sede_id'=>(int)$row['origin_sede_id']]),
            ],$row['campaign_id']!==null?(int)$row['campaign_id']:null,(int)$row['origin_sede_id']);
        }
    }catch(Throwable $mailError){log_exception_reference($mailError,'notification_transfer_resolved');}
    audit('equipment_transfer_decision','equipment_transfer',$id,['status'=>$row['status']],['action'=>$action]);
    flash($flashType,$flashMessage);
    redirect('traslados',['campaign_id'=>$row['campaign_id'],'sede_id'=>$row['origin_sede_id']]);
}

function site_closure_certificate_page(): void
{
    $campaignId = (int)($_GET['campaign_id'] ?? selected_campaign_id());
    $sedeId = (int)($_GET['sede_id'] ?? 0);
    if (!$sedeId || !Scope::canAccessSede($sedeId)) {
        http_response_code(403);
        render_error('Acceso denegado', 'No puede consultar esta sede.');
        return;
    }

    $campaign = Database::fetchOne('SELECT * FROM campaigns WHERE id=?', [$campaignId]);
    $sede = Database::fetchOne(
        'SELECT s.*,cs.status,cs.submitted_at,cs.closed_at,cs.closure_code,cs.closure_notes,cs.responsible_name,cs.responsible_role,cs.responsible_email,u.name closed_name '
        . 'FROM sedes s JOIN campaign_sedes cs ON cs.sede_id=s.id AND cs.campaign_id=? '
        . 'LEFT JOIN users u ON u.id=cs.closed_by WHERE s.id=?',
        [$campaignId, $sedeId]
    );
    $stats = Database::fetchOne(
        "SELECT COUNT(*) total,SUM(validation_status<>'pendiente') validated,"
        . "SUM(validation_status IN ('no_encontrado','trasladado','reparacion','pendiente_baja','dado_baja','con_correccion')) news "
        . 'FROM equipment_validations WHERE campaign_id=? AND reported_by_sede_id=?',
        [$campaignId, $sedeId]
    );

    if (!$campaign || !$sede) {
        render_error('Acta no disponible', 'No existe relación entre la campaña y la sede.');
        return;
    }

    $closedAt = (string)($sede['closed_at'] ?: $sede['submitted_at'] ?: 'Pendiente');
    $responsibleName = (string)($sede['responsible_name'] ?: ($sede['closed_name'] ?: 'Usuario responsable de la sede'));
    $responsibleRole = (string)($sede['responsible_role'] ?: 'Registrador o responsable');
    $responsibleEmail = (string)($sede['responsible_email'] ?: ($sede['email_contacto'] ?: 'No registrado'));
    $code = (string)($sede['closure_code'] ?: hash('sha256', $campaignId . '|' . $sedeId . '|' . $closedAt));
    $declaration = (string)($sede['closure_notes'] ?: 'La sede declara finalizada la validación física de los equipos asignados en la campaña indicada y confirma que la información registrada corresponde a la revisión realizada.');

    $content = '<article class="card print-certificate closure-certificate" aria-labelledby="closureCertificateTitle">'
        . '<header class="certificate-header">'
        . '<div class="certificate-brand"><img src="assets/brand/icons/sivi-icon-64x64.png" alt="SIVI"><div><strong>SIVI</strong><span>Sistema Integrado de Verificación de Inventario</span></div></div>'
        . '<div class="certificate-document-meta"><strong>Acta de cierre de sede</strong><span>Documento generado por el sistema</span></div>'
        . '</header>'
        . '<section class="certificate-title"><div class="kicker">Constancia de finalización</div><h2 id="closureCertificateTitle">' . e((string)$campaign['name']) . '</h2><p>Validación de inventario realizada por la sede.</p></section>'
        . '<table class="certificate-details" aria-label="Información de la constancia"><tbody>'
        . '<tr><th scope="row">Sede</th><td>' . e((string)$sede['identificador'] . ' · ' . (string)$sede['nombre_sede']) . '</td></tr>'
        . '<tr><th scope="row">Departamento / Municipio</th><td>' . e((string)$sede['departamento'] . ' / ' . (string)$sede['municipio']) . '</td></tr>'
        . '<tr><th scope="row">Equipos reportados</th><td>' . e((string)($stats['total'] ?? 0)) . '</td></tr>'
        . '<tr><th scope="row">Equipos validados</th><td>' . e((string)($stats['validated'] ?? 0)) . '</td></tr>'
        . '<tr><th scope="row">Equipos con novedad</th><td>' . e((string)($stats['news'] ?? 0)) . '</td></tr>'
        . '<tr><th scope="row">Fecha de cierre</th><td>' . e($closedAt) . '</td></tr>'
        . '<tr><th scope="row">Responsable de la sede</th><td>' . e($responsibleName) . '</td></tr>'
        . '<tr><th scope="row">Cargo / función</th><td>' . e($responsibleRole) . '</td></tr>'
        . '<tr><th scope="row">Correo de contacto</th><td>' . e($responsibleEmail) . '</td></tr>'
        . '</tbody></table>'
        . '<section class="certificate-declaration"><h3>Declaración de cierre</h3><p>' . e($declaration) . '</p></section>'
        . '<section class="certificate-signatures"><div><span>Responsable</span><strong>' . e($responsibleName) . '</strong><small>' . e($responsibleRole) . '</small></div><div><span>Fecha de finalización</span><strong>' . e($closedAt) . '</strong><small>SIVI registró el cierre de la sede</small></div></section>'
        . '<div class="certificate-verification"><span>Código de verificación</span><code>' . e($code) . '</code></div>'
        . '<footer class="certificate-footer">Esta constancia fue generada automáticamente por SIVI. La trazabilidad completa permanece registrada en la auditoría del sistema.</footer>'
        . '<div class="form-actions no-print"><button class="btn" type="button" data-print-page>Imprimir / Guardar como PDF</button><a class="btn btn-secondary" href="' . e(route_url('equipos', ['campaign_id'=>$campaignId, 'sede_id'=>$sedeId])) . '">Regresar</a></div>'
        . '</article>';

    render_page('Acta de cierre de sede', $content, ['subtitle'=>'Constancia verificable de finalización de la campaña.']);
}

function audit_page(): void
{
    Auth::requireRole('admin_gi');$rows=Database::fetchAll('SELECT a.*,u.name user_name,u.email FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 300');$content='<div class="card"><div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Entidad</th><th>IP</th><th>Cambios</th></tr></thead><tbody>';foreach($rows as $r)$content.='<tr><td class="nowrap">'.e($r['created_at']).'</td><td>'.e($r['user_name'] ?: 'Sistema').'<br><span class="muted">'.e($r['email']).'</span></td><td>'.e($r['action']).'</td><td>'.e($r['entity_type'].' #'.$r['entity_id']).'</td><td>'.e($r['ip_address']).'</td><td><details><summary>Ver detalle</summary><pre>'.e($r['new_values']).'</pre></details></td></tr>';$content.='</tbody></table></div></div>';render_page('Auditoría',$content,['subtitle'=>'Últimas 300 acciones registradas.']);
}

function export_page(): never
{
    $campaignId = selected_campaign_id();
    $format = strtolower(trim((string)($_GET['format'] ?? 'csv')));
    if (!in_array($format, ['csv','xlsx'], true)) $format = 'csv';

    [$where, $params] = Scope::sedeCondition('s');
    $department = trim((string)($_GET['department'] ?? ''));
    $municipality = trim((string)($_GET['municipality'] ?? ''));
    $municipalityName = $municipality;
    if (str_contains($municipality, '|')) {
        [$municipalityDepartment, $municipalityName] = explode('|', $municipality, 2);
        if ($department === '') $department = $municipalityDepartment;
    }
    $siteType = trim((string)($_GET['site_type'] ?? ''));
    $sedeId = (int)($_GET['sede_id'] ?? 0);
    $q = trim((string)($_GET['q'] ?? ''));
    $category = trim((string)($_GET['category'] ?? ''));
    $type = trim((string)($_GET['type'] ?? ''));
    $associationReview = trim((string)($_GET['association_review'] ?? ''));

    if ($department !== '') {
        $where .= ' AND s.cod_dd=?';
        $params[] = $department;
    }
    if ($municipalityName !== '') {
        $where .= ' AND s.municipio=?';
        $params[] = $municipalityName;
    }
    if ($siteType !== '') {
        $where .= ' AND s.tipo_sede=?';
        $params[] = $siteType;
    }
    if ($sedeId > 0) {
        if (!Scope::canAccessSede($sedeId)) {
            http_response_code(403);
            exit('Acceso denegado');
        }
        $where .= ' AND s.id=?';
        $params[] = $sedeId;
    }
    if ($q !== '') {
        $where .= ' AND (e.name LIKE ? OR e.serial_number LIKE ? OR e.placa_rnec LIKE ? OR e.placa_almacen LIKE ? OR e.alternate_user LIKE ? OR e.os_name LIKE ? OR e.os_version LIKE ? OR e.processor LIKE ? OR e.screen_size LIKE ? OR e.connection_type LIKE ? OR e.print_technology LIKE ?)';
        $like = "%{$q}%";
        array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like);
    }
    if ($category !== '') {
        $where .= ' AND e.asset_category=?';
        $params[] = $category;
    }
    if ($type !== '') {
        $where .= ' AND e.equipment_type=?';
        $params[] = $type;
    }
    if ($associationReview === '1') $where .= ' AND e.association_review_required=1';
    if ($associationReview === '0') $where .= ' AND e.association_review_required=0';

    $allParams = array_merge([$campaignId], $params);
    $records = Database::fetchAll(
        "SELECT s.identificador,s.departamento,s.municipio,s.tipo_sede,s.nombre_sede,s.direccion_actual,e.name,e.placa_rnec,e.placa_almacen,e.warehouse_match_status,e.serial_number,e.asset_category,e.category_source,e.source_origin,e.equipment_type,e.manufacturer,e.model,e.screen_size,e.connection_type,e.print_technology,e.os_name,e.os_version,e.architecture,e.processor,e.memory,e.storage,e.alternate_user,e.ip_address,e.source_state,e.source_location,e.association_method,e.association_confidence,e.association_review_required,COALESCE(ev.validation_status,'pendiente') validation_status,COALESCE(ev.physical_condition,e.inventory_status,'desconocido') physical_condition,COALESCE(ev.ownership_type,e.ownership_type,'desconocido') ownership_type,ev.placa_reported,ev.placa_status,ev.serial_reported,ev.serial_status,ev.destination_sede_id,sd.nombre_sede destination_sede_name,sd.departamento destination_department,sd.municipio destination_municipality,ev.disposal_date,ev.disposal_document,ev.notes,COALESCE(ev.review_status,'pendiente') review_status FROM campaign_equipment ce JOIN equipment e ON e.id=ce.equipment_id LEFT JOIN sedes s ON s.id=ce.sede_id LEFT JOIN equipment_validations ev ON ev.equipment_id=e.id AND ev.campaign_id=ce.campaign_id LEFT JOIN sedes sd ON sd.id=ev.destination_sede_id WHERE ce.campaign_id=? AND e.active=1 AND {$where} ORDER BY s.departamento,s.municipio,s.identificador,e.asset_category,e.name",
        $allParams
    );

    $headers = [
        'Identificador Sede','Departamento','Municipio','Tipo de Sede','Sede','Direccion Sede','Nombre Activo','Placa RNEC Consolidada','Placa Sugerida Almacen','Estado Conciliacion Almacen','Serial','Categoria','Fuente Categoria','Origen Registro','Tipo GLPI','Fabricante','Modelo','Tamaño Monitor','Tipo Conexion','Tecnologia Impresion','Sistema Operativo','Version Sistema Operativo','Arquitectura','Procesador','Memoria RAM','Almacenamiento','Usuario','Direccion IP','Estado GLPI','Localizacion GLPI','Metodo Asociacion Sede','Confianza Asociacion','Requiere Revision Asociacion','Estado Validacion','Estado del Equipo','Tipo de Propiedad','Placa Reportada','Estado Placa','Serial Reportado','Estado Serial','Sede Destino del Traslado','Departamento Destino','Municipio Destino','Fecha de Baja','Resolucion o Acta de Baja','Observaciones','Estado Revision'
    ];
    $rows = [$headers];
    foreach ($records as $record) {
        $record['asset_category'] = asset_category_label((string)$record['asset_category']);
        $record['category_source'] = match ((string)$record['category_source']) { 'almacen' => 'Almacén', 'glpi' => 'GLPI provisional', 'manual' => 'Manual', default => 'Pendiente' };
        $record['source_origin'] = match ((string)$record['source_origin']) { 'almacen' => 'Almacén', 'manual' => 'Manual', default => 'GLPI' };
        $record['association_method'] = association_method_label($record['association_method'] ?? null);
        $record['association_confidence'] = match ((string)($record['association_confidence'] ?? '')) { 'alta' => 'Alta', 'media' => 'Media', 'baja' => 'Baja', default => 'Sin asignar' };
        $record['association_review_required'] = !empty($record['association_review_required']) ? 'Sí' : 'No';
        $record['notes'] = validation_notes_for_display((string)($record['notes'] ?? ''));
        $rows[] = array_values($record);
    }

    $timestamp = date('Ymd_His');
    if ($format === 'xlsx') {
        XlsxWriter::download('reporte_equipos_sivi_rnec_' . $timestamp . '.xlsx', [
            ['name' => 'Equipos', 'rows' => $rows, 'header_row' => 1, 'freeze_row' => 1, 'autofilter' => true],
        ]);
    }

    // Evita que advertencias, espacios o HTML previo dañen el archivo CSV.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    @ini_set('zlib.output_compression', '0');
    @ini_set('display_errors', '0');

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="reporte_equipos_sivi_rnec_' . $timestamp . '.csv"');
    header('Content-Transfer-Encoding: binary');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'wb');
    if ($out === false) {
        http_response_code(500);
        exit('No fue posible generar el archivo CSV.');
    }

    // BOM UTF-8 para conservar tildes y caracteres especiales en Excel.
    fwrite($out, "\xEF\xBB\xBF");
    foreach ($rows as $row) {
        // PHP 8.4 exige indicar el parámetro escape. Se usa cadena vacía
        // para generar CSV estándar y evitar la advertencia deprecada.
        $safeRow = array_map('csv_safe_cell', $row);
        fputcsv($out, $safeRow, ';', '"', '', "\r\n");
    }
    fclose($out);
    exit;
}



/** Cuenta las imágenes válidamente seleccionadas en el formulario de equipo adicional. */
function additional_equipment_upload_count(): int
{
    $fields = ['additional_image_general','additional_image_placa','additional_image_serial'];
    $count = 0;
    foreach ($fields as $field) {
        if (!empty($_FILES[$field]['name']) && ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) $count++;
    }
    return $count;
}

/**
 * Guarda las imágenes del equipo adicional y retorna sus rutas relativas.
 * Las rutas se almacenan como JSON en additional_equipment.evidence_path.
 *
 * @return array<string,string>
 */
function process_additional_equipment_images(): array
{
    $map = ['additional_image_general'=>'general','additional_image_placa'=>'placa','additional_image_serial'=>'serial'];
    $base = dirname(__DIR__) . '/storage/uploads/additional-equipment';
    if (!is_dir($base) && !mkdir($base, 0775, true) && !is_dir($base)) throw new RuntimeException('No fue posible crear el directorio para las imágenes.');
    $stored = [];
    try {
        foreach ($map as $field => $type) {
            if (empty($_FILES[$field]['name']) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            if (($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Una de las imágenes no pudo cargarse correctamente.');
            $tmp = (string)$_FILES[$field]['tmp_name'];
            $mime = UploadSecurity::validateImage((array)$_FILES[$field], 8 * 1024 * 1024, false);
            $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
            $name = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
            $target = $base . '/' . $name;
            if (function_exists('imagecreatefromstring')) {
                $raw = file_get_contents($tmp); $image = $raw !== false ? @imagecreatefromstring($raw) : false;
                if ($image) {
                    $width = imagesx($image); $height = imagesy($image); $maximum = 1800;
                    $scale = min(1, $maximum / max($width, $height));
                    $newWidth = max(1, (int)round($width * $scale)); $newHeight = max(1, (int)round($height * $scale));
                    $output = imagecreatetruecolor($newWidth, $newHeight);
                    imagecopyresampled($output, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                    $name = bin2hex(random_bytes(16)) . '.webp'; $target = $base . '/' . $name;
                    if (!imagewebp($output, $target, 82)) throw new RuntimeException('No fue posible convertir la imagen a WebP.');
                    imagedestroy($output); imagedestroy($image);
                } elseif (!move_uploaded_file($tmp, $target)) throw new RuntimeException('No fue posible guardar una imagen del equipo.');
            } elseif (!move_uploaded_file($tmp, $target)) throw new RuntimeException('No fue posible guardar una imagen del equipo.');
            $stored[$type] = 'storage/uploads/additional-equipment/' . $name;
        }
        return $stored;
    } catch (Throwable $e) {
        foreach ($stored as $relativePath) {
            $absolutePath = dirname(__DIR__) . '/' . ltrim($relativePath, '/');
            if (is_file($absolutePath)) @unlink($absolutePath);
        }
        throw $e;
    }
}

function process_evidence_uploads(int $validationId): void
{
    $map=['evidence_general'=>'general','evidence_placa'=>'placa','evidence_serial'=>'serial','evidence_dano'=>'dano','evidence_documento'=>'documento'];
    $base=dirname(__DIR__).'/storage/uploads/evidence'; if(!is_dir($base)){mkdir($base,0775,true);}    
    foreach($map as $field=>$type){
        if(empty($_FILES[$field]['name'])||($_FILES[$field]['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) continue;
        $tmp=(string)$_FILES[$field]['tmp_name'];
        $mime=UploadSecurity::validateImage((array)$_FILES[$field],8*1024*1024,true);
        $allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','application/pdf'=>'pdf'];
        $ext=$allowed[$mime]; $destMime=$mime;
        $name=bin2hex(random_bytes(16)).'.'.$ext; $target=$base.'/'.$name;
        if(str_starts_with($mime,'image/') && function_exists('imagecreatefromstring')){
            $raw=file_get_contents($tmp); $im=$raw!==false?@imagecreatefromstring($raw):false;
            if($im){$w=imagesx($im);$h=imagesy($im);$max=1800;$scale=min(1,$max/max($w,$h));$nw=max(1,(int)round($w*$scale));$nh=max(1,(int)round($h*$scale));$out=imagecreatetruecolor($nw,$nh);imagecopyresampled($out,$im,0,0,0,0,$nw,$nh,$w,$h);$name=bin2hex(random_bytes(16)).'.webp';$target=$base.'/'.$name;if(!imagewebp($out,$target,82)){imagedestroy($out);imagedestroy($im);throw new RuntimeException('No fue posible convertir la evidencia a WebP.');}imagedestroy($out);imagedestroy($im);$destMime='image/webp';} else {if(!move_uploaded_file($tmp,$target)) throw new RuntimeException('No fue posible guardar la evidencia.');} 
        } else { if(!move_uploaded_file($tmp,$target)) throw new RuntimeException('No fue posible guardar la evidencia.'); }
        $hash=hash_file('sha256',$target);$rel='storage/uploads/evidence/'.$name;$finalSize=filesize($target)?:0;
        try{
            Database::execute('INSERT INTO evidence_files(validation_id,evidence_type,file_path,mime_type,file_size,sha256,uploaded_by) VALUES(?,?,?,?,?,?,?)',[$validationId,$type,$rel,$destMime,$finalSize,$hash,Auth::id()]);
            Database::execute('UPDATE equipment_validations SET evidence_path=COALESCE(NULLIF(evidence_path,\'\'),?) WHERE id=?',[$rel,$validationId]);
        }catch(Throwable $e){if(is_file($target))unlink($target);}
    }
}

function corrections_page(): void
{
    $where='1=1';$params=[];if(Auth::is('registrador')){$where='ev.reported_by_sede_id=?';$params=[(int)Auth::user()['sede_id']];}
    elseif(Auth::is('formador')){$deps=Auth::user()['departments']??[];if($deps){$where='s.cod_dd IN ('.implode(',',array_fill(0,count($deps),'?')).')';$params=$deps;}else{$where='0=1';}}
    $rows=Database::fetchAll("SELECT vc.*,ev.campaign_id,e.name equipment_name,e.placa_rnec,s.nombre_sede,u.name requester FROM validation_corrections vc JOIN equipment_validations ev ON ev.id=vc.validation_id JOIN equipment e ON e.id=ev.equipment_id JOIN sedes s ON s.id=ev.reported_by_sede_id JOIN users u ON u.id=vc.requested_by WHERE $where ORDER BY vc.id DESC",$params);
    $content='<div class="card"><div class="toolbar"><p class="muted">Seguimiento de validaciones devueltas para corrección.</p></div><div class="table-wrap"><table><thead><tr><th>Equipo</th><th>Sede</th><th>Solicitud</th><th>Estado</th><th>Acción</th></tr></thead><tbody>';
    foreach($rows as $r){$content.='<tr><td><strong>'.e($r['equipment_name']).'</strong><div class="muted">'.e($r['placa_rnec']).'</div></td><td>'.e($r['nombre_sede']).'</td><td>'.e($r['notes']).'<div class="small muted">'.e($r['created_at']).'</div></td><td>'.status_badge($r['status']).'</td><td>'.($r['status']==='pendiente'?'<form method="post" action="'.e(route_url('correccion_accion')).'">'.csrf_field().'<input type="hidden" name="id" value="'.$r['id'].'"><button class="btn btn-sm">Marcar corregida</button></form>':'—').'</td></tr>';}
    $content.='</tbody></table></div></div>';render_page('Solicitudes de corrección',$content,['subtitle'=>'Control de calidad y trazabilidad de ajustes.']);
}
function correction_action_page(): void
{
    if(!request_method('POST')) redirect('correcciones');
    verify_csrf();
    $id=(int)($_POST['id']??0);
    $row=Database::fetchOne(
        'SELECT vc.*,ev.reported_by_sede_id,ev.campaign_id,e.name equipment_name,e.serial,e.placa_rnec,s.nombre_sede,s.identificador,u.email requester_email FROM validation_corrections vc JOIN equipment_validations ev ON ev.id=vc.validation_id JOIN equipment e ON e.id=ev.equipment_id JOIN sedes s ON s.id=ev.reported_by_sede_id JOIN users u ON u.id=vc.requested_by WHERE vc.id=?',
        [$id]
    );
    if(!$row||!Scope::canAccessSede((int)$row['reported_by_sede_id'])){render_error('Acceso denegado','No puede modificar esta solicitud.');return;}
    Database::execute("UPDATE validation_corrections SET status='corregida',corrected_at=NOW() WHERE id=?",[$id]);
    try{
        $recipient=strtolower(trim((string)$row['requester_email']));
        if(filter_var($recipient,FILTER_VALIDATE_EMAIL)){
            $equipmentLabel=trim((string)$row['equipment_name']);
            if(trim((string)$row['serial'])!=='') $equipmentLabel.=' · Serial '.trim((string)$row['serial']);
            if(trim((string)$row['placa_rnec'])!=='') $equipmentLabel.=' · Placa '.trim((string)$row['placa_rnec']);
            NotificationService::sendTemplate('correction_resolved',$recipient,[
                'sede'=>trim((string)$row['identificador'].' · '.(string)$row['nombre_sede']),
                'equipo'=>$equipmentLabel,
                'detalle'=>(string)$row['notes'],
                'url_accion'=>NotificationService::appUrl('correcciones'),
            ],(int)$row['campaign_id'],(int)$row['reported_by_sede_id']);
        }
    }catch(Throwable $mailError){log_exception_reference($mailError,'notification_correction_resolved');}
    audit('correct_validation','validation_correction',$id,null,['status'=>'corregida']);
    flash('success','La solicitud quedó marcada como corregida.');
    redirect('correcciones');
}

function reminders_page(): void
{
    Auth::requireRole(['admin_gi','superadmin']);$rows=Database::fetchAll("SELECT c.id,c.name,c.end_date,c.status,COUNT(cs.sede_id) AS sedes,COALESCE(SUM(CASE WHEN cs.sede_id IS NOT NULL AND cs.status NOT IN ('cerrado','aprobado') THEN 1 ELSE 0 END),0) AS pendientes FROM campaigns c LEFT JOIN campaign_sedes cs ON cs.campaign_id=c.id GROUP BY c.id,c.name,c.end_date,c.status ORDER BY c.id DESC");$content='<div class="card"><p class="muted">Genere recordatorios internos para las sedes pendientes. El sistema evita duplicados del mismo día.</p><div class="table-wrap"><table><thead><tr><th>Campaña</th><th>Fecha límite</th><th>Pendientes</th><th>Acción</th></tr></thead><tbody>';foreach($rows as $r){$content.='<tr><td>'.e($r['name']).'</td><td>'.e($r['end_date']?:'Sin fecha').'</td><td>'.e($r['pendientes']).'</td><td><form method="post" action="'.e(route_url('recordatorio_accion')).'">'.csrf_field().'<input type="hidden" name="campaign_id" value="'.$r['id'].'"><button class="btn btn-sm">Enviar recordatorio interno</button></form></td></tr>';}$content.='</tbody></table></div></div>';render_page('Recordatorios de campaña',$content);
}
function reminder_action_page(): void{Auth::requireRole(['admin_gi','superadmin']);if(!request_method('POST'))redirect('recordatorios');verify_csrf();$cid=(int)($_POST['campaign_id']??0);$c=Database::fetchOne('SELECT * FROM campaigns WHERE id=?',[$cid]);if(!$c){flash('danger','Campaña no encontrada.');redirect('recordatorios');}Database::execute("INSERT INTO internal_notifications(user_id,campaign_id,sede_id,title,message,notification_type) SELECT u.id,cs.campaign_id,cs.sede_id,'Recordatorio de campaña',CONCAT('La campaña ',?,' continúa pendiente. Fecha límite: ',COALESCE(?, 'sin definir')),'recordatorio' FROM campaign_sedes cs JOIN users u ON u.sede_id=cs.sede_id AND u.active=1 WHERE cs.campaign_id=? AND cs.status NOT IN ('cerrado','aprobado') AND NOT EXISTS(SELECT 1 FROM internal_notifications n WHERE n.user_id=u.id AND n.campaign_id=cs.campaign_id AND n.notification_type='recordatorio' AND DATE(n.created_at)=CURDATE())",[$c['name'],$c['end_date'],$cid]);$count=Database::connection()->query('SELECT ROW_COUNT()')->fetchColumn();audit('send_campaign_reminders','campaign',$cid,null,['created'=>$count]);flash('success','Se generaron '.$count.' recordatorios internos.');redirect('recordatorios');}


/** @return array<string,string|int> */
function report_query_params(string $report, array $filters, array $extra = []): array
{
    return array_merge([
        'report'=>$report,
        'campaign_id'=>(int)$filters['campaign_id'],
        'department'=>(string)$filters['department'],
        'municipality'=>(string)$filters['municipality'],
        'sede_id'=>(int)$filters['sede_id'],
        'status'=>(string)$filters['status'],
        'date_from'=>(string)$filters['date_from'],
        'date_to'=>(string)$filters['date_to'],
        'q'=>(string)$filters['q'],
    ], $extra);
}

function reports_page(): void
{
    Auth::requireRole(['formador','admin_gi','superadmin']);
    $types = ReportsCenter::availableTypes();
    $report = trim((string)($_GET['report'] ?? 'avance'));
    if (!isset($types[$report])) $report = (string)array_key_first($types);
    $filters = ReportsCenter::filters($_GET);
    if ($report === 'auditoria') {
        $filters['campaign_id']=0; $filters['department']=''; $filters['municipality']=''; $filters['sede_id']=0;
    }
    $campaigns = ReportsCenter::campaignOptions();
    $departments = ReportsCenter::departmentOptions();
    $municipalities = ReportsCenter::municipalityOptions((string)$filters['department']);
    $sedes = ReportsCenter::sedeOptions((string)$filters['department'], (string)$filters['municipality']);

    try {
        $dataset = ReportsCenter::dataset($report, $filters, 200);
    } catch (Throwable $e) {
        $reference = log_exception_reference($e, 'reports_page');
        $dataset = ['title'=>$types[$report]['label'],'description'=>$types[$report]['description'],'headers'=>[],'rows'=>[],'total'=>0,'truncated'=>false,'filename'=>'sivi_reporte'];
        flash('danger', safe_error_message('No fue posible generar la vista previa del informe', $reference));
    }

    $catalog = '<div class="report-catalog">';
    foreach ($types as $key=>$meta) {
        $url = route_url('informes', report_query_params((string)$key, $filters));
        $catalog .= '<a class="report-type-card '.($key===$report?'active':'').'" href="'.e($url).'">'
            . '<span class="report-type-icon" aria-hidden="true">'.e((string)$meta['icon']).'</span><span><strong>'.e((string)$meta['label']).'</strong><small>'.e((string)$meta['description']).'</small></span></a>';
    }
    $catalog .= '</div>';

    $reportOptions=''; foreach($types as $key=>$meta){$reportOptions.='<option value="'.e((string)$key).'"'.($key===$report?' selected':'').'>'.e((string)$meta['label']).'</option>';}
    $campaignOptions='<option value="0">Todas las campañas</option>'; foreach($campaigns as $c){$campaignOptions.='<option value="'.(int)$c['id'].'"'.((int)$filters['campaign_id']===(int)$c['id']?' selected':'').'>'.e((string)$c['name']).'</option>';}
    $departmentOptions='<option value="">Todos los departamentos</option>'; foreach($departments as $d){$departmentOptions.='<option value="'.e((string)$d['cod_dd']).'"'.((string)$filters['department']===(string)$d['cod_dd']?' selected':'').'>'.e((string)$d['departamento']).'</option>';}
    $municipalityOptions='<option value="">Todos los municipios</option>'; foreach($municipalities as $m){$municipalityOptions.='<option value="'.e((string)$m['municipio']).'"'.((string)$filters['municipality']===(string)$m['municipio']?' selected':'').'>'.e((string)$m['municipio']).'</option>';}
    $sedeOptions='<option value="0">Todas las sedes</option>'; foreach($sedes as $sede){$label=(string)$sede['identificador'].' · '.(string)$sede['nombre_sede'];$sedeOptions.='<option value="'.(int)$sede['id'].'"'.((int)$filters['sede_id']===(int)$sede['id']?' selected':'').'>'.e($label).'</option>';}
    $statusChoices=[''=>'Todos los estados','pendiente'=>'Pendiente','en_diligenciamiento'=>'En diligenciamiento','en_revision'=>'En revisión','devuelto'=>'Devuelto','aprobado'=>'Aprobado','cerrado'=>'Cerrado','abierta'=>'Abierta','en_gestion'=>'En gestión','corregida'=>'Corregida','rechazado'=>'Rechazado','aplicado'=>'Aplicado','activa'=>'Activa','finalizada'=>'Finalizada'];
    $statusOptions='';foreach($statusChoices as $value=>$label){$statusOptions.='<option value="'.e($value).'"'.((string)$filters['status']===$value?' selected':'').'>'.e($label).'</option>';}

    $filterForm='<section class="card report-filter-card"><div class="guided-section-head"><div><div class="kicker">Centro integral de informes</div><h2>'.e((string)$dataset['title']).'</h2><p>'.e((string)$dataset['description']).'</p></div></div>'
        . '<form class="report-filter-grid" method="get"><input type="hidden" name="page" value="informes">'
        . '<label class="field"><span class="form-label">Informe</span><select class="form-select" name="report">'.$reportOptions.'</select></label>'
        . '<label class="field"><span class="form-label">Campaña</span><select class="form-select" name="campaign_id">'.$campaignOptions.'</select></label>'
        . '<label class="field"><span class="form-label">Departamento</span><select class="form-select" name="department">'.$departmentOptions.'</select></label>'
        . '<label class="field"><span class="form-label">Municipio</span><select class="form-select" name="municipality">'.$municipalityOptions.'</select></label>'
        . '<label class="field"><span class="form-label">Sede</span><select class="form-select" name="sede_id">'.$sedeOptions.'</select></label>'
        . '<label class="field"><span class="form-label">Estado</span><select class="form-select" name="status">'.$statusOptions.'</select></label>'
        . '<label class="field"><span class="form-label">Desde</span><input class="form-control" type="date" name="date_from" value="'.e((string)$filters['date_from']).'"></label>'
        . '<label class="field"><span class="form-label">Hasta</span><input class="form-control" type="date" name="date_to" value="'.e((string)$filters['date_to']).'"></label>'
        . '<label class="field report-search-field"><span class="form-label">Buscar</span><input class="form-control" name="q" value="'.e((string)$filters['q']).'" placeholder="Serial, placa, sede, responsable o texto"></label>'
        . '<div class="report-filter-actions"><button class="btn">Generar informe</button><a class="btn btn-secondary" href="'.e(route_url('informes',['report'=>$report])).'">Limpiar filtros</a></div></form></section>';

    $summary = ReportsCenter::filterSummary($filters);
    $summaryHtml='';foreach($summary as $label=>$value){$summaryHtml.='<span><b>'.e((string)$label).'</b>'.e((string)$value).'</span>';}
    if($summaryHtml==='')$summaryHtml='<span><b>Alcance</b>Todos los datos permitidos para su perfil</span>';

    $exportBase = report_query_params($report,$filters);
    $toolbar='<section class="card report-toolbar no-print"><div><strong>'.number_format((int)$dataset['total'],0,',','.').' registro(s) en la vista previa</strong><span>'.(!empty($dataset['truncated'])?'La tabla muestra los primeros 200. La exportación incluye todos los registros disponibles.':'Vista previa completa para los filtros aplicados.').'</span></div><div class="form-actions">'
        . '<a class="btn btn-success" href="'.e(route_url('informe_exportar',array_merge($exportBase,['format'=>'xlsx']))).'">Exportar Excel</a>'
        . '<a class="btn btn-secondary" href="'.e(route_url('informe_exportar',array_merge($exportBase,['format'=>'csv']))).'">Exportar CSV</a>'
        . '<a class="btn btn-outline-primary" target="_blank" rel="noopener" href="'.e(route_url('informe_imprimir',$exportBase)).'">Imprimir / PDF</a></div></section>';

    $table='<section class="card report-preview-card"><div class="report-filter-summary">'.$summaryHtml.'</div>';
    if(empty($dataset['headers'])){$table.=empty_state('Informe no disponible','No fue posible preparar las columnas del informe seleccionado.');}
    elseif(empty($dataset['rows'])){$table.=empty_state('Sin resultados','Ajuste los filtros o seleccione otra campaña para consultar información.');}
    else{
        $table.='<div class="table-wrap report-table-wrap"><table class="report-table"><thead><tr>';foreach($dataset['headers'] as $header)$table.='<th>'.e((string)$header).'</th>';$table.='</tr></thead><tbody>';
        foreach($dataset['rows'] as $row){$table.='<tr>';foreach($row as $cell)$table.='<td>'.nl2br(e((string)$cell)).'</td>';$table.='</tr>';}$table.='</tbody></table></div>';
    }
    $table.='</section>';

    render_page('Informes', $catalog.$filterForm.$toolbar.$table, ['subtitle'=>'Reportes territoriales, inventario, calidad, auditoría y cierre de campaña.']);
}

function reports_export_page(): never
{
    Auth::requireRole(['formador','admin_gi','superadmin']);
    $type=trim((string)($_GET['report']??'avance'));
    $format=strtolower(trim((string)($_GET['format']??'xlsx')));
    if(!in_array($format,['xlsx','csv'],true))$format='xlsx';
    $filters=ReportsCenter::filters($_GET);
    if ($type === 'auditoria') { $filters['campaign_id']=0; $filters['department']=''; $filters['municipality']=''; $filters['sede_id']=0; }
    try{$dataset=ReportsCenter::dataset($type,$filters,0);}catch(Throwable $e){$reference=log_exception_reference($e,'reports_export');http_response_code(500);exit(safe_error_message('No fue posible generar el informe',$reference));}
    ReportsCenter::logExport($type,$format,$filters,count($dataset['rows']));
    $dataRows=[array_values($dataset['headers'])];foreach($dataset['rows'] as $row)$dataRows[]=array_values($row);
    $filterRows=[['Parámetro','Valor'],['Informe',(string)$dataset['title']],['Generado por',(string)(Auth::user()['name']??'')],['Fecha',date('Y-m-d H:i:s')]];
    foreach(ReportsCenter::filterSummary($filters) as $label=>$value)$filterRows[]=[(string)$label,(string)$value];
    if($format==='xlsx'){
        XlsxWriter::download((string)$dataset['filename'].'.xlsx',[
            ['name'=>'Datos','rows'=>$dataRows,'header_row'=>1,'freeze_row'=>1,'autofilter'=>true],
            ['name'=>'Parámetros','rows'=>$filterRows,'header_row'=>1,'freeze_row'=>1,'autofilter'=>false],
        ]);
    }
    while(ob_get_level()>0)ob_end_clean();if(session_status()===PHP_SESSION_ACTIVE)session_write_close();@ini_set('zlib.output_compression','0');
    header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="'.e((string)$dataset['filename']).'.csv"');header('Cache-Control: no-store');
    $out=fopen('php://output','wb');if($out===false){http_response_code(500);exit('No fue posible generar el CSV.');}fwrite($out,"\xEF\xBB\xBF");foreach($dataRows as $row)fputcsv($out,array_map('csv_safe_cell',$row),';','"','',"\r\n");fclose($out);exit;
}

function reports_print_page(): void
{
    Auth::requireRole(['formador','admin_gi','superadmin']);
    $type=trim((string)($_GET['report']??'avance'));$filters=ReportsCenter::filters($_GET);
    if ($type === 'auditoria') { $filters['campaign_id']=0; $filters['department']=''; $filters['municipality']=''; $filters['sede_id']=0; }
    try{$dataset=ReportsCenter::dataset($type,$filters,1000);}catch(Throwable $e){$reference=log_exception_reference($e,'reports_print');http_response_code(500);echo e(safe_error_message('No fue posible preparar la impresión',$reference));return;}
    ReportsCenter::logExport($type,'pdf_print',$filters,count($dataset['rows']));
    $summary=ReportsCenter::filterSummary($filters);$user=Auth::user();$assetVersion=rawurlencode(AppVersion::package());
    $closeUrl = route_url('informes', report_query_params($type, $filters));
    header('Cache-Control: no-store, max-age=0');
    ?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e((string)$dataset['title'])?> · SIVI</title><link rel="stylesheet" href="assets/report-print.css?v=<?=e($assetVersion)?>"><script src="assets/report-print.js?v=<?=e($assetVersion)?>" defer></script></head><body><div class="print-actions no-print"><button type="button" data-report-print>Imprimir / Guardar como PDF</button><a role="button" href="<?=e($closeUrl)?>" data-report-close data-close-url="<?=e($closeUrl)?>">Cerrar</a></div><header class="report-head"><div><strong>SIVI · Sistema Integrado de Verificación de Inventario</strong><h1><?=e((string)$dataset['title'])?></h1><p><?=e((string)$dataset['description'])?></p></div><div class="report-meta"><strong>Generado: <?=e(date('Y-m-d H:i:s'))?></strong><br><?=e((string)($user['name']??''))?><br><?=e(role_label((string)($user['role']??'')))?></div></header><section class="filters"><?php if($summary===[]):?><span><b>Alcance</b>Todos los datos permitidos para el perfil</span><?php else:foreach($summary as $label=>$value):?><span><b><?=e((string)$label)?></b><?=e((string)$value)?></span><?php endforeach;endif;?></section><?php if($dataset['rows']===[]):?><p>No se encontraron registros con los filtros seleccionados.</p><?php else:?><table><thead><tr><?php foreach($dataset['headers'] as $header):?><th><?=e((string)$header)?></th><?php endforeach;?></tr></thead><tbody><?php foreach($dataset['rows'] as $row):?><tr><?php foreach($row as $cell):?><td><?=nl2br(e((string)$cell))?></td><?php endforeach;?></tr><?php endforeach;?></tbody></table><?php endif;?><?php if(!empty($dataset['truncated'])):?><p class="note">La impresión está limitada a 1.000 registros. Use la exportación Excel para obtener el conjunto completo.</p><?php endif;?><p class="note">Documento generado automáticamente por SIVI. Los filtros, usuario y fecha de generación quedan registrados para trazabilidad.</p></body></html><?php
}

function executive_report_page(): void
{
    $cid=selected_campaign_id();$campaigns=Database::fetchAll('SELECT id,name FROM campaigns ORDER BY id DESC');$content='<div class="card"><form class="filter-grid" method="get"><input type="hidden" name="page" value="reporte_ejecutivo"><label class="field"><span class="form-label">Campaña</span><select class="form-select" name="campaign_id">';foreach($campaigns as $c){$content.='<option value="'.$c['id'].'"'.($cid==(int)$c['id']?' selected':'').'>'.e($c['name']).'</option>';}$content.='</select></label><button class="btn">Consultar</button></form></div>';
    if($cid){$m=Database::fetchOne("SELECT COUNT(DISTINCT cs.sede_id) sedes,COUNT(DISTINCT CASE WHEN cs.status IN ('cerrado','aprobado') THEN cs.sede_id END) cerradas,COUNT(DISTINCT e.id) equipos,COUNT(DISTINCT CASE WHEN ev.validation_status<>'pendiente' THEN ev.equipment_id END) validados,COUNT(DISTINCT CASE WHEN ev.physical_condition='trasladado' THEN ev.equipment_id END) trasladados,COUNT(DISTINCT CASE WHEN ev.physical_condition='dado_baja' THEN ev.equipment_id END) dados_baja,COUNT(DISTINCT CASE WHEN ev.physical_condition='en_mantenimiento' THEN ev.equipment_id END) mantenimiento,COUNT(DISTINCT CASE WHEN ev.serial_status IN ('corregido','ilegible','sin_serial') THEN ev.equipment_id END) serial_novedad,COUNT(DISTINCT CASE WHEN ev.placa_status IN ('corregido','ilegible') OR (ev.ownership_type='propio' AND ev.placa_status='sin_placa') THEN ev.equipment_id END) placa_novedad FROM campaign_sedes cs LEFT JOIN campaign_equipment ce ON ce.campaign_id=cs.campaign_id AND ce.sede_id=cs.sede_id LEFT JOIN equipment e ON e.id=ce.equipment_id LEFT JOIN equipment_validations ev ON ev.campaign_id=cs.campaign_id AND ev.equipment_id=e.id WHERE cs.campaign_id=?",[$cid]);$pct=$m['sedes']?round($m['cerradas']/$m['sedes']*100):0;$content.='<div class="metric-grid"><div class="metric"><span>Sedes cerradas</span><strong>'.$m['cerradas'].' / '.$m['sedes'].'</strong></div><div class="metric"><span>Cumplimiento</span><strong>'.$pct.'%</strong></div><div class="metric"><span>Equipos validados</span><strong>'.$m['validados'].' / '.$m['equipos'].'</strong></div><div class="metric"><span>Trasladados</span><strong>'.$m['trasladados'].'</strong></div><div class="metric"><span>Dados de baja</span><strong>'.$m['dados_baja'].'</strong></div><div class="metric"><span>En mantenimiento</span><strong>'.$m['mantenimiento'].'</strong></div><div class="metric"><span>Novedad serial</span><strong>'.$m['serial_novedad'].'</strong></div><div class="metric"><span>Novedad placa</span><strong>'.$m['placa_novedad'].'</strong></div></div><div class="card"><button class="btn btn-secondary" type="button" data-print-page>Imprimir / Guardar PDF</button></div>';}
    render_page('Reporte ejecutivo',$content,['subtitle'=>'Resumen consolidado para seguimiento institucional.']);
}

function system_health_page(): void
{
    Auth::requireRole(['admin_gi','superadmin']);
    $snapshot = SystemHealth::snapshot();

    if (isset($_GET['download']) && (string)$_GET['download'] === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="sivi-diagnostico-' . date('Ymd-His') . '.json"');
        header('Cache-Control: no-store');
        echo json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $tone = match ($snapshot['status']) {
        'ok' => 'success',
        'warning' => 'warning',
        default => 'danger',
    };
    $headline = match ($snapshot['status']) {
        'ok' => 'SIVI está listo para operar',
        'warning' => 'SIVI está operativo con advertencias',
        default => 'SIVI requiere atención',
    };

    $content = '<section class="card system-health-hero"><div><div class="kicker">Operación y despliegue</div><h2>' . e($headline) . '</h2><p>Verificación segura de aplicación, base de datos, almacenamiento, HTTPS, PWA y herramientas de captura.</p></div><span class="badge text-bg-' . e($tone) . '">' . e(strtoupper((string)$snapshot['status'])) . '</span></section>';
    $content .= '<div class="metric-grid">'
        . metric_card('Versión', (string)$snapshot['version'], 'Paquete desplegado', 'blue')
        . metric_card('Errores críticos', (int)$snapshot['critical_failures'], 'Deben resolverse antes de producción', ((int)$snapshot['critical_failures'] > 0 ? 'orange' : 'green'))
        . metric_card('Advertencias', (int)$snapshot['warnings'], 'Recomendaciones operativas', ((int)$snapshot['warnings'] > 0 ? 'orange' : 'green'))
        . metric_card('Espacio libre', $snapshot['storage_free_percent'] === null ? 'N/D' : $snapshot['storage_free_percent'] . '%', 'Volumen de almacenamiento', 'blue')
        . '</div>';

    $content .= '<section class="card"><div class="guided-section-head"><div><h3>Controles del sistema</h3><p>Los controles críticos bloquean la preparación de una liberación.</p></div><a class="btn btn-outline-primary" href="' . e(route_url('sistema',['download'=>'json'])) . '">Descargar diagnóstico JSON</a></div><div class="table-wrap"><table><thead><tr><th>Control</th><th>Resultado</th><th>Detalle</th></tr></thead><tbody>';
    foreach ($snapshot['checks'] as $check) {
        $statusText = $check['ok'] ? 'Correcto' : ($check['critical'] ? 'Error' : 'Advertencia');
        $statusClass = $check['ok'] ? 'success' : ($check['critical'] ? 'danger' : 'warning');
        $content .= '<tr><td><strong>' . e((string)$check['label']) . '</strong></td><td><span class="badge text-bg-' . e($statusClass) . '">' . e($statusText) . '</span></td><td>' . e((string)$check['detail']) . '</td></tr>';
    }
    $content .= '</tbody></table></div></section>';

    $actions = '<a class="btn btn-outline-primary" href="' . e(route_url('sistema')) . '">Actualizar diagnóstico</a>';
    if (Auth::is('superadmin')) {
        $content .= '<section class="card"><h3>Acciones recomendadas</h3><div class="action-row"><a class="btn" href="' . e(route_url('respaldos')) . '">Generar respaldo</a><a class="btn btn-secondary" href="' . e(route_url('versionamiento')) . '">Revisar versionamiento</a><a class="btn btn-outline-secondary" href="health.php" target="_blank" rel="noopener">Abrir health.php</a></div></section>';
    }
    render_page('Estado del sistema', $content, ['subtitle'=>'Salud operativa, preparación del despliegue y diagnóstico técnico.', 'actions'=>$actions]);
}

function backups_page(): void
{
    Auth::requireRole(['superadmin']);
    $dir = '/var/backups/sivi';
    $requestDir = $dir . '/requests';
    if (!is_dir($dir)) @mkdir($dir, 0770, true);
    if (!is_dir($requestDir)) @mkdir($requestDir, 0770, true);

    if (request_method('POST')) {
        verify_csrf();
        try {
            $requestId = date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(4)));
            $requestFile = $requestDir . '/' . $requestId . '.request';
            $payload = json_encode([
                'request_id' => $requestId,
                'requested_at' => date(DATE_ATOM),
                'requested_by' => Auth::id(),
                'app_version' => AppVersion::package(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($payload === false || file_put_contents($requestFile, $payload . PHP_EOL, LOCK_EX) === false) {
                throw new RuntimeException('No fue posible registrar la solicitud de respaldo.');
            }
            @chmod($requestFile, 0660);
            audit('request_encrypted_backup', 'system', null, null, [
                'request_id' => $requestId,
            ]);
            flash('success', 'La solicitud de respaldo cifrado fue enviada. El servicio de respaldos la procesará en el siguiente ciclo.');
        } catch (Throwable $e) {
            $reference = log_exception_reference($e, 'manual_backup_request');
            flash('danger', safe_error_message('No fue posible solicitar el respaldo', $reference));
        }
        redirect('respaldos');
    }

    if (isset($_GET['download'])) {
        $name = basename((string)$_GET['download']);
        if (preg_match('/^SIVI-[A-Za-z0-9._-]+\.tar\.gz\.enc$/', $name) !== 1) {
            http_response_code(400);
            exit('Archivo inválido');
        }
        $path = $dir . '/' . $name;
        if (!is_file($path)) {
            http_response_code(404);
            exit('Archivo no encontrado');
        }
        audit('download_encrypted_backup', 'system', null, null, [
            'file' => $name,
        ]);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: no-store, private, max-age=0');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    $files = glob($dir . '/SIVI-*.tar.gz.enc') ?: [];
    usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
    $files = array_slice($files, 0, 30);

    $content = '<div class="card"><p class="muted">Los respaldos de producción se generan en el contenedor dedicado, se verifican y se almacenan cifrados. La aplicación no crea copias de base de datos sin cifrar.</p><form method="post">'
        . csrf_field()
        . '<button class="btn" data-confirm="¿Solicitar un respaldo cifrado ahora?">Solicitar respaldo cifrado</button></form></div>'
        . '<div class="card"><div class="table-wrap"><table><thead><tr><th>Archivo</th><th>Tamaño</th><th>SHA-256</th><th>Fecha</th><th></th></tr></thead><tbody>';

    foreach ($files as $path) {
        $name = basename($path);
        $hashFile = $path . '.sha256';
        $hash = '';
        if (is_file($hashFile)) {
            $parts = preg_split('/\s+/', trim((string)file_get_contents($hashFile))) ?: [];
            $hash = (string)($parts[0] ?? '');
        }
        $content .= '<tr><td>' . e($name) . '</td><td>'
            . number_format(((int)filesize($path))/1024/1024, 2)
            . ' MB</td><td><code>'
            . e($hash !== '' ? substr($hash, 0, 20) . '…' : 'Pendiente')
            . '</code></td><td>'
            . e(date('Y-m-d H:i:s', (int)filemtime($path)))
            . '</td><td><a class="btn btn-sm" href="'
            . e(route_url('respaldos',['download'=>$name]))
            . '">Descargar cifrado</a></td></tr>';
    }
    if ($files === []) {
        $content .= '<tr><td colspan="5">Todavía no existen respaldos cifrados disponibles.</td></tr>';
    }
    $content .= '</tbody></table></div></div>';
    render_page('Copias de seguridad', $content, [
        'subtitle'=>'Respaldo cifrado, verificado y trazable.',
    ]);
}

function version_control_page(): void
{
    Auth::requireRole(['superadmin']);

    if (request_method('POST')) {
        verify_csrf();
        $action = (string)($_POST['action'] ?? 'register');
        if ($action === 'register') {
            try {
                $notes = trim((string)($_POST['release_notes'] ?? ''));
                AppVersion::registerDeployment(Auth::id(), $notes !== '' ? $notes : null);
                audit('register_application_version', 'system', null, null, [
                    'version' => AppVersion::package(),
                    'environment' => AppVersion::environment(),
                    'build_id' => AppVersion::buildId(),
                    'git_commit' => AppVersion::gitCommit(),
                ]);
                flash('success', 'La versión desplegada quedó registrada correctamente.');
            } catch (Throwable $e) {
                $reference = log_exception_reference($e, 'register_application_version');
                flash('danger', safe_error_message('No fue posible registrar la versión', $reference));
            }
            redirect('versionamiento');
        }
    }

    $status = AppVersion::status();
    $release = $status['database_release'];
    $history = AppVersion::history(40);
    $levelClass = match ($status['level']) {
        'ok' => 'success',
        'warning' => 'warning',
        default => 'danger',
    };
    $levelText = match ($status['level']) {
        'ok' => 'Versionamiento consistente',
        'warning' => 'Versionamiento con advertencias',
        default => 'Versionamiento inconsistente',
    };

    $content = '<div class="alert alert-' . $levelClass . '"><strong>' . e($levelText) . '</strong>';
    foreach ($status['errors'] as $message) {
        $content .= '<div>• ' . e($message) . '</div>';
    }
    foreach ($status['warnings'] as $message) {
        $content .= '<div>• ' . e($message) . '</div>';
    }
    if ($status['errors'] === [] && $status['warnings'] === []) {
        $content .= '<div>El paquete, Environment, base de datos y esquema están sincronizados.</div>';
    }
    $content .= '</div>';

    $content .= '<div class="metric-grid version-metrics">'
        . '<div class="metric"><span>Versión del paquete</span><strong>' . e($status['package_version']) . '</strong><small>Fuente: archivo VERSION</small></div>'
        . '<div class="metric"><span>Versión configurada</span><strong>' . e($status['configured_version']) . '</strong><small>Fuente: APP_VERSION</small></div>'
        . '<div class="metric"><span>Ambiente</span><strong>' . e($status['environment_label']) . '</strong><small>' . e($status['environment']) . '</small></div>'
        . '<div class="metric"><span>Build</span><strong class="version-build">' . e($status['build_id']) . '</strong><small>APP_BUILD_ID</small></div>'
        . '</div>';

    $content .= '<div class="card"><h2>Identificación técnica del despliegue</h2><div class="version-detail-grid">'
        . '<div><span>Commit Git</span><code>' . e($status['git_commit'] ?? 'No configurado') . '</code></div>'
        . '<div><span>SHA-256 del esquema</span><code>' . e($status['schema_checksum'] ?? 'No disponible') . '</code></div>'
        . '<div><span>Versión registrada en BD</span><strong>' . e($release['version'] ?? 'Pendiente') . '</strong></div>'
        . '<div><span>Último registro</span><strong>' . e($release['last_seen_at'] ?? 'Pendiente') . '</strong></div>'
        . '</div></div>';

    $schema = $status['schema'];
    $content .= '<div class="card"><h2>Controles de publicación</h2><div class="table-wrap"><table><thead><tr><th>Control</th><th>Resultado</th><th>Detalle</th></tr></thead><tbody>';
    $checks = [
        ['Formato N.N.N.N', AppVersion::isValid($status['package_version']), $status['package_version']],
        ['VERSION = APP_VERSION', $status['package_version'] === $status['configured_version'], $status['configured_version']],
        ['Ambiente permitido', in_array($status['environment'], ['development','testing','staging','production'], true), $status['environment_label']],
        ['Registro en base de datos', $status['database_matches'], $release ? 'Registrado' : 'Pendiente'],
        ['Esquema sincronizado', is_array($schema) && !empty($schema['ok']), is_array($schema) ? (empty($schema['ok']) ? 'Objetos o checksum pendientes' : 'Actualizado') : 'No disponible'],
        ['Política pruebas/producción', AppVersion::policy()['errors'] === [], $status['environment'] === 'production' ? 'Producción requiere 1.x o superior' : 'Pruebas utiliza 0.x'],
    ];
    foreach ($checks as [$label, $ok, $detail]) {
        $content .= '<tr><td><strong>' . e($label) . '</strong></td><td><span class="badge badge-' . ($ok ? 'success' : 'danger') . '">' . ($ok ? 'Correcto' : 'Revisar') . '</span></td><td>' . e($detail) . '</td></tr>';
    }
    $content .= '</tbody></table></div></div>';

    $content .= '<div class="card"><h2>Registrar el despliegue actual</h2><p class="muted">Este control no permite cambiar la versión desde la interfaz. Registra únicamente la versión contenida en el archivo <code>VERSION</code> y deja trazabilidad del usuario, ambiente, build, commit y esquema.</p>'
        . '<form method="post" class="version-register-form">' . csrf_field() . '<input type="hidden" name="action" value="register">'
        . '<label class="field"><span class="form-label">Notas de la versión</span><textarea class="form-control" name="release_notes" rows="3" maxlength="2000" placeholder="Resumen de ajustes incluidos en este despliegue"></textarea></label>'
        . '<button class="btn" type="submit" data-confirm="¿Registrar la versión actual como despliegue vigente?">Registrar versión ' . e(AppVersion::package()) . '</button></form>'
        . '<div class="version-next"><span>Siguiente corrección sugerida: <strong>' . e(AppVersion::nextPatch()) . '</strong></span><span>Siguiente mejora funcional sugerida: <strong>' . e(AppVersion::nextFeature()) . '</strong></span><span>Primera producción: <strong>1.0.0.0</strong></span></div></div>';

    $content .= '<div class="card"><h2>Historial de versiones registradas</h2><div class="table-wrap"><table><thead><tr><th>Versión</th><th>Ambiente</th><th>Build / commit</th><th>Estado</th><th>Registro</th><th>Notas</th></tr></thead><tbody>';
    if ($history === []) {
        $content .= '<tr><td colspan="6" class="muted">Todavía no existen versiones registradas.</td></tr>';
    }
    foreach ($history as $row) {
        $content .= '<tr><td><strong>' . e($row['version']) . '</strong></td><td>' . e($row['environment']) . '</td><td><code>' . e($row['build_id']) . '</code><div class="small muted">' . e($row['git_commit'] ? mb_substr((string)$row['git_commit'], 0, 12) : 'sin commit') . '</div></td><td><span class="badge badge-' . ((int)$row['is_current'] === 1 ? 'success' : 'neutral') . '">' . ((int)$row['is_current'] === 1 ? 'Actual' : 'Anterior') . '</span></td><td>' . e($row['installed_at']) . '<div class="small muted">' . e($row['registered_by_name'] ?? 'Sistema') . '</div></td><td>' . nl2br(e($row['release_notes'] ?? '—')) . '</td></tr>';
    }
    $content .= '</tbody></table></div></div>';

    render_page('Versionamiento', $content, ['subtitle' => 'Control de versión, ambiente, esquema y trazabilidad de despliegues.']);
}

/** @param array<string,mixed> $payload */
function mobile_scan_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mobile_scan_start_page(): never
{
    if (!AppSettings::mobileCaptureEnabled()) mobile_scan_json(['ok'=>false,'message'=>'La captura móvil está deshabilitada por el administrador.'], 403);
    if (!request_method('POST')) mobile_scan_json(['ok'=>false,'message'=>'Método no permitido.'], 405);
    verify_csrf();
    $campaignId = (int)($_POST['campaign_id'] ?? 0);
    $sedeId = (int)($_POST['sede_id'] ?? 0);
    if ($sedeId < 1 && Auth::is('registrador')) $sedeId = (int)(Auth::user()['sede_id'] ?? 0);
    if ($sedeId > 0 && !Scope::canAccessSede($sedeId)) {
        mobile_scan_json(['ok'=>false,'message'=>'La sede no pertenece a su alcance territorial.'], 403);
    }
    if ($campaignId > 0 && $sedeId > 0) {
        $linked = Database::fetchOne('SELECT 1 ok FROM campaign_sedes WHERE campaign_id=? AND sede_id=?', [$campaignId,$sedeId]);
        if (!$linked) mobile_scan_json(['ok'=>false,'message'=>'La sede no está asociada a la campaña seleccionada.'], 422);
    }

    try {
        $session = MobileScanBridge::start(Auth::id(), $campaignId, $sedeId);
        $token = $session['token'];
        mobile_scan_json([
            'ok'=>true,
            'token'=>$token,
            'pairing_code'=>$session['pairing_code'],
            'expires_at'=>$session['expires_at'],
            'expires_in'=>$session['expires_in'],
            'scanner_url'=>MobileScanBridge::scannerUrl($token),
            'qr_url'=>route_url('mobile_scan_qr', ['token'=>$token]),
        ]);
    } catch (Throwable $e) {
        $reference = log_exception_reference($e, 'mobile_scan_start');
        mobile_scan_json(['ok'=>false,'message'=>'No fue posible crear la conexión con el celular. Referencia: '.$reference.'.'], 500);
    }
}

function mobile_scan_poll_page(): never
{
    if (!AppSettings::mobileCaptureEnabled()) mobile_scan_json(['ok'=>false,'message'=>'La captura móvil está deshabilitada por el administrador.'], 403);
    $token = trim((string)($_GET['token'] ?? ''));
    $after = max(0, (int)($_GET['after'] ?? 0));
    $row = MobileScanBridge::findForUser($token, Auth::id());
    if (!$row) mobile_scan_json(['ok'=>false,'status'=>'not_found','message'=>'La conexión móvil no existe.'], 404);
    if ((string)$row['status'] !== 'active') {
        mobile_scan_json(['ok'=>true,'status'=>(string)$row['status'],'expired'=>true,'sequence'=>(int)$row['scan_sequence']]);
    }
    $sequence = (int)$row['scan_sequence'];
    $payload = [
        'ok'=>true,
        'status'=>'active',
        'expired'=>false,
        'sequence'=>$sequence,
        'ack_sequence'=>(int)($row['ack_sequence'] ?? 0),
        'expires_at'=>(string)$row['expires_at'],
        'mobile_connected'=>!empty($row['mobile_last_seen_at']) && strtotime((string)$row['mobile_last_seen_at']) >= time()-15,
        'mobile_last_seen'=>(string)($row['mobile_last_seen_at'] ?? ''),
        'has_scan'=>$sequence > $after && trim((string)($row['last_value'] ?? '')) !== '',
    ];
    if ($payload['has_scan']) {
        $payload['target'] = (string)$row['last_target'];
        $payload['value'] = (string)$row['last_value'];
        $payload['format'] = (string)($row['last_format'] ?? '');
        $payload['scanned_at'] = (string)($row['last_scanned_at'] ?? '');
    }
    mobile_scan_json($payload);
}

function mobile_scan_ack_page(): never
{
    if (!AppSettings::mobileCaptureEnabled()) mobile_scan_json(['ok'=>false,'message'=>'La captura móvil está deshabilitada por el administrador.'], 403);
    if (!request_method('POST')) mobile_scan_json(['ok'=>false,'message'=>'Método no permitido.'], 405);
    verify_csrf();
    $token = trim((string)($_POST['token'] ?? ''));
    $sequence = max(0, (int)($_POST['sequence'] ?? 0));
    try {
        $result = MobileScanBridge::acknowledge($token, Auth::id(), $sequence);
        mobile_scan_json(['ok'=>true,'message'=>'Lectura confirmada en el computador.'] + $result);
    } catch (InvalidArgumentException $e) {
        mobile_scan_json(['ok'=>false,'message'=>$e->getMessage()], 422);
    } catch (Throwable $e) {
        $reference = log_exception_reference($e, 'mobile_scan_ack');
        mobile_scan_json(['ok'=>false,'message'=>'No fue posible confirmar la lectura. Referencia: '.$reference.'.'], 410);
    }
}

function mobile_scan_renew_page(): never
{
    if (!AppSettings::mobileCaptureEnabled()) mobile_scan_json(['ok'=>false,'message'=>'La captura móvil está deshabilitada por el administrador.'], 403);
    if (!request_method('POST')) mobile_scan_json(['ok'=>false,'message'=>'Método no permitido.'], 405);
    verify_csrf();
    $token = trim((string)($_POST['token'] ?? ''));
    try {
        $result = MobileScanBridge::renew($token, Auth::id());
        mobile_scan_json(['ok'=>true,'message'=>'Conexión renovada por 10 minutos.'] + $result);
    } catch (Throwable $e) {
        $reference = log_exception_reference($e, 'mobile_scan_renew');
        mobile_scan_json(['ok'=>false,'message'=>'No fue posible renovar la conexión. Referencia: '.$reference.'.'], 410);
    }
}

function mobile_scan_stop_page(): never
{
    if (!AppSettings::mobileCaptureEnabled()) mobile_scan_json(['ok'=>false,'message'=>'La captura móvil está deshabilitada por el administrador.'], 403);
    if (!request_method('POST')) mobile_scan_json(['ok'=>false,'message'=>'Método no permitido.'], 405);
    verify_csrf();
    $token = trim((string)($_POST['token'] ?? ''));
    MobileScanBridge::stop($token, Auth::id());
    mobile_scan_json(['ok'=>true]);
}

function mobile_scan_qr_page(): never
{
    if (!AppSettings::mobileCaptureEnabled()) mobile_scan_json(['ok'=>false,'message'=>'La captura móvil está deshabilitada por el administrador.'], 403);
    $token = trim((string)($_GET['token'] ?? ''));
    $row = MobileScanBridge::findForUser($token, Auth::id());
    if (!$row || (string)$row['status'] !== 'active') {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Conexión no disponible';
        exit;
    }

    $url = MobileScanBridge::scannerUrl($token);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = @proc_open(['qrencode','-t','PNG','-s','6','-m','2','-o','-',$url], $descriptors, $pipes);
    if (!is_resource($process)) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Generador QR no disponible';
        exit;
    }
    fclose($pipes[0]);
    $png = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0 || !is_string($png) || $png === '') {
        error_log('mobile_scan_qr error: '.trim((string)$error));
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'No fue posible generar el código QR';
        exit;
    }
    header('Content-Type: image/png');
    header('Cache-Control: no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo $png;
    exit;
}

function mobile_scan_status_page(): never
{
    if (!AppSettings::mobileCaptureEnabled()) mobile_scan_json(['ok'=>false,'message'=>'La captura móvil está deshabilitada por el administrador.'], 403);
    $token = trim((string)($_GET['token'] ?? ''));
    try {
        $result = MobileScanBridge::mobileStatus($token);
        mobile_scan_json(['ok'=>true] + $result);
    } catch (Throwable $e) {
        mobile_scan_json(['ok'=>false,'status'=>'not_found','message'=>'La conexión móvil no existe o ya no está disponible.'], 404);
    }
}

function mobile_scan_submit_page(): never
{
    if (!AppSettings::mobileCaptureEnabled()) mobile_scan_json(['ok'=>false,'message'=>'La captura móvil está deshabilitada por el administrador.'], 403);
    if (!request_method('POST')) mobile_scan_json(['ok'=>false,'message'=>'Método no permitido.'], 405);
    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin !== '') {
        $originHost = strtolower((string)(parse_url($origin, PHP_URL_HOST) ?? ''));
        $requestHost = strtolower(trim(explode(':', (string)($_SERVER['HTTP_HOST'] ?? ''))[0]));
        if ($originHost !== '' && $requestHost !== '' && $originHost !== $requestHost) {
            mobile_scan_json(['ok'=>false,'message'=>'Origen no autorizado.'], 403);
        }
    }
    $token = trim((string)($_POST['token'] ?? ''));
    $target = trim((string)($_POST['target'] ?? ''));
    $value = (string)($_POST['value'] ?? '');
    $format = (string)($_POST['format'] ?? '');
    $requestId = trim((string)($_POST['request_id'] ?? ''));
    try {
        $result = MobileScanBridge::submit($token, $target, $value, $format, $requestId);
        mobile_scan_json(['ok'=>true,'message'=>'Código enviado al formulario.'] + $result);
    } catch (InvalidArgumentException $e) {
        mobile_scan_json(['ok'=>false,'message'=>$e->getMessage()], 422);
    } catch (Throwable $e) {
        $reference = log_exception_reference($e, 'mobile_scan_submit');
        mobile_scan_json(['ok'=>false,'message'=>'No fue posible enviar el código. Referencia: '.$reference.'.'], 410);
    }
}

function mobile_scan_decode_image_page(): never
{
    if (!AppSettings::mobileCaptureEnabled()) mobile_scan_json(['ok'=>false,'message'=>'La captura móvil está deshabilitada por el administrador.'], 403);
    if (!AppSettings::imageUploadEnabled()) mobile_scan_json(['ok'=>false,'message'=>'La lectura por fotografía o galería está deshabilitada.'], 403);
    if (!request_method('POST')) mobile_scan_json(['ok'=>false,'message'=>'Método no permitido.'], 405);
    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin !== '') {
        $originHost = strtolower((string)(parse_url($origin, PHP_URL_HOST) ?? ''));
        $requestHost = strtolower(trim(explode(':', (string)($_SERVER['HTTP_HOST'] ?? ''))[0]));
        if ($originHost !== '' && $requestHost !== '' && $originHost !== $requestHost) {
            mobile_scan_json(['ok'=>false,'message'=>'Origen no autorizado.'], 403);
        }
    }
    $token = trim((string)($_POST['token'] ?? ''));
    $target = trim((string)($_POST['target'] ?? 'serial_number'));
    try {
        $result = MobileScanBridge::decodeUploadedImage($token, (array)($_FILES['image'] ?? []), $target);
        mobile_scan_json(['ok'=>true,'message'=>'Código detectado en la fotografía.'] + $result);
    } catch (InvalidArgumentException $e) {
        mobile_scan_json(['ok'=>false,'message'=>$e->getMessage()], 422);
    } catch (Throwable $e) {
        $reference = log_exception_reference($e, 'mobile_scan_decode_image');
        mobile_scan_json(['ok'=>false,'message'=>'No fue posible analizar la fotografía. Referencia: '.$reference.'.'], 500);
    }
}

function mobile_scanner_page(): void
{
    $token = trim((string)($_GET['token'] ?? ''));
    $scanConfig = AppSettings::mobileCaptureConfig();
    $row = $scanConfig['enabled'] ? MobileScanBridge::find($token) : null;
    $available = $scanConfig['enabled'] && $row && (string)$row['status'] === 'active';
    $pairingCode = $available ? (string)$row['pairing_code'] : '------';
    $expiresAt = $available ? (string)$row['expires_at'] : '';
    $version = e(rawurlencode(AppVersion::package()));
    header('Cache-Control: no-store, max-age=0');
    header('Permissions-Policy: camera=(self)');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    ?><!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="#0b4a78">
    <meta name="robots" content="noindex,nofollow">
    <title>Lector móvil · SIVI</title>
    <link rel="icon" href="assets/brand/favicon/favicon.ico">
    <link rel="stylesheet" href="assets/mobile-scanner.css?v=<?=$version?>">
</head>
<body>
<main class="scanner-shell" data-mobile-scanner
      data-token="<?=e($token)?>"
      data-submit-url="<?=e(route_url('mobile_scan_submit'))?>"
      data-status-url="<?=e(route_url('mobile_scan_status'))?>"
      data-image-url="<?=e(route_url('mobile_scan_decode_image'))?>"
      data-expires-at="<?=e($expiresAt)?>"
      data-available="<?=$available?'1':'0'?>"
      data-live-enabled="<?=$scanConfig['live_camera']?'1':'0'?>"
      data-image-enabled="<?=$scanConfig['image_upload']?'1':'0'?>"
      data-manual-enabled="<?=$scanConfig['manual_entry']?'1':'0'?>">
    <header class="scanner-header">
        <img src="assets/brand/logos/sivi-logo-horizontal-600px.png?v=<?=$version?>" alt="SIVI">
        <div><span>Lector móvil seguro</span><strong>QR, barras y texto de etiquetas</strong></div>
    </header>
    <?php if (!$available): ?>
        <section class="scanner-card scanner-expired"><div class="scanner-icon">⌛</div><h1><?=!$scanConfig['enabled']?'Captura móvil deshabilitada':'La conexión ya no está disponible'?></h1><p><?=!$scanConfig['enabled']?'El administrador deshabilitó temporalmente la captura desde celular.':'Regrese al computador y genere una nueva conexión desde el formulario de SIVI.'?></p></section>
    <?php else: ?>
        <section class="scanner-card scanner-connection-card">
            <div class="scanner-connection-line"><span class="scanner-connection-dot" data-scanner-connection-dot></span><strong data-scanner-connection-label>Conectando con el computador…</strong><span class="scanner-countdown" data-scanner-countdown><?=sprintf('%02d:00',(int)$scanConfig['session_minutes'])?></span></div>
            <div class="scanner-pairing"><span>Código de conexión</span><strong><?=e($pairingCode)?></strong><small>Confirme que sea igual al mostrado en el computador.</small></div>
        </section>
        <section class="scanner-card scanner-browser-help" data-scanner-browser-help hidden>
            <strong data-scanner-browser-title>Navegador integrado detectado</strong>
            <span data-scanner-browser-message>Para mejorar el acceso a la cámara, abra este mismo enlace directamente en Safari o Chrome.</span>
            <div class="scanner-browser-actions">
                <a class="scanner-button" href="<?=e(MobileScanBridge::scannerUrl($token))?>" target="_blank" rel="noopener" data-scanner-open-browser>Abrir en navegador</a>
                <button type="button" class="scanner-button" data-scanner-copy-link>Copiar enlace</button>
                <button type="button" class="scanner-button" data-scanner-share-link hidden>Compartir enlace</button>
            </div>
            <small data-scanner-browser-instructions>En iPhone: toque el menú del navegador integrado y seleccione “Abrir en Safari”.</small>
        </section>
        <section class="scanner-card">
            <label class="scanner-guided-toggle"><input type="checkbox" data-scanner-guided><span><strong>Captura guiada</strong><small>Leer primero el serial y después la Placa RNEC.</small></span></label>
            <div class="scanner-guided-progress" data-scanner-guided-progress hidden><span data-scanner-guided-step>Paso 1 de 2</span><strong data-scanner-guided-label>Escanee el serial</strong></div>
            <div class="scanner-target-heading"><div><span>Enviar lectura como</span><strong>Seleccione el campo de destino</strong></div></div>
            <div class="scanner-targets" role="radiogroup" aria-label="Campo de destino">
                <label><input type="radio" name="scan_target" value="serial_number" checked><span>Serial</span></label>
                <label><input type="radio" name="scan_target" value="placa_rnec"><span>Placa RNEC</span></label>
            </div>
        </section>
        <section class="scanner-card scanner-camera-card">
            <div class="scanner-camera" data-scanner-camera>
                <video data-scanner-video playsinline muted></video>
                <div class="scanner-frame" aria-hidden="true"><span></span></div>
                <div class="scanner-camera-placeholder" data-scanner-placeholder><div class="scanner-icon">▦</div><strong>Capture el código o la etiqueta</strong><span>Use cámara en vivo, tome una foto o seleccione una imagen.</span></div>
            </div>
            <div class="scanner-actions">
                <button type="button" class="scanner-button scanner-primary" data-scanner-start<?=$scanConfig['live_camera']?'':' hidden'?>>Escanear con cámara</button>
                <button type="button" class="scanner-button" data-scanner-photo<?=$scanConfig['image_upload']?'':' hidden'?>>Tomar fotografía</button>
                <button type="button" class="scanner-button" data-scanner-gallery<?=$scanConfig['image_upload']?'':' hidden'?>>Elegir de galería</button>
                <button type="button" class="scanner-button" data-scanner-torch hidden>Encender linterna</button>
                <button type="button" class="scanner-button" data-scanner-stop hidden>Detener cámara</button>
                <input type="file" accept="image/jpeg,image/png,image/webp,image/*" capture="environment" data-scanner-photo-input hidden>
                <input type="file" accept="image/jpeg,image/png,image/webp,image/*" data-scanner-gallery-input hidden>
            </div>
            <p class="scanner-status" data-scanner-status role="status" aria-live="polite">La cámara requiere HTTPS y permiso del navegador.</p>
        </section>
        <section class="scanner-card scanner-candidates-card" data-scanner-candidates-card hidden>
            <strong>Seleccione el valor correcto</strong>
            <p>SIVI encontró varios textos posibles en la etiqueta.</p>
            <div class="scanner-candidates" data-scanner-candidates></div>
        </section>
        <section class="scanner-card scanner-result-card" data-scanner-result-card>
            <label for="scannerValue">Valor leído o digitado</label>
            <textarea id="scannerValue" data-scanner-value rows="3" autocomplete="off" autocapitalize="characters"<?= $scanConfig['manual_entry'] ? '' : ' readonly' ?> placeholder="<?=e($scanConfig['manual_entry']?'El código aparecerá aquí. También puede escribirlo manualmente.':'El valor leído aparecerá aquí para confirmación.')?>"></textarea>
            <div class="scanner-read-meta"><span data-scanner-format>Lectura manual</span><button type="button" data-scanner-clear>Limpiar</button></div>
            <button type="button" class="scanner-button scanner-send" data-scanner-send>Enviar al computador</button>
            <button type="button" class="scanner-button scanner-retry" data-scanner-retry hidden>Reintentar confirmación</button>
            <div class="scanner-send-status" data-scanner-send-status role="status" aria-live="polite"></div>
        </section>
        <section class="scanner-help"><strong>Cómo usarlo</strong><ol><li>Seleccione Serial, Placa RNEC o active Captura guiada.</li><li>Escanee en vivo, tome una fotografía o seleccione una imagen de la galería.</li><li>Revise el valor detectado.</li><li>Envíelo y espere la confirmación del computador.</li></ol><p>La conexión es temporal. Las imágenes se procesan para leer el código o texto y no se incorporan como evidencia del inventario.</p></section>
    <?php endif; ?>
</main>
<?php if ($available): ?><script src="assets/mobile-scanner.js?v=<?=$version?>" defer></script><?php endif; ?>
</body>
</html><?php
}

function site_quality_gate_page(): void
{
    $campaignId=(int)($_GET['campaign_id']??$_POST['campaign_id']??0);
    $sedeId=(int)($_GET['sede_id']??$_POST['sede_id']??0);
    if($campaignId<1||$sedeId<1||!campaign_accessible_to_current_user($campaignId)||!Scope::canAccessSede($sedeId)){
        render_error('Acceso denegado','No puede ejecutar el control de calidad de esta sede.');return;
    }
    if(request_method('POST')){verify_csrf();$result=SiteQualityGate::run($campaignId,$sedeId,Auth::id());flash($result['blocking_count']===0?'success':'warning',$result['blocking_count']===0?'La revisión no encontró hallazgos bloqueantes.':'La revisión encontró '.$result['blocking_count'].' hallazgo(s) bloqueante(s).');redirect('site_quality_gate',['campaign_id'=>$campaignId,'sede_id'=>$sedeId]);}
    $sede=Database::fetchOne('SELECT s.identificador,s.nombre_sede,s.departamento,s.municipio,c.name campaign_name FROM sedes s JOIN campaign_sedes cs ON cs.sede_id=s.id JOIN campaigns c ON c.id=cs.campaign_id WHERE cs.campaign_id=? AND cs.sede_id=?',[$campaignId,$sedeId])?:[];
    $latest=SiteQualityGate::latest($campaignId,$sedeId);
    if(!$latest)$latest=SiteQualityGate::run($campaignId,$sedeId,Auth::id());
    $score=(int)($latest['score']??0);$blocking=(int)($latest['blocking_count']??0);$warnings=(int)($latest['warning_count']??0);
    $content='<div class="card"><div class="kicker">Puerta de calidad</div><h2>'.e((string)($sede['campaign_name']??'Campaña')).'</h2><p>'.e((string)($sede['identificador']??'').' · '.(string)($sede['nombre_sede']??'').' · '.(string)($sede['municipio']??'').' / '.(string)($sede['departamento']??'')).'</p><div class="metrics-grid">'.metric_card('Puntaje',$score.' / 100','Última revisión',$blocking===0?'green':'orange').metric_card('Bloqueantes',$blocking,'Deben quedar en cero',$blocking===0?'green':'red').metric_card('Advertencias',$warnings,'No bloquean el cierre','orange').'</div><div class="form-actions"><form method="post">'.csrf_field().'<input type="hidden" name="campaign_id" value="'.$campaignId.'"><input type="hidden" name="sede_id" value="'.$sedeId.'"><button class="btn" type="submit">Guardar nueva revisión</button></form><a class="btn btn-secondary" href="'.e(route_url('equipos',['campaign_id'=>$campaignId,'sede_id'=>$sedeId,'focus'=>'closure'])).'">Volver a la sede</a></div></div>';
    $findings=$latest['findings']??[];
    if(!$findings){$content.='<div class="card"><div class="alert alert-success mb-0"><strong>Sin hallazgos bloqueantes.</strong><div>La sede puede continuar con el cierre cuando cumpla también las reglas operativas de la campaña.</div></div></div>';}
    else{$content.='<div class="card"><h3>Hallazgos de la última revisión</h3><div class="quality-findings">';foreach($findings as $finding){$sev=(string)($finding['severity']??'advertencia');$route=(string)($finding['action_route']??$finding['route']??'');$content.='<article class="alert alert-'.($sev==='bloqueante'?'danger':'warning').'"><div><span class="badge badge-'.($sev==='bloqueante'?'danger':'warning').'">'.e(ucfirst($sev)).'</span><h4>'.e((string)($finding['title']??'Hallazgo')).'</h4><p>'.e((string)($finding['detail']??'')).'</p></div>'.($route!==''?'<a class="btn btn-sm btn-outline-primary" href="'.e($route).'">Resolver ahora</a>':'').'</article>';}$content.='</div></div>';}
    render_page('Control de calidad de la sede',$content,['subtitle'=>'Revise cada hallazgo y regrese a guardar una nueva revisión.']);
}

function glpi_integration_page(): void
{
    Auth::requireRole('admin_gi');GlpiControlledSync::ensureSchema();
    if(request_method('POST')){
        verify_csrf();$action=(string)($_POST['action']??'');
        try{
            if($action==='save_config'){GlpiControlledSync::saveConfig($_POST,Auth::id());flash('success','La configuración GLPI quedó guardada de forma cifrada.');}
            elseif($action==='test'){ $result=GlpiControlledSync::testConnection(); flash($result['ok']?'success':'danger',(string)$result['message']); }
            elseif($action==='preview'){ $result=GlpiControlledSync::createPreview(Auth::id(),(int)($_POST['limit_per_type']??250)); flash('success','Vista previa generada con '.$result['counts']['total'].' activo(s).'); redirect('glpi',['run_id'=>$result['run_id']]); }
            elseif($action==='apply'){ $result=GlpiControlledSync::applyPreview((int)($_POST['run_id']??0),Auth::id()); flash('success','Se aplicaron '.$result['applied'].' activo(s) confirmados. Los conflictos fueron omitidos.'); }
            elseif($action==='map'){GlpiControlledSync::saveMapping((string)($_POST['location_key']??''),(string)($_POST['location_name']??''),(int)($_POST['sede_id']??0),Auth::id());flash('success','La localización GLPI quedó homologada con la sede oficial.');}
        }catch(Throwable $e){$ref=log_exception_reference($e,'glpi_controlled_sync');flash('danger',safe_error_message($e->getMessage(),$ref));}
        redirect('glpi',['run_id'=>(int)($_POST['run_id']??0)]);
    }
    $config=GlpiControlledSync::config();$runs=GlpiControlledSync::recentRuns();$runId=(int)($_GET['run_id']??($runs[0]['id']??0));$items=$runId>0?GlpiControlledSync::runItems($runId):[];$locations=GlpiControlledSync::unmappedLocations();
    $content='<div class="card"><div class="kicker">Integración de solo consulta</div><h2>GLPI → SIVI</h2><p>SIVI consulta activos, genera una vista previa y solo modifica el inventario local después de una confirmación. Nunca crea, edita o elimina registros en GLPI.</p><form method="post">'.csrf_field().'<input type="hidden" name="action" value="save_config"><div class="form-grid">'
        .field('api_version','Versión de API',(string)$config['api_version'],'select',['required'=>true,'choices'=>['v1'=>'GLPI 10 / API V1 heredada','v2'=>'GLPI 11 / API V2 OAuth2']])
        .field('base_url','URL base de GLPI',(string)$config['base_url'],'url',['required'=>true,'placeholder'=>'https://glpi.entidad.gov.co'])
        .field('app_token','App Token V1','','password',['placeholder'=>$config['app_token_configured']?'Configurado · deje vacío para conservar':'Requerido para V1','attributes'=>['autocomplete'=>'new-password']])
        .field('user_token','User Token V1','','password',['placeholder'=>$config['user_token_configured']?'Configurado · deje vacío para conservar':'Requerido para V1','attributes'=>['autocomplete'=>'new-password']])
        .field('client_id','Client ID OAuth2',(string)$config['client_id'],'text',['placeholder'=>'Requerido para V2'])
        .field('client_secret','Client Secret OAuth2','','password',['placeholder'=>$config['client_secret_configured']?'Configurado · deje vacío para conservar':'Requerido para V2','attributes'=>['autocomplete'=>'new-password']])
        .field('username','Usuario técnico GLPI',(string)$config['username'],'text',['placeholder'=>'Requerido para V2','attributes'=>['autocomplete'=>'off']])
        .field('password','Contraseña del usuario técnico','','password',['placeholder'=>$config['password_configured']?'Configurada · deje vacío para conservar':'Requerida para V2','attributes'=>['autocomplete'=>'new-password']])
        .field('scanner_keywords','Palabras para reconocer escáneres',(string)$config['scanner_keywords'],'textarea',['help'=>'Separe por comas. Los periféricos que no coincidan se omiten de la vista previa.'])
        .'</div><label class="confirmation-box"><input type="checkbox" name="verify_tls" value="1" '.(!empty($config['verify_tls'])?'checked':'').'><span><strong>Verificar certificado TLS</strong><small>Mantenga esta opción activa en pruebas y producción.</small></span></label><div class="form-actions"><button class="btn" type="submit">Guardar configuración</button></div></form><div class="form-actions"><form method="post">'.csrf_field().'<input type="hidden" name="action" value="test"><button class="btn btn-outline-primary">Probar conexión</button></form><form method="post">'.csrf_field().'<input type="hidden" name="action" value="preview"><label class="field mb-0"><span class="form-label">Límite por tipo</span><input class="form-control" type="number" name="limit_per_type" value="250" min="1" max="1000"></label><button class="btn btn-success">Generar vista previa</button></form></div></div>';
    if($locations){$sedes=Database::fetchAll('SELECT id,identificador,nombre_sede,departamento,municipio FROM sedes ORDER BY departamento,municipio,identificador');$content.='<div class="card"><h3>Localizaciones pendientes de homologar</h3><p>Asocie cada localización de GLPI con una sede oficial y genere una nueva vista previa.</p>';foreach($locations as $loc){$content.='<form method="post" class="mapping-row">'.csrf_field().'<input type="hidden" name="action" value="map"><input type="hidden" name="location_key" value="'.e((string)$loc['location_key']).'"><input type="hidden" name="location_name" value="'.e((string)$loc['location_name']).'"><div><strong>'.e((string)($loc['location_name']?:$loc['location_key'])).'</strong><small>'.(int)$loc['items'].' activo(s)</small></div><select class="form-select" name="sede_id" required><option value="">Seleccione sede</option>';foreach($sedes as $sede){$content.='<option value="'.(int)$sede['id'].'">'.e((string)$sede['identificador'].' · '.$sede['nombre_sede'].' · '.$sede['municipio'].' / '.$sede['departamento']).'</option>';}$content.='</select><button class="btn btn-sm">Homologar</button></form>';}$content.='</div>';}
    $content.='<div class="card"><h3>Historial de sincronizaciones</h3><div class="table-wrap"><table><thead><tr><th>Ejecución</th><th>Estado</th><th>Total</th><th>Nuevos</th><th>Actualizados</th><th>Conflictos</th><th>Sin sede</th><th>Aplicados</th><th></th></tr></thead><tbody>';foreach($runs as $run){$content.='<tr><td>#'.(int)$run['id'].'<br><small>'.e((string)$run['created_at']).'</small></td><td>'.status_badge((string)$run['status']).'</td><td>'.(int)$run['total_items'].'</td><td>'.(int)$run['new_items'].'</td><td>'.((int)$run['updated_items']+(int)$run['linked_items']).'</td><td>'.(int)$run['conflict_items'].'</td><td>'.(int)$run['unmapped_items'].'</td><td>'.(int)$run['applied_items'].'</td><td><a class="btn btn-sm btn-outline-primary" href="'.e(route_url('glpi',['run_id'=>(int)$run['id']])).'">Ver</a></td></tr>';}$content.='</tbody></table></div></div>';
    if($items){$content.='<div class="card"><div class="section-heading"><div><h3>Vista previa #'.$runId.'</h3><p>Los conflictos y activos sin sede se omiten automáticamente.</p></div><form method="post">'.csrf_field().'<input type="hidden" name="action" value="apply"><input type="hidden" name="run_id" value="'.$runId.'"><button class="btn btn-success" data-confirm="¿Aplicar únicamente los activos sin conflicto y con sede homologada?">Aplicar vista previa confirmada</button></form></div><div class="table-wrap"><table><thead><tr><th>Decisión</th><th>Activo</th><th>Serial / Placa</th><th>Localización</th><th>Sede SIVI</th><th>Motivo</th></tr></thead><tbody>';foreach($items as $item){$content.='<tr><td>'.status_badge((string)$item['decision']).'</td><td>'.e((string)$item['asset_category']).'<br><strong>'.e((string)$item['name']).'</strong></td><td>'.e((string)$item['serial_number']).'<br>'.e((string)$item['placa_rnec']).'</td><td>'.e((string)($item['location_name']?:$item['location_key'])).'</td><td>'.e((string)($item['identificador']??'')).'<br>'.e((string)($item['nombre_sede']??'')).'</td><td>'.e((string)$item['decision_reason']).'</td></tr>';}$content.='</tbody></table></div></div>';}
    render_page('Integración GLPI',$content,['subtitle'=>'Consulta controlada, vista previa, homologación territorial y aplicación confirmada.']);
}
