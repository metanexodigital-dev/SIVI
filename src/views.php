<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: src/views.php
 * Propósito: Construye las vistas, formularios y componentes HTML principales de SIVI.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

/**
 * Muestra Notificaciones y Correcciones únicamente cuando existe trabajo
 * pendiente para el usuario/sede o dentro de su alcance territorial.
 * Ante un error de consulta se conserva el acceso visible para no ocultar
 * accidentalmente una tarea operativa.
 *
 * @return array{notificaciones:bool,correcciones:bool}
 */
function navigation_pending_modules(array $user): array
{
    static $cache = [];
    $userId = (int)($user['id'] ?? 0);
    $role = (string)($user['role'] ?? '');
    $sedeId = (int)($user['sede_id'] ?? 0);
    $key = $userId . '|' . $role . '|' . $sedeId;
    if (isset($cache[$key])) return $cache[$key];

    try {
        if ($role === 'registrador') {
            $notifications = $userId > 0 && $sedeId > 0 && (int)(Database::fetchOne(
                'SELECT COUNT(*) total FROM internal_notifications WHERE user_id=? AND read_at IS NULL AND (sede_id IS NULL OR sede_id=?)',
                [$userId,$sedeId]
            )['total'] ?? 0) > 0;
            $corrections = $sedeId > 0 && (int)(Database::fetchOne(
                "SELECT COUNT(*) total FROM validation_corrections vc "
                . "JOIN equipment_validations ev ON ev.id=vc.validation_id "
                . "WHERE vc.status='pendiente' AND ev.reported_by_sede_id=?",
                [$sedeId]
            )['total'] ?? 0) > 0;
        } else {
            [$scopeWhere,$scopeParams] = Scope::sedeCondition('s');
            $notificationParams = array_merge([$userId], $scopeParams);
            $notifications = $userId > 0 && (int)(Database::fetchOne(
                "SELECT COUNT(*) total FROM internal_notifications n "
                . "LEFT JOIN sedes s ON s.id=n.sede_id "
                . "WHERE n.user_id=? AND n.read_at IS NULL "
                . "AND (n.sede_id IS NULL OR (s.id IS NOT NULL AND {$scopeWhere}))",
                $notificationParams
            )['total'] ?? 0) > 0;
            $corrections = (int)(Database::fetchOne(
                "SELECT COUNT(*) total FROM validation_corrections vc "
                . "JOIN equipment_validations ev ON ev.id=vc.validation_id "
                . "JOIN sedes s ON s.id=ev.reported_by_sede_id "
                . "WHERE vc.status='pendiente' AND {$scopeWhere}",
                $scopeParams
            )['total'] ?? 0) > 0;
        }

        return $cache[$key] = [
            'notificaciones' => $notifications,
            'correcciones' => $corrections,
        ];
    } catch (Throwable) {
        return $cache[$key] = [
            'notificaciones' => true,
            'correcciones' => true,
        ];
    }
}

function render_page(string $title, string $content, array $options = []): void
{
    $user = Auth::user();
    $flashes = pull_flashes();
    $appName = Env::get('APP_NAME', 'SIVI');
    $appVersion = AppVersion::package();
    $versionAsset = rawurlencode(AppVersion::buildId());
    $environmentLabel = AppVersion::environmentLabel();
    $idleMinutes = max(5, min(240, (int)(Env::get('SESSION_IDLE_TIMEOUT_MINUTES', '30') ?? '30')));
    $warningMinutes = max(1, min(5, (int)(Env::get('SESSION_WARNING_MINUTES', '2') ?? '2')));
    $page = (string)($_GET['page'] ?? 'dashboard');
    $pwaInstallEnabled = AppSettings::pwaInstallEnabled();
    $nav = '';
    $initializationReady = true;
    if ($user) {
        $pendingNavigation = navigation_pending_modules($user);
        try {
            $initializationReady = InitializationState::isReady();
        } catch (Throwable) {
            $initializationReady = false;
        }
        $activeRoute = match ($page) {
            'equipo_validar','equipo_asignar','historial_equipo','campania_sede_contacto','acta_sede','buscar_equipo' => 'equipos',
            'campania_crear','campania_accion' => 'campanias',
            'directorio_accion' => 'directorio',
            'traslado_accion' => 'traslados',
            'reapertura_accion' => 'reaperturas',
            'correccion_accion' => 'correcciones',
            'recordatorio_accion' => 'recordatorios',
            'seguimiento_accion' => 'seguimiento',
            'informe_exportar','informe_imprimir' => 'informes',
            'glpi_accion' => 'glpi',
            'site_quality_gate' => 'calidad',
            'placa_config' => 'placa_config',
            'novedad_accion' => 'novedades',
            'usuarios_plantilla','usuarios_importar','usuario_estado','usuario_clave','usuario_editar' => 'usuarios',
            default => $page,
        };
        if ((string)$user['role'] === 'registrador') {
            $operationalMenu = [
                ['equipos','Validar inventario','▣'],
                ['adicionales','Registrar equipos adicionales','＋'],
                ['novedades','Novedades','!'],
            ];
            if ($pendingNavigation['notificaciones']) $operationalMenu[] = ['notificaciones','Notificaciones','🔔'];
            if ($pendingNavigation['correcciones']) $operationalMenu[] = ['correcciones','Correcciones','✎'];
            $groups = ['Validación de inventario' => $operationalMenu];
        } elseif ((string)$user['role'] === 'formador') {
            // El Formador conserva el flujo operativo del Registrador y agrega
            // herramientas territoriales dentro de los departamentos asignados.
            // Campañas se presenta exclusivamente en modo consulta.
            $operationalMenu = [
                ['equipos','Validar inventario','▣'],
                ['adicionales','Registrar equipos adicionales','＋'],
                ['novedades','Novedades','!'],
            ];
            if ($pendingNavigation['notificaciones']) $operationalMenu[] = ['notificaciones','Notificaciones','🔔'];
            if ($pendingNavigation['correcciones']) $operationalMenu[] = ['correcciones','Correcciones','✎'];
            $groups = [
                'Validación de inventario' => $operationalMenu,
                'Gestión territorial' => [
                    ['sedes','Sedes','⌖'],
                    ['campanias','Campañas · solo lectura','✓'],
                    ['seguimiento','Seguimiento','↳'],
                    ['calidad','Control de calidad','◎'],
                    ['traslados','Traslados','⇄'],
                    ['reaperturas','Reaperturas','↺'],
                    ['inconsistencias','Inconsistencias','⚠'],
                    ['informes','Informes','▤'],
                ],
            ];
        } else {
            $dailyMenu = [
                ['dashboard','Inicio','⌂'],
                ['equipos','Validar inventario','▣'],
                ['adicionales','Equipos adicionales','＋'],
                ['novedades','Novedades','!'],
            ];
            if ($pendingNavigation['notificaciones']) $dailyMenu[] = ['notificaciones','Notificaciones','🔔'];
            if ($pendingNavigation['correcciones']) $dailyMenu[] = ['correcciones','Correcciones','✎'];
            $groups = [
                'Trabajo diario' => $dailyMenu,
                'Gestión territorial' => [
                    ['sedes','Sedes','⌖'],
                    ['campanias','Campañas','✓'],
                    ['seguimiento','Seguimiento','↳'],
                    ['calidad','Control de calidad','◎'],
                    ['traslados','Traslados','⇄'],
                    ['reaperturas','Reaperturas','↺'],
                    ['inconsistencias','Inconsistencias','⚠'],
                ],
                'Administración' => [
                    ['directorio','Directorio institucional','◉'],
                    ['homologaciones','Homologaciones','≋'],
                    ['importar','Importar inventario','⇧'],
                    ['diagnostico','Centro de diagnóstico','◆'],
                    ['sistema','Estado del sistema','♥'],
                    ['usuarios','Usuarios','♟'],
                    ['recordatorios','Recordatorios','⏰'],
                    ['configuracion','Configuración','⚙'],
                    ['correo','Correo y notificaciones','✉'],
                    ['glpi','Integración GLPI','⇆'],
                    ['placa_config','Configuración de Placa RNEC','▦'],
                    ['informes','Centro de informes','▤'],
                    ['reporte_ejecutivo','Reporte ejecutivo anterior','▥'],
                    ['respaldos','Respaldos','⬇'],
                    ['versionamiento','Versionamiento','#'],
                    ['auditoria','Auditoría','◷'],
                ],
            ];
        }
        $allowed = match ((string)$user['role']) {
            'registrador' => ['equipos','adicionales','novedades','notificaciones','correcciones'],
            'formador' => ['equipos','adicionales','novedades','notificaciones','correcciones','sedes','campanias','seguimiento','calidad','traslados','reaperturas','inconsistencias','informes'],
            'admin_gi' => ['dashboard','equipos','adicionales','novedades','notificaciones','correcciones','sedes','campanias','seguimiento','calidad','traslados','reaperturas','inconsistencias','directorio','homologaciones','importar','diagnostico','sistema','usuarios','recordatorios','configuracion','correo','glpi','placa_config','informes','reporte_ejecutivo','auditoria'],
            'superadmin' => array_merge(['dashboard','equipos','adicionales','novedades','notificaciones','correcciones','sedes','campanias','seguimiento','calidad','traslados','reaperturas','inconsistencias','directorio','homologaciones','importar','diagnostico','sistema','usuarios','recordatorios','configuracion','correo','glpi','placa_config','informes','reporte_ejecutivo','auditoria'], ['respaldos','versionamiento']),
            default => ['dashboard'],
        };
        if (!$initializationReady) {
            $allowed = match ((string)$user['role']) {
                'admin_gi' => ['dashboard','importar','diagnostico','sistema'],
                'superadmin' => ['dashboard','sistema'],
                default => ['dashboard'],
            };
        }
        foreach ($groups as $groupLabel => $items) {
            $groupItems = '';
            foreach ($items as [$route,$label,$icon]) {
                if (!in_array($route, $allowed, true)) continue;
                $isActive = $activeRoute === $route;
                $groupItems .= '<a class="nav-link ' . ($isActive ? 'active' : '') . '" href="' . e(route_url($route)) . '"' . ($isActive ? ' aria-current="page"' : '') . '><span class="nav-icon" aria-hidden="true">' . $icon . '</span><span class="nav-label">' . e($label) . '</span></a>';
            }
            if ($groupItems !== '') {
                $nav .= '<div class="nav-group"><div class="nav-group-title">' . e($groupLabel) . '</div>' . $groupItems . '</div>';
            }
        }
    }
    $flashHtml = '';
    foreach ($flashes as $flash) {
        $flashHtml .= '<div class="alert alert-' . e($flash['type']) . ' alert-dismissible fade show" role="alert"><span>' . e($flash['message']) . '</span><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button></div>';
    }
    $roleInfo = $user ? role_label($user['role']) : '';
    $operationalHome = $user && in_array((string)$user['role'], ['registrador','formador'], true);
    $homeRoute = $operationalHome ? 'equipos' : 'dashboard';
    $homeLabel = $operationalHome ? 'Validar inventario' : 'Inicio';
    $scopeInfo = '';
    if ($user) {
        if ($user['role'] === 'registrador') $scopeInfo = $user['sede_identificador'] . ' · ' . $user['nombre_sede'];
        elseif ($user['role'] === 'formador') $scopeInfo = 'Departamentos: ' . (implode(', ', $user['departments']) ?: 'Sin asignación');
        else $scopeInfo = 'Cobertura nacional';
    }

    echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">';
    echo '<title>' . e($title) . ' · ' . e($appName) . '</title><meta name="theme-color" content="#0B4EA2"><meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="default"><meta name="apple-mobile-web-app-title" content="SIVI"><link href="assets/vendor/bootstrap.min.css?v=' . e($versionAsset) . '" rel="stylesheet"><link rel="icon" href="assets/brand/favicon/favicon.ico"><link rel="apple-touch-icon" href="assets/brand/favicon/apple-touch-icon.png"><link rel="manifest" href="manifest.webmanifest"><link rel="stylesheet" href="assets/app.css?v=' . e($versionAsset) . '"><link rel="stylesheet" href="assets/plate-ux.css?v=' . e($versionAsset) . '">' . ($user ? '<link rel="stylesheet" href="assets/sivi-onboarding.css?v=' . e($versionAsset) . '">' : '') . '</head><body data-page="' . e($page) . '" data-pwa-install-enabled="' . ($pwaInstallEnabled ? '1' : '0') . '" data-session-idle-ms="' . e((string)($idleMinutes * 60 * 1000)) . '" data-session-warning-ms="' . e((string)($warningMinutes * 60 * 1000)) . '"><a class="skip-link" href="#mainContent">Saltar al contenido principal</a>';
    if (!$user) {
        echo '<main class="auth-shell">' . $flashHtml . $content . ($pwaInstallEnabled ? '<button type="button" class="btn btn-light auth-install-button" data-pwa-install hidden>Instalar SIVI</button>' : '') . '<div class="auth-version-meta">SIVI v' . e($appVersion) . ' · ' . e($environmentLabel) . '</div></main>' . pwa_install_modal($pwaInstallEnabled) . '<script src="assets/vendor/bootstrap.bundle.min.js?v=' . e($versionAsset) . '" defer></script><script src="assets/app.js?v=' . e($versionAsset) . '" defer></script><script src="assets/plate-entry.js?v=' . e($versionAsset) . '" defer></script></body></html>';
        return;
    }
    echo '<div class="app-shell"><aside class="sidebar" id="appSidebar"><div class="brand"><div class="sivi-logo-box sivi-logo-box--sidebar"><img class="sivi-logo-image" src="assets/brand/icons/sivi-icon-64x64.png?v=' . e($versionAsset) . '" alt="SIVI"><div class="sivi-brand-copy"><strong>SIVI</strong><small>Sistema Integrado de Verificación de Inventario</small></div></div></div><nav>' . $nav . '</nav><div class="sidebar-footer"><div class="user-name">' . e($user['name']) . '</div><div class="user-role">' . e($roleInfo) . '</div><div class="user-scope">' . e($scopeInfo) . '</div>' . (!$initializationReady ? '<div class="badge text-bg-warning mt-2">Inicialización pendiente</div>' : '') . '<div class="app-version-meta"><span>v' . e($appVersion) . '</span><span>' . e($environmentLabel) . '</span></div></div></aside><button class="sidebar-backdrop" id="sidebarBackdrop" type="button" aria-label="Cerrar menú"></button>';
    $topActions = (string)($options['actions'] ?? '');
    $globalSearch = $initializationReady
        ? '<form class="topbar-global-search" method="get" action="' . e(route_url('buscar_equipo')) . '" data-global-equipment-search><input type="hidden" name="page" value="buscar_equipo"><label class="visually-hidden" for="topbarGlobalSearch">Buscar equipo</label><input id="topbarGlobalSearch" class="form-control form-control-sm" name="q" placeholder="Buscar serial, placa o hostname" autocomplete="off"><button class="btn btn-sm btn-outline-primary" type="submit" aria-label="Buscar">⌕</button></form><span class="network-indicator" data-network-indicator aria-live="polite">En línea</span>'
        : '';
    echo '<section class="main"><header class="topbar sticky-top"><div class="topbar-left"><button class="btn btn-sm btn-outline-secondary sidebar-toggle" type="button" id="sidebarToggle" aria-label="Mostrar u ocultar menú" aria-controls="appSidebar" aria-expanded="true">☰</button><div class="page-heading"><nav class="breadcrumbs" aria-label="Ruta de navegación"><a href="' . e(route_url($homeRoute)) . '">' . e($homeLabel) . '</a><span aria-hidden="true">/</span><span aria-current="page">' . e($title) . '</span></nav><h1>' . e($title) . '</h1><p>' . e($options['subtitle'] ?? '') . '</p></div></div><div class="topbar-actions">' . $globalSearch . $topActions . '<div class="dropdown"><button class="btn btn-light border dropdown-toggle user-menu-button" type="button" data-bs-toggle="dropdown" aria-expanded="false"><span class="user-avatar">' . e(mb_strtoupper(mb_substr((string)$user['name'],0,1))) . '</span><span class="user-menu-text"><strong>' . e($user['name']) . '</strong><small>' . e($roleInfo) . '</small></span></button><ul class="dropdown-menu dropdown-menu-end shadow"><li><h6 class="dropdown-header">' . e($scopeInfo) . '</h6></li>' . ($initializationReady ? '<li><a class="dropdown-item" href="' . e(route_url('notificaciones')) . '">Ver notificaciones</a></li>' : '') . '<li><button class="dropdown-item" type="button" data-sivi-tour-open>Guía de SIVI</button></li><li><a class="dropdown-item" href="' . e(route_url('cambiar_clave')) . '">Cambiar contraseña</a></li>' . ($pwaInstallEnabled ? '<li><button class="dropdown-item" type="button" data-pwa-install hidden>Instalar SIVI en este dispositivo</button></li>' : '') . '<li><hr class="dropdown-divider"></li><li><a class="dropdown-item text-danger fw-semibold" href="' . e(route_url('logout')) . '" data-confirm="¿Desea cerrar la sesión?">Cerrar sesión</a></li></ul></div></div></header><div class="content" id="mainContent" tabindex="-1">' . $flashHtml . $content . '</div></section></div>';
    echo '<div class="modal fade" id="sessionWarningModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">La sesión está por vencer</h5></div><div class="modal-body"><p>Por seguridad, su sesión se cerrará en <strong id="sessionCountdown">2:00</strong> por inactividad.</p></div><div class="modal-footer"><a class="btn btn-outline-secondary" href="' . e(route_url('logout')) . '">Cerrar sesión</a><button class="btn btn-primary" type="button" id="continueSession">Continuar sesión</button></div></div></div></div>';
    echo pwa_install_modal($pwaInstallEnabled);
    echo '<script src="assets/vendor/bootstrap.bundle.min.js?v=' . e($versionAsset) . '" defer></script><script src="assets/app.js?v=' . e($versionAsset) . '" defer></script><script src="assets/plate-entry.js?v=' . e($versionAsset) . '" defer></script><script src="assets/sivi-onboarding.js?v=' . e($versionAsset) . '" defer></script></body></html>';
}

function pwa_install_modal(bool $enabled): string
{
    if (!$enabled) return '';
    return '<div class="modal fade" id="pwaInstallModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content pwa-install-modal"><div class="modal-header"><div><div class="kicker">Aplicación instalable</div><h5 class="modal-title">Instalar SIVI</h5></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div><div class="modal-body"><div class="pwa-install-brand"><img src="assets/brand/pwa/icon-192x192.png" alt=""><div><strong>SIVI</strong><span>Acceso rápido, pantalla completa y mejor experiencia móvil.</span></div></div><div data-pwa-android-instructions hidden><p>Pulse <strong>Instalar aplicación</strong> para agregar SIVI al dispositivo.</p><button type="button" class="btn btn-primary w-100" data-pwa-confirm-install>Instalar aplicación</button></div><div data-pwa-ios-instructions hidden><p>En Safari:</p><ol><li>Pulse el botón <strong>Compartir</strong>.</li><li>Seleccione <strong>Añadir a pantalla de inicio</strong>.</li><li>Confirme con <strong>Añadir</strong>.</li></ol></div><div data-pwa-generic-instructions hidden><p>Abra el menú del navegador y seleccione <strong>Instalar aplicación</strong> o <strong>Añadir a pantalla de inicio</strong>.</p></div><div class="pwa-install-status" data-pwa-status aria-live="polite"></div></div></div></div></div>';
}

function render_error(string $title, string $message): void
{
    render_page($title, '<div class="card"><h2>' . e($title) . '</h2><p>' . e($message) . '</p><a class="btn" href="index.php" data-history-back>Regresar</a></div>');
}

function metric_card(string $label, int|string $value, string $hint = '', string $tone = 'blue'): string
{
    return '<div class="metric metric-' . e($tone) . '"><div class="metric-label">' . e($label) . '</div><div class="metric-value">' . e($value) . '</div><div class="metric-hint">' . e($hint) . '</div></div>';
}

function field(string $name, string $label, mixed $value = '', string $type = 'text', array $options = []): string
{
    $required = !empty($options['required']) ? ' required' : '';
    $readonly = !empty($options['readonly']) ? ' readonly' : '';
    $placeholder = isset($options['placeholder']) ? ' placeholder="' . e($options['placeholder']) . '"' : '';
    $attributes = '';
    foreach (($options['attributes'] ?? []) as $attribute => $attributeValue) {
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_:-]*$/', (string)$attribute)) continue;
        if ($attributeValue === true) $attributes .= ' ' . e($attribute);
        elseif ($attributeValue !== false && $attributeValue !== null) $attributes .= ' ' . e($attribute) . '="' . e($attributeValue) . '"';
    }
    $help = isset($options['help']) ? '<div class="form-text">' . e($options['help']) . '</div>' : '';
    $wide = !empty($options['wide']) ? ' field-wide' : '';
    $html = '<div class="field mb-3' . $wide . '"><label class="form-label" for="field-' . e($name) . '">' . e($label) . (!empty($options['required']) ? ' <span class="text-danger">*</span>' : '') . '</label>';
    if ($type === 'textarea') {
        $html .= '<textarea class="form-control" id="field-' . e($name) . '" name="' . e($name) . '"' . $required . $readonly . $placeholder . $attributes . '>' . e($value) . '</textarea>';
    } elseif ($type === 'select') {
        $html .= '<select class="form-select" id="field-' . e($name) . '" name="' . e($name) . '"' . $required . $attributes . '>';
        foreach (($options['choices'] ?? []) as $key => $choice) {
            $selected = (string)$value === (string)$key ? ' selected' : '';
            $html .= '<option value="' . e($key) . '"' . $selected . '>' . e($choice) . '</option>';
        }
        $html .= '</select>';
    } else {
        $html .= '<input class="form-control" id="field-' . e($name) . '" type="' . e($type) . '" name="' . e($name) . '" value="' . e($value) . '"' . $required . $readonly . $placeholder . $attributes . '>';
    }
    return $html . $help . '</div>';
}

function empty_state(string $title, string $text): string
{
    return '<div class="empty-state"><div class="empty-icon">◇</div><h3>' . e($title) . '</h3><p>' . e($text) . '</p></div>';
}
