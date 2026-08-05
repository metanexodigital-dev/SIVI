<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: src/helpers.php
 * Propósito: Agrupa funciones auxiliares reutilizadas por los módulos y formularios.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function env_value(string $key, ?string $default = null): ?string
{
    return Env::get($key, $default);
}

/**
 * Convierte un porcentaje en una clase CSS predefinida. Evita estilos inline
 * y permite una Content-Security-Policy estricta.
 */
function progress_width_class(int|float|string $percent): string
{
    $value = max(0, min(100, (int)round((float)$percent)));
    return 'progress-w-' . $value;
}

/**
 * Devuelve la IP del cliente. Los encabezados del proxy solo se usan cuando
 * APP_TRUST_PROXY_HEADERS=true y la aplicación está detrás de un proxy confiable.
 */
function client_ip(): string
{
    $trustProxy = function_exists('sivi_request_from_trusted_proxy')
        && sivi_request_from_trusted_proxy();
    if ($trustProxy) {
        $candidates = [
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
            $_SERVER['HTTP_X_REAL_IP'] ?? '',
            explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0] ?? '',
        ];
        foreach ($candidates as $candidate) {
            $candidate = trim((string)$candidate);
            if (filter_var($candidate, FILTER_VALIDATE_IP)) return $candidate;
        }
    }
    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : 'unknown';
}

/**
 * Registra el detalle técnico en storage/logs/security.log y retorna una
 * referencia segura para mostrar al usuario sin exponer rutas, SQL o credenciales.
 */
function log_exception_reference(Throwable $exception, string $context): string
{
    try {
        $reference = 'ERR-' . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3)));
    } catch (Throwable) {
        $reference = 'ERR-' . date('Ymd-His') . '-' . strtoupper(substr(hash('sha256', uniqid('', true)), 0, 6));
    }

    $logDir = dirname(__DIR__) . '/storage/logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0770, true);
    $userId = null;
    try {
        $userId = Auth::user()['id'] ?? null;
    } catch (Throwable) {
        $userId = null;
    }
    $payload = [
        'timestamp' => date(DATE_ATOM),
        'reference' => $reference,
        'context' => $context,
        'exception' => get_class($exception),
        'message' => $exception->getMessage(),
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'request_path' => isset($_SERVER['REQUEST_URI'])
            ? mb_substr((string)(parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: ''), 0, 500)
            : null,
        'user_id' => $userId,
        'ip' => client_ip(),
        'trace' => $exception->getTraceAsString(),
    ];
    $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    @file_put_contents($logDir . '/security.log', $line, FILE_APPEND | LOCK_EX);
    error_log("[{$reference}] {$context}: {$exception->getMessage()}");
    return $reference;
}

/**
 * Emite eventos de seguridad en JSON para consumo por Docker logs/SIEM y
 * conserva una copia local rotada por la política operativa.
 *
 * @param array<string,mixed> $context
 */
function security_event(string $event, array $context = [], string $severity = 'info'): void
{
    $allowedSeverity = ['debug','info','notice','warning','error','critical'];
    if (!in_array($severity, $allowedSeverity, true)) $severity = 'info';

    $userId = null;
    try {
        $userId = Auth::id();
    } catch (Throwable) {
        $userId = null;
    }

    $payload = [
        'timestamp' => date(DATE_ATOM),
        'application' => 'SIVI',
        'event' => preg_replace('/[^a-zA-Z0-9_.:-]+/', '_', $event) ?: 'security_event',
        'severity' => $severity,
        'user_id' => $userId,
        'ip' => client_ip(),
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? null,
        'request_path' => isset($_SERVER['REQUEST_URI'])
            ? mb_substr((string)(parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: ''), 0, 500)
            : null,
        'context' => $context,
    ];

    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($json === false) return;

    $logDir = dirname(__DIR__) . '/storage/logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0770, true);
    @file_put_contents(
        $logDir . '/security-events.log',
        $json . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );

    // Docker captura stderr; el prefijo facilita reglas de ingestión en SIEM.
    error_log('SIVI_SECURITY_EVENT ' . $json);
}

function safe_error_message(string $message, string $reference): string
{
    return rtrim($message, '. ') . '. Referencia: ' . $reference . '.';
}

/**
 * Traduce fallos conocidos de importación a instrucciones seguras y útiles.
 * El detalle técnico completo continúa almacenado en security.log.
 */
function inventory_import_error_message(Throwable $exception, string $reference): string
{
    $root = $exception;
    while ($root->getPrevious() instanceof Throwable) {
        $root = $root->getPrevious();
    }
    $technicalMessage = trim($root->getMessage());
    $knownMessages = [
        'No se encontró una hoja de inventario utilizable',
        'La hoja de sedes fue encontrada',
        'El archivo no contiene una hoja de sedes',
        'No se identificaron activos en la hoja de inventario',
        'No se identificaron registros de activos en la hoja seleccionada',
        'No se encontró una hoja de inventario utilizable en el reporte GLPI',
        'No fue posible abrir el archivo XLSX',
        'El XLSX no contiene la estructura esperada',
        'La extensión PHP Zip no está habilitada',
        'La extensión PHP XMLReader no está habilitada',
        'Debe importar primero',
        'Debe importar después',
        'Primero debe completar',
        'Los reportes complementarios solo se habilitan',
        'La importación de sedes no produjo',
        'La importación GLPI no produjo',
        'La importación de Almacén no produjo',
        'Seleccione una etapa de importación válida',
        'Seleccione Monitores o Impresoras',
    ];
    foreach ($knownMessages as $prefix) {
        if (str_starts_with($technicalMessage, $prefix)) {
            return safe_error_message($technicalMessage, $reference);
        }
    }

    if ($root instanceof PDOException) {
        $driverCode = (int)($root->errorInfo[1] ?? 0);
        if (in_array($driverCode, [1054, 1146], true)) {
            return safe_error_message('La base de datos no está actualizada para importar inventarios. Ejecute las migraciones y verifique el esquema', $reference);
        }
        if ($driverCode === 1062) {
            return safe_error_message('Se encontró un identificador repetido durante la importación. El archivo quedó sin aplicar para proteger el inventario existente', $reference);
        }
        if (in_array($driverCode, [1205, 1213], true)) {
            return safe_error_message('La base de datos estaba ocupada. Intente nuevamente cuando no haya otra importación en ejecución', $reference);
        }
    }

    $lower = mb_strtolower($technicalMessage);
    if (str_contains($lower, 'allowed memory size') || str_contains($lower, 'maximum execution time')) {
        return safe_error_message('El archivo supera los recursos disponibles para una sola importación. Divídalo por categoría o aumente temporalmente los límites de PHP', $reference);
    }

    return safe_error_message('No fue posible completar la importación', $reference);
}

/**
 * Neutraliza CSV/Formula Injection al abrir el archivo en Excel o similares.
 * Si el primer carácter significativo es =, +, - o @, antepone apóstrofe.
 */
function csv_safe_cell(mixed $value): string
{
    if ($value === null) return '';
    if (is_bool($value)) return $value ? '1' : '0';
    $text = str_replace("\0", '', (string)$value);
    if (preg_match('/^[\x00-\x20]*[=+\-@]/', $text) === 1) {
        return "'" . $text;
    }
    return $text;
}

function route_url(string $page, array $params = []): string
{
    $params = array_merge(['page' => $page], $params);
    return 'index.php?' . http_build_query($params);
}

function redirect(string $page, array $params = []): never
{
    header('Location: ' . route_url($page, $params));
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function pull_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = (string)($_POST['csrf'] ?? '');
    if (!hash_equals((string)($_SESSION['csrf'] ?? ''), $token)) {
        http_response_code(419);
        render_error('Sesión vencida', 'Actualice la página e intente nuevamente.');
        exit;
    }
}

function request_method(string $method): bool
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === strtoupper($method);
}

function audit(string $action, ?string $entityType = null, ?int $entityId = null, mixed $oldValues = null, mixed $newValues = null): void
{
    try {
        $userId = Auth::user()['id'] ?? null;
        Database::execute(
            'INSERT INTO audit_logs (user_id,action,entity_type,entity_id,old_values,new_values,ip_address) VALUES (?,?,?,?,?,?,?)',
            [
                $userId,
                $action,
                $entityType,
                $entityId,
                $oldValues === null ? null : json_encode($oldValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $newValues === null ? null : json_encode($newValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                client_ip(),
            ]
        );
        security_event('audit.' . $action, [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ], 'notice');
    } catch (Throwable) {
        // La auditoría no debe bloquear la operación principal.
    }
}

function role_label(string $role): string
{
    return match ($role) {
        'registrador' => 'Registrador',
        'formador' => 'Formador',
        'admin_gi' => 'Admin GI',
        'superadmin' => 'Superadministrador',
        default => $role,
    };
}

function status_badge(string $status): string
{
    $labels = [
        'pendiente' => 'Pendiente', 'confirmado' => 'Confirmado', 'con_correccion' => 'Con corrección',
        'no_encontrado' => 'No encontrado', 'trasladado' => 'Trasladado', 'reparacion' => 'En reparación',
        'almacenado' => 'Almacenado', 'pendiente_baja' => 'Pendiente de baja', 'dado_baja' => 'Dado de baja',
        'publicada' => 'Publicada', 'borrador' => 'Borrador', 'programada' => 'Programada', 'activa' => 'Activa', 'cerrada' => 'Cerrada', 'en_revision' => 'En revisión', 'finalizada' => 'Finalizada', 'cancelada' => 'Cancelada',
        'aprobada' => 'Aprobada', 'devuelta' => 'Devuelta', 'reasignada' => 'Reasignada',
        'pertenece' => 'Pertenece', 'no_pertenece' => 'No pertenece', 'otro_usuario' => 'Otro usuario',
        'completado' => 'Completado', 'procesando' => 'Procesando', 'error' => 'Error',
        'activo' => 'Activo', 'inactivo' => 'Inactivo', 'para_baja' => 'Para baja',
        'en_almacen' => 'En Almacén', 'en_mantenimiento' => 'En Mantenimiento',
        'propio' => 'Propio RNEC', 'comodato' => 'Comodato',
        'donado_sin_legalizar' => 'Donado sin legalizar', 'desconocido' => 'Sin definir',
    ];
    $class = match ($status) {
        'confirmado','aprobada','completado','publicada','activa','finalizada','pertenece','activo','propio' => 'success',
        'con_correccion','pendiente','borrador','programada','en_revision','procesando','otro_usuario','inactivo','para_baja','comodato','donado_sin_legalizar' => 'warning',
        'no_encontrado','dado_baja','error','no_pertenece','devuelta','cancelada' => 'danger',
        'trasladado','reasignada','reparacion','en_almacen','en_mantenimiento' => 'info',
        default => 'neutral',
    };
    return '<span class="badge badge-' . $class . '">' . e($labels[$status] ?? $status) . '</span>';
}

/**
 * Normaliza la Placa RNEC con la política vigente. El usuario puede escribir
 * todos los números; SIVI inserta el guion después de los tres primeros.
 */

/**
 * Normaliza el valor recibido desde un formulario sin alterar sus dígitos.
 *
 * Se retiran únicamente espacios exteriores. La validación posterior rechaza
 * letras, espacios internos, guiones y cualquier símbolo diferente de 0 a 9.
 */
function normalize_contact_phone(string $value): string
{
    return trim($value);
}

/**
 * Valida números de contacto colombianos utilizados por SIVI.
 *
 * Reglas:
 * - Exactamente 10 dígitos.
 * - Teléfono fijo: inicia por 60.
 * - Celular: inicia por 3.
 */
function valid_contact_phone(string $value, bool $allowEmpty = true): bool
{
    $value = normalize_contact_phone($value);
    if ($value === '') {
        return $allowEmpty;
    }

    return preg_match('/^(?:60[0-9]{8}|3[0-9]{9})$/D', $value) === 1;
}

/**
 * Expresión HTML utilizada por los campos de teléfono.
 */
function contact_phone_pattern(): string
{
    return '(?:60[0-9]{8}|3[0-9]{9})';
}

/**
 * Mensaje común presentado debajo de los campos de contacto.
 */
function contact_phone_help(): string
{
    return 'Digite exactamente 10 números. Fijo: debe iniciar por 60. Celular: debe iniciar por 3.';
}

function normalize_placa_rnec(?string $value): ?string
{
    $raw = trim((string)$value);
    if ($raw === '') return null;
    try {
        $total = PlatePolicy::totalCharacters(Database::connection());
    } catch (Throwable) {
        $total = PlatePolicy::defaultTotalCharacters();
    }
    $result = PlatePolicy::validate($raw, $total, true);
    return $result['ok'] ? (string)$result['value'] : null;
}

function valid_placa_rnec(?string $value): bool
{
    $raw = trim((string)$value);
    if ($raw === '') return false;
    try {
        $total = PlatePolicy::totalCharacters(Database::connection());
    } catch (Throwable) {
        $total = PlatePolicy::defaultTotalCharacters();
    }
    return (bool)PlatePolicy::validate($raw, $total, true)['ok'];
}

function placa_rnec_total_characters(): int
{
    try {
        return PlatePolicy::totalCharacters(Database::connection());
    } catch (Throwable) {
        return PlatePolicy::defaultTotalCharacters();
    }
}

function placa_rnec_example(): string
{
    return PlatePolicy::example(placa_rnec_total_characters());
}

function placa_rnec_pattern(): string
{
    $total = placa_rnec_total_characters();
    return '(?:000[0-9]{' . PlatePolicy::suffixDigits($total) . '}|000-[0-9]{' . PlatePolicy::suffixDigits($total) . '})';
}

function placa_rnec_help(): string
{
    $total = placa_rnec_total_characters();
    return 'La Placa RNEC debe iniciar con 000. Escriba los números de manera continua; al completar 000, SIVI agregará automáticamente el guion. Ejemplo: ' . PlatePolicy::example($total) . '.';
}

function pagination(int $total, int $page, int $perPage, string $route, array $query = []): string
{
    $pages = max(1, (int)ceil($total / $perPage));
    if ($pages <= 1) return '';
    $html = '<nav class="pagination">';
    for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++) {
        $class = $i === $page ? 'active' : '';
        $html .= '<a class="' . $class . '" href="' . e(route_url($route, array_merge($query, ['p' => $i]))) . '">' . $i . '</a>';
    }
    return $html . '</nav>';
}


/**
 * Separa las observaciones visibles de los metadatos operativos usados por la
 * validación sin requerir columnas nuevas en la base de datos.
 *
 * @return array{notes:string,belongs_reason_other:string,placa_unavailable_reason:string}
 */
function validation_notes_parse(?string $notes): array
{
    $general = [];
    $belongsReasonOther = '';
    $plateUnavailableReason = '';

    foreach (preg_split('/\R/u', trim((string)$notes)) ?: [] as $line) {
        if (preg_match('/^\[SIVI:OTRO_MOTIVO\]\s*(.*)$/u', $line, $match) === 1) {
            $belongsReasonOther = trim((string)($match[1] ?? ''));
            continue;
        }
        if (preg_match('/^\[SIVI:PLACA_NO_VISIBLE\]\s*(.*)$/u', $line, $match) === 1) {
            $plateUnavailableReason = trim((string)($match[1] ?? ''));
            continue;
        }
        $general[] = $line;
    }

    return [
        'notes' => trim(implode("\n", $general)),
        'belongs_reason_other' => $belongsReasonOther,
        'placa_unavailable_reason' => $plateUnavailableReason,
    ];
}

function validation_notes_compose(
    string $notes,
    string $belongsReasonOther = '',
    string $plateUnavailableReason = ''
): string {
    $parts = [];
    $notes = trim($notes);
    if ($notes !== '') $parts[] = $notes;

    $belongsReasonOther = trim($belongsReasonOther);
    if ($belongsReasonOther !== '') {
        $parts[] = '[SIVI:OTRO_MOTIVO] ' . $belongsReasonOther;
    }

    $plateUnavailableReason = trim($plateUnavailableReason);
    if ($plateUnavailableReason !== '') {
        $parts[] = '[SIVI:PLACA_NO_VISIBLE] ' . $plateUnavailableReason;
    }

    return trim(implode("\n", $parts));
}

function validation_notes_for_display(?string $notes): string
{
    $parts = validation_notes_parse($notes);
    $visible = [];
    if ($parts['notes'] !== '') $visible[] = $parts['notes'];
    if ($parts['belongs_reason_other'] !== '') {
        $visible[] = 'Otro motivo: ' . $parts['belongs_reason_other'];
    }
    if ($parts['placa_unavailable_reason'] !== '') {
        $visible[] = 'Placa RNEC no visible físicamente: ' . $parts['placa_unavailable_reason'];
    }
    return trim(implode("\n", $visible));
}


function territorial_filter_fields(array $sedes, string $department = '', string $municipality = '', string $siteType = '', int $sedeId = 0): string
{
    $departments = [];
    $municipalities = [];
    $types = [];
    $municipalityTypes = [];
    foreach ($sedes as $sede) {
        $codDd = trim((string)($sede['cod_dd'] ?? ''));
        $departmentName = trim((string)($sede['departamento'] ?? ''));
        $municipalityName = trim((string)($sede['municipio'] ?? ''));
        $type = trim((string)($sede['tipo_sede'] ?? ''));
        if ($codDd !== '') $departments[$codDd] = $departmentName !== '' ? $departmentName : $codDd;
        if ($municipalityName !== '') {
            $key = $codDd . '|' . $municipalityName;
            $municipalities[$key] = ['department'=>$codDd,'name'=>$municipalityName];
            if ($type !== '') $municipalityTypes[$key][$type] = true;
        }
        if ($type !== '') $types[$type] = $type;
    }
    asort($departments, SORT_NATURAL | SORT_FLAG_CASE);
    asort($types, SORT_NATURAL | SORT_FLAG_CASE);
    uasort($municipalities, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

    $html = '<label class="field"><span>Departamento</span><select name="department" data-territorial-department><option value="">Todos los departamentos</option>';
    foreach ($departments as $code=>$name) $html .= '<option value="'.e($code).'"'.($department===(string)$code?' selected':'').'>'.e($code.' · '.$name).'</option>';
    $html .= '</select></label>';

    $html .= '<label class="field"><span>Municipio</span><select name="municipality" data-territorial-municipality><option value="">Todos los municipios</option>';
    foreach ($municipalities as $key=>$item) {
        $typeData=json_encode(array_keys($municipalityTypes[$key]??[]),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $html .= '<option value="'.e($key).'" data-department="'.e($item['department']).'" data-types="'.e($typeData).'"'.($municipality===$key?' selected':'').'>'.e($item['name']).'</option>';
    }
    $html .= '</select></label>';

    $html .= '<label class="field"><span>Tipo de sede</span><select name="site_type" data-territorial-type><option value="">Todos los tipos de sede</option>';
    foreach ($types as $type) $html .= '<option value="'.e($type).'"'.($siteType===(string)$type?' selected':'').'>'.e($type).'</option>';
    $html .= '</select></label>';

    $html .= '<label class="field"><span>Sede</span><select name="sede_id" data-territorial-sede><option value="">Todas las sedes</option>';
    foreach ($sedes as $sede) {
        $selected=$sedeId===(int)$sede['id']?' selected':'';
        $label=trim((string)$sede['identificador']).' · '.trim((string)$sede['nombre_sede']);
        $html .= '<option value="'.(int)$sede['id'].'" data-department="'.e($sede['cod_dd']).'" data-municipality="'.e($sede['cod_dd'].'|'.$sede['municipio']).'" data-type="'.e($sede['tipo_sede']).'"'.$selected.'>'.e($label).'</option>';
    }
    return $html . '</select></label>';
}

/**
 * Selectores encadenados para asignar un Registrador a una sede.
 * Orden requerido: Departamento → Municipio → Tipo de sede → Sede.
 */
function user_sede_assignment_fields(array $sedes): string
{
    $types=[];$departments=[];$municipalities=[];$municipalityTypes=[];
    foreach($sedes as $sede){
        $type=trim((string)($sede['tipo_sede']??''));$dd=trim((string)($sede['cod_dd']??''));
        $department=trim((string)($sede['departamento']??''));$municipality=trim((string)($sede['municipio']??''));
        if($type!=='')$types[$type]=$type;
        if($dd!=='')$departments[$dd]=$department!==''?$department:$dd;
        if($municipality!==''){$key=$dd.'|'.$municipality;$municipalities[$key]=['department'=>$dd,'name'=>$municipality];if($type!=='')$municipalityTypes[$key][$type]=true;}
    }
    asort($types,SORT_NATURAL|SORT_FLAG_CASE);asort($departments,SORT_NATURAL|SORT_FLAG_CASE);uasort($municipalities,static fn(array $a,array $b):int=>strnatcasecmp($a['name'],$b['name']));
    $html='<label class="field"><span>Departamento</span><select name="sede_department_filter" data-user-sede-department><option value="">Seleccione el departamento</option>';
    foreach($departments as $dd=>$name)$html.='<option value="'.e($dd).'">'.e($dd.' · '.$name).'</option>';
    $html.='</select></label>';
    $html.='<label class="field"><span>Municipio</span><select name="sede_municipality_filter" data-user-sede-municipality><option value="">Seleccione primero el departamento</option>';
    foreach($municipalities as $key=>$item){$typeData=json_encode(array_keys($municipalityTypes[$key]??[]),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$html.='<option value="'.e($key).'" data-department="'.e($item['department']).'" data-types="'.e($typeData).'">'.e($item['name']).'</option>';}
    $html.='</select></label>';
    $html.='<label class="field"><span>Tipo de sede</span><select name="sede_type_filter" data-user-sede-type><option value="">Seleccione primero el municipio</option>';
    foreach($types as $type)$html.='<option value="'.e($type).'">'.e($type).'</option>';
    $html.='</select></label>';
    $html.='<label class="field"><span>Sede del Registrador</span><select name="sede_id" data-user-sede><option value="">Seleccione la sede</option>';
    foreach($sedes as $sede){$label=trim((string)$sede['identificador']).' · '.trim((string)$sede['nombre_sede']);$html.='<option value="'.(int)$sede['id'].'" data-type="'.e($sede['tipo_sede']).'" data-department="'.e($sede['cod_dd']).'" data-municipality="'.e($sede['cod_dd'].'|'.$sede['municipio']).'">'.e($label).'</option>';}
    return $html.'</select></label>';
}

/**
 * Indica si el perfil debe seleccionar una sede antes de diligenciar módulos
 * operativos. Los Registradores trabajan exclusivamente sobre su sede asignada.
 */
function profile_requires_sede_selection(): bool
{
    $role = (string)(Auth::user()['role'] ?? '');
    return in_array($role, ['formador','admin_gi','superadmin'], true);
}

/**
 * Selectores encadenados reutilizables para módulos operativos.
 * Orden institucional: Departamento → Municipio → Tipo de sede → Sede.
 *
 * @param array $sedes Sedes visibles para el perfil autenticado.
 * @param int $selectedSedeId Sede preseleccionada al volver de un registro.
 * @param string $prefix Prefijo único para evitar colisiones cuando hay dos selectores.
 * @param string $sedeName Nombre del campo final que contiene el ID de la sede.
 * @param string $sedeLabel Etiqueta visible del último selector.
 * @param array $options gate=true identifica la sede que habilita el formulario;
 *                       exclude_current_origin=true se usa para sede destino.
 */
function module_sede_selector_fields(
    array $sedes,
    int $selectedSedeId = 0,
    string $prefix = 'scope',
    string $sedeName = 'sede_id',
    string $sedeLabel = 'Sede',
    array $options = []
): string {
    $prefix = preg_replace('/[^a-zA-Z0-9_-]/', '', $prefix) ?: 'scope';
    $types = [];
    $departments = [];
    $municipalities = [];
    $departmentTypes = [];
    $municipalityTypes = [];
    $selected = null;

    foreach ($sedes as $sede) {
        $type = trim((string)($sede['tipo_sede'] ?? ''));
        $dd = trim((string)($sede['cod_dd'] ?? ''));
        $department = trim((string)($sede['departamento'] ?? ''));
        $municipality = trim((string)($sede['municipio'] ?? ''));
        if ((int)($sede['id'] ?? 0) === $selectedSedeId) $selected = $sede;
        if ($type !== '') $types[$type] = $type;
        if ($dd !== '') {
            $departments[$dd] = $department !== '' ? $department : $dd;
            if ($type !== '') $departmentTypes[$dd][$type] = true;
        }
        if ($municipality !== '') {
            $key = $dd . '|' . $municipality;
            $municipalities[$key] = ['department'=>$dd,'name'=>$municipality];
            if ($type !== '') $municipalityTypes[$key][$type] = true;
        }
    }

    asort($types, SORT_NATURAL | SORT_FLAG_CASE);
    asort($departments, SORT_NATURAL | SORT_FLAG_CASE);
    uasort($municipalities, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

    $selectedType = trim((string)($selected['tipo_sede'] ?? ''));
    $selectedDepartment = trim((string)($selected['cod_dd'] ?? ''));
    $selectedMunicipality = $selected ? $selectedDepartment . '|' . trim((string)($selected['municipio'] ?? '')) : '';
    $gateAttribute = !empty($options['gate']) ? ' data-gate-sede-select' : '';
    $excludeAttribute = isset($options['exclude_sede_id']) && (int)$options['exclude_sede_id'] > 0
        ? ' data-exclude-sede-id="' . (int)$options['exclude_sede_id'] . '"'
        : (!empty($options['exclude_current_origin']) ? ' data-exclude-current-origin' : '');
    $destinationAttribute = !empty($options['destination']) ? ' data-destination-sede-selector' : '';

    $html = '<div class="form-grid sede-selector-grid" data-cascade-sede-selector data-selector-prefix="' . e($prefix) . '"' . $excludeAttribute . $destinationAttribute . '>';

    // Jerarquía obligatoria: Departamento → Municipio → Tipo de sede → Sede.
    $html .= '<label class="field"><span>Departamento <span class="text-danger">*</span></span><select name="' . e($prefix . '_departamento') . '" data-sede-department required><option value="">Seleccione el departamento</option>';
    foreach ($departments as $dd => $name) {
        $sel = $selectedDepartment === (string)$dd ? ' selected' : '';
        $html .= '<option value="' . e($dd) . '"' . $sel . '>' . e($dd . ' · ' . $name) . '</option>';
    }
    $html .= '</select></label>';

    $html .= '<label class="field"><span>Municipio <span class="text-danger">*</span></span><select name="' . e($prefix . '_municipio') . '" data-sede-municipality required><option value="">Seleccione primero el departamento</option>';
    foreach ($municipalities as $key => $item) {
        $typeData = json_encode(array_keys($municipalityTypes[$key] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $sel = $selectedMunicipality === (string)$key ? ' selected' : '';
        $html .= '<option value="' . e($key) . '" data-department="' . e($item['department']) . '" data-types="' . e($typeData) . '"' . $sel . '>' . e($item['name']) . '</option>';
    }
    $html .= '</select></label>';

    $html .= '<label class="field"><span>Tipo de sede <span class="text-danger">*</span></span><select name="' . e($prefix . '_tipo_sede') . '" data-sede-type required><option value="">Seleccione primero el municipio</option>';
    foreach ($types as $type) {
        $sel = $selectedType === (string)$type ? ' selected' : '';
        $html .= '<option value="' . e($type) . '"' . $sel . '>' . e($type) . '</option>';
    }
    $html .= '</select></label>';

    $html .= '<label class="field"><span>' . e($sedeLabel) . ' <span class="text-danger">*</span></span><select name="' . e($sedeName) . '" data-sede-final' . $gateAttribute . ' required><option value="">Seleccione primero el tipo de sede</option>';
    foreach ($sedes as $sede) {
        $id = (int)($sede['id'] ?? 0);
        $label = trim((string)($sede['identificador'] ?? '')) . ' · ' . trim((string)($sede['nombre_sede'] ?? ''));
        $location = trim((string)($sede['departamento'] ?? '')) . ' / ' . trim((string)($sede['municipio'] ?? ''));
        $sel = $selectedSedeId === $id ? ' selected' : '';
        $html .= '<option value="' . $id . '" data-type="' . e($sede['tipo_sede'] ?? '') . '" data-department="' . e($sede['cod_dd'] ?? '') . '" data-municipality="' . e(($sede['cod_dd'] ?? '') . '|' . ($sede['municipio'] ?? '')) . '" data-site-label="' . e($label) . '" data-site-location="' . e($location) . '"' . $sel . '>' . e($label) . '</option>';
    }
    return $html . '</select></label></div>';
}

/**
 * Resumen visible de la sede fija del Registrador.
 */
function fixed_sede_summary(array $sede): string
{
    $label = trim((string)($sede['identificador'] ?? '')) . ' · ' . trim((string)($sede['nombre_sede'] ?? ''));
    $location = trim((string)($sede['tipo_sede'] ?? '')) . ' · ' . trim((string)($sede['departamento'] ?? '')) . ' / ' . trim((string)($sede['municipio'] ?? ''));
    return '<div class="card sede-fixed-summary"><div class="kicker">Sede asignada</div><h3>' . e($label) . '</h3><p class="muted mb-0">' . e($location) . '</p></div>';
}


/**
 * Categorías institucionales de activos administradas por SIVI.
 * Almacén es la fuente oficial de la categoría cuando existe conciliación.
 */
function asset_category_labels(bool $includeOther = true): array
{
    $labels = [
        'cpu' => 'CPU',
        'portatil' => 'Portátil',
        'pc_todo_en_uno' => 'PC Todo en Uno',
        'monitor' => 'Monitor',
        'impresora' => 'Impresora',
        'escaner' => 'Escáner',
        'ups' => 'UPS',
    ];
    if ($includeOther) $labels['otro'] = 'Pendiente de clasificar';
    return $labels;
}

function asset_category_label(?string $category): string
{
    $key = strtolower(trim((string)$category));
    return asset_category_labels(true)[$key] ?? ($key !== '' ? $key : 'Pendiente de clasificar');
}

function asset_category_choices(bool $withAll = false, bool $includeOther = true): array
{
    $choices = asset_category_labels($includeOther);
    return $withAll ? ['' => 'Todas las categorías'] + $choices : $choices;
}

function is_computer_category(?string $category): bool
{
    return in_array((string)$category, ['cpu', 'portatil', 'pc_todo_en_uno'], true);
}


/** Opciones institucionales de propiedad para equipos adicionales. */
function additional_equipment_ownership_choices(): array
{
    return [
        '' => 'Seleccione el tipo de propiedad',
        'propio' => 'Propio de la RNEC',
        'comodato' => 'En comodato',
        'donado_sin_legalizar' => 'Donado sin legalizar',
    ];
}

/** Estados físicos permitidos al reportar un equipo adicional. */
function additional_equipment_state_choices(): array
{
    return [
        '' => 'Seleccione el estado actual',
        'activo' => 'Activo',
        'inactivo' => 'Inactivo',
        'para_baja' => 'Para baja',
        'dado_baja' => 'Dado de baja',
        'en_almacen' => 'En Almacén',
        'en_mantenimiento' => 'En Mantenimiento',
        'trasladado' => 'Trasladado',
    ];
}

/**
 * Campos visibles y obligatorios según la categoría del equipo adicional.
 * Los campos visibles dependen de la categoría. Únicamente propiedad, estado,
 * serial y Placa RNEC son obligatorios además de la categoría seleccionada.
 */
function additional_equipment_category_rules(): array
{
    $base = [
        'ownership_type','equipment_state','manufacturer','model',
        'serial_number','placa_rnec','technical_details',
    ];
    $required = ['ownership_type','equipment_state','serial_number','placa_rnec','technical_details'];
    $rule = static fn(string $label,string $description,array $technical,array $technicalRequired,array $extra=[]): array => [
        'label'=>$label,
        'description'=>$description,
        'visible'=>array_merge($base,$extra,$technical),
        'required'=>$required,
        'technical'=>$technical,
        'technical_required'=>$technicalRequired,
    ];
    return [
        'cpu'=>$rule('CPU','Información básica y características técnicas opcionales de la CPU.',['equipment_type','os_name','os_version','processor','memory'],['equipment_type','os_name','os_version','processor','memory'],[]),
        'portatil'=>$rule('Portátil','Información básica y características técnicas opcionales del portátil.',['equipment_type','screen_size','os_name','os_version','processor','memory'],['equipment_type','os_name','os_version','processor','memory'],[]),
        'pc_todo_en_uno'=>$rule('PC Todo en Uno','Información básica y características técnicas opcionales del Todo en Uno.',['equipment_type','screen_size','os_name','os_version','processor','memory'],['equipment_type','os_name','os_version','processor','memory'],[]),
        'monitor'=>$rule('Monitor','Información básica y características técnicas opcionales del monitor.',['equipment_type','screen_size','connection_type'],['equipment_type']),
        'impresora'=>$rule('Impresora','Información básica y características técnicas opcionales de la impresora.',['equipment_type','print_technology','connection_type'],['equipment_type']),
        'escaner'=>$rule('Escáner','Información básica y características técnicas opcionales del escáner.',['equipment_type','connection_type'],['equipment_type']),
        'ups'=>$rule('UPS','Información básica y tipo o capacidad técnica opcional de la UPS.',['equipment_type'],['equipment_type']),
        'otro'=>$rule('Pendiente de clasificar','Información básica y tipo técnico opcional.',['equipment_type'],['equipment_type'],[]),
    ];
}

/** Opciones para decidir si se diligencian características técnicas. */
function additional_equipment_technical_detail_choices(): array
{
    return [
        'no'=>'No, registrar únicamente la información básica',
        'si'=>'Sí, diligenciar las características técnicas',
    ];
}

/** Catálogos técnicos filtrados por categoría. */
function additional_equipment_technical_catalogs(): array
{
    $os=[''=>'Seleccione el sistema operativo','Windows'=>'Windows','Linux'=>'Linux','macOS'=>'macOS','ChromeOS'=>'ChromeOS','Sin sistema operativo'=>'Sin sistema operativo','No identificado'=>'No identificado'];
    $osVersion=[''=>'Seleccione la versión','Windows 7'=>'Windows 7','Windows 10'=>'Windows 10','Windows 11'=>'Windows 11','Windows Server 2016'=>'Windows Server 2016','Windows Server 2019'=>'Windows Server 2019','Windows Server 2022'=>'Windows Server 2022','Ubuntu 20.04'=>'Ubuntu 20.04','Ubuntu 22.04'=>'Ubuntu 22.04','Ubuntu 24.04'=>'Ubuntu 24.04','Debian 11'=>'Debian 11','Debian 12'=>'Debian 12','Otra'=>'Otra','No identificada'=>'No identificada'];
    $processor=[''=>'Seleccione el procesador','Intel Celeron'=>'Intel Celeron','Intel Pentium'=>'Intel Pentium','Intel Core i3'=>'Intel Core i3','Intel Core i5'=>'Intel Core i5','Intel Core i7'=>'Intel Core i7','Intel Core i9'=>'Intel Core i9','Intel Xeon'=>'Intel Xeon','AMD Athlon'=>'AMD Athlon','AMD Ryzen 3'=>'AMD Ryzen 3','AMD Ryzen 5'=>'AMD Ryzen 5','AMD Ryzen 7'=>'AMD Ryzen 7','AMD Ryzen 9'=>'AMD Ryzen 9','AMD EPYC'=>'AMD EPYC','Apple M1'=>'Apple M1','Apple M2'=>'Apple M2','Apple M3'=>'Apple M3','Apple M4'=>'Apple M4','Otro'=>'Otro','No identificado'=>'No identificado'];
    $ram=[''=>'Seleccione la memoria RAM','2 GB'=>'2 GB','4 GB'=>'4 GB','8 GB'=>'8 GB','12 GB'=>'12 GB','16 GB'=>'16 GB','24 GB'=>'24 GB','32 GB'=>'32 GB','64 GB'=>'64 GB','128 GB'=>'128 GB','256 GB'=>'256 GB','Otra'=>'Otra','No identificada'=>'No identificada'];
    $screen=[''=>'Seleccione el tamaño','14 pulgadas'=>'14 pulgadas','15.6 pulgadas'=>'15,6 pulgadas','17 pulgadas'=>'17 pulgadas','19 pulgadas'=>'19 pulgadas','21.5 pulgadas'=>'21,5 pulgadas','22 pulgadas'=>'22 pulgadas','23.8 pulgadas'=>'23,8 pulgadas','24 pulgadas'=>'24 pulgadas','27 pulgadas'=>'27 pulgadas','Otra'=>'Otra','No identificado'=>'No identificado'];
    $connection=[''=>'Seleccione la conexión','USB'=>'USB','USB y red'=>'USB y red','Ethernet'=>'Ethernet','Wi-Fi'=>'Wi-Fi','HDMI'=>'HDMI','DisplayPort'=>'DisplayPort','VGA'=>'VGA','HDMI y DisplayPort'=>'HDMI y DisplayPort','Otra'=>'Otra','No identificada'=>'No identificada'];
    $common=['os_name'=>$os,'os_version'=>$osVersion,'processor'=>$processor,'memory'=>$ram,'screen_size'=>$screen,'connection_type'=>$connection];
    return [
        'cpu'=>$common+['equipment_type'=>[''=>'Seleccione el tipo','Torre'=>'Torre','Escritorio SFF'=>'Escritorio SFF','Mini PC'=>'Mini PC','Workstation'=>'Workstation','No identificado'=>'No identificado']],
        'portatil'=>$common+['equipment_type'=>[''=>'Seleccione el tipo','Portátil estándar'=>'Portátil estándar','Ultrabook'=>'Ultrabook','Dos en uno'=>'Dos en uno','Workstation móvil'=>'Workstation móvil','No identificado'=>'No identificado']],
        'pc_todo_en_uno'=>$common+['equipment_type'=>[''=>'Seleccione el tipo','Todo en Uno'=>'Todo en Uno','Todo en Uno táctil'=>'Todo en Uno táctil','No identificado'=>'No identificado']],
        'monitor'=>['equipment_type'=>[''=>'Seleccione el tipo','LED'=>'LED','LCD'=>'LCD','OLED'=>'OLED','Curvo'=>'Curvo','Táctil'=>'Táctil','No identificado'=>'No identificado'],'screen_size'=>$screen,'connection_type'=>$connection],
        'impresora'=>['equipment_type'=>[''=>'Seleccione el tipo','Láser monocromática'=>'Láser monocromática','Láser color'=>'Láser color','Inyección de tinta'=>'Inyección de tinta','Térmica'=>'Térmica','Multifuncional'=>'Multifuncional','Matricial'=>'Matricial','No identificado'=>'No identificado'],'print_technology'=>[''=>'Seleccione la tecnología','Láser'=>'Láser','Inyección de tinta'=>'Inyección de tinta','Térmica'=>'Térmica','Matricial'=>'Matricial','Otra'=>'Otra','No identificada'=>'No identificada'],'connection_type'=>$connection],
        'escaner'=>['equipment_type'=>[''=>'Seleccione el tipo','Cama plana'=>'Cama plana','Alimentador automático'=>'Alimentador automático','Documental'=>'Documental','Portátil'=>'Portátil','Código de barras'=>'Código de barras','No identificado'=>'No identificado'],'connection_type'=>$connection],
        'ups'=>['equipment_type'=>[''=>'Seleccione el tipo o capacidad','Standby'=>'Standby','Interactiva'=>'Interactiva','Online doble conversión'=>'Online doble conversión','500 VA'=>'500 VA','750 VA'=>'750 VA','1000 VA'=>'1000 VA','1500 VA'=>'1500 VA','2000 VA'=>'2000 VA','3000 VA'=>'3000 VA','Otra'=>'Otra','No identificada'=>'No identificada']],
        'otro'=>['equipment_type'=>[''=>'Seleccione el tipo','Periférico'=>'Periférico','Comunicaciones'=>'Comunicaciones','Energía'=>'Energía','Almacenamiento'=>'Almacenamiento','Otro'=>'Otro','No identificado'=>'No identificado']],
    ];
}

/** Etiqueta legible del método usado para asociar un activo a una sede. */
function association_method_label(?string $method): string
{
    return match ((string)$method) {
        'hostname' => 'Hostname',
        'usuario' => 'Usuario GLPI',
        'location' => 'Localización GLPI',
        'fallback_distrital' => 'Regla AB → Distrital',
        'fallback_delegacion' => 'Regla RM/RA → Delegación',
        'warehouse' => 'Inventario de Almacén',
        'manual' => 'Asignación manual',
        default => 'Sin asociación',
    };
}

/** Clase visual para la confianza de la asociación territorial. */
function association_confidence_badge(?string $confidence): string
{
    $value = (string)$confidence;
    $class = match ($value) {
        'alta' => 'badge-success',
        'media' => 'badge-info',
        'baja' => 'badge-warning',
        default => 'badge-danger',
    };
    $label = match ($value) {
        'alta' => 'Confianza alta',
        'media' => 'Confianza media',
        'baja' => 'Confianza baja',
        default => 'Sin asignar',
    };
    return '<span class="badge ' . $class . '">' . e($label) . '</span>';
}

/**
 * Panel reutilizable para vincular un celular y enviar lecturas de QR/códigos
 * de barras a los campos de serial o Placa RNEC del formulario abierto.
 */
function mobile_scan_connection_panel(int $campaignId = 0, int $sedeId = 0): string
{
    if (!AppSettings::mobileCaptureEnabled()) {
        if (Auth::is('admin_gi') || Auth::is('superadmin')) {
            return '<div class="note note-info mobile-capture-disabled"><strong>Captura móvil deshabilitada.</strong> Puede habilitarla desde Configuración móvil y PWA.</div>';
        }
        return '';
    }
    $sessionMinutes = AppSettings::mobileSessionMinutes();
    return '<section class="mobile-scan-bridge" data-mobile-scan-bridge'
        . ' data-start-url="' . e(route_url('mobile_scan_start')) . '"'
        . ' data-poll-url="' . e(route_url('mobile_scan_poll')) . '"'
        . ' data-ack-url="' . e(route_url('mobile_scan_ack')) . '"'
        . ' data-renew-url="' . e(route_url('mobile_scan_renew')) . '"'
        . ' data-stop-url="' . e(route_url('mobile_scan_stop')) . '"'
        . ' data-campaign-id="' . $campaignId . '" data-sede-id="' . $sedeId . '" data-session-minutes="' . $sessionMinutes . '">'
        . '<div class="mobile-scan-bridge-intro"><div class="mobile-scan-bridge-icon" aria-hidden="true">📱</div>'
        . '<div><strong>Capturar con el celular</strong><span>Conecte temporalmente un celular para leer códigos QR o códigos de barras y enviar el serial o la Placa RNEC a este formulario.</span></div>'
        . '<button type="button" class="btn btn-outline-primary" data-mobile-scan-start>Conectar celular</button></div>'
        . '<div class="mobile-scan-session" data-mobile-scan-session hidden>'
        . '<div class="mobile-scan-qr"><img data-mobile-scan-qr alt="Código QR para abrir el lector en el celular"></div>'
        . '<div class="mobile-scan-session-copy"><div class="kicker">Conexión temporal</div><h4>Abra el lector en el celular</h4>'
        . '<p>Escanee este código con la cámara del celular o comparta el enlace. Verifique que el código de conexión coincida en ambos dispositivos.</p>'
        . '<div class="mobile-scan-pairing-code"><span>Código</span><strong data-mobile-scan-code>------</strong></div>'
        . '<a class="mobile-scan-link" data-mobile-scan-link href="#" target="_blank" rel="noopener">Abrir enlace del lector</a>'
        . '<div class="mobile-scan-actions"><button type="button" class="btn btn-sm btn-secondary" data-mobile-scan-copy>Copiar enlace</button>'
        . '<button type="button" class="btn btn-sm btn-secondary" data-mobile-scan-share hidden>Compartir</button>'
        . '<a class="btn btn-sm btn-success" data-mobile-scan-whatsapp href="#" target="_blank" rel="noopener">Enviar por WhatsApp</a>'
        . '<button type="button" class="btn btn-sm btn-secondary" data-mobile-scan-renew>Renovar ' . $sessionMinutes . ' minutos</button>'
        . '<button type="button" class="btn btn-sm btn-outline-danger" data-mobile-scan-stop>Desconectar</button></div>'
        . '<div class="mobile-scan-status" data-mobile-scan-status role="status" aria-live="polite">Esperando conexión del celular…</div>'
        . '<small>La conexión vence en aproximadamente ' . $sessionMinutes . ' minutos. Tiempo restante: <b data-mobile-scan-countdown>' . sprintf('%02d:00', $sessionMinutes) . '</b>. El celular recibe confirmación cuando el dato queda aplicado en el formulario.</small>'
        . '</div></div></section>';
}
