<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_formador_flow.php
 * Propósito: Verifica automáticamente que la funcionalidad «formador flow» esté presente y sea coherente antes o después del despliegue.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$version = trim((string)@file_get_contents($root . '/VERSION'));
$views = (string)@file_get_contents($root . '/src/views.php');
$index = (string)@file_get_contents($root . '/public/index.php');

$checks = [
    'version_0_0_0_31' => $version === '1.0.0.0',
    'formador_own_menu_branch' => str_contains($views, "elseif ((string)\$user['role'] === 'formador')"),
    'formador_operational_menu' => str_contains($views, "['equipos','Validar inventario'")
        && str_contains($views, "['adicionales','Registrar equipos adicionales'")
        && str_contains($views, "['novedades','Novedades'")
        && str_contains($views, "['notificaciones','Notificaciones'")
        && str_contains($views, "['correcciones','Correcciones'"),
    'formador_sedes_menu' => str_contains($views, "['sedes','Sedes'"),
    'formador_campaign_read_only_menu' => str_contains($views, "['campanias','Campañas · solo lectura'"),
    'formador_followup_menu' => str_contains($views, "['seguimiento','Seguimiento'"),
    'formador_quality_menu' => str_contains($views, "['calidad','Control de calidad'"),
    'formador_transfers_menu' => str_contains($views, "['traslados','Traslados'"),
    'formador_reopenings_menu' => str_contains($views, "['reaperturas','Reaperturas'"),
    'formador_inconsistencies_menu' => str_contains($views, "['inconsistencias','Inconsistencias'"),
    'formador_reports_menu' => str_contains($views, "['informes','Informes','▤']"),
    'formador_allowed_routes' => str_contains($views, "'formador' => ['equipos','adicionales','novedades','notificaciones','correcciones','sedes','campanias','seguimiento','calidad','traslados','reaperturas','inconsistencias','informes']"),
    'formador_route_guard_extended' => str_contains($index, "'sedes','sede_editar'")
        && str_contains($index, "'seguimiento','seguimiento_accion'")
        && str_contains($index, "'traslados','traslado_accion'")
        && str_contains($index, "'reaperturas','reapertura_accion'")
        && str_contains($index, "'informes','informe_exportar','informe_imprimir'"),
    'campaign_read_only_notice' => str_contains($index, 'Modo solo lectura:')
        && str_contains($index, "\$readOnly = Auth::is('formador')"),
    'campaign_create_admin_only' => str_contains($index, 'function campaign_create_page(): void')
        && str_contains($index, "Auth::requireRole(['admin_gi','superadmin']);"),
    'campaign_manage_admin_only' => str_contains($index, "return in_array((string)(Auth::user()['role'] ?? ''), ['admin_gi','superadmin'], true);"),
    'campaign_admin_actions_protected' => str_contains($index, "Auth::requireRole(['admin_gi','superadmin']);")
        && str_contains($index, "if(\$action==='submit_sede')"),
    'department_scope_preserved' => str_contains($index, 'function accessible_campaign_rows(): array')
        && str_contains($index, "\$role === 'formador'")
        && str_contains($index, 's.cod_dd IN'),
];
$errors = [];
foreach ($checks as $name => $ok) {
    if (!$ok) $errors[] = 'No se cumple el control: ' . $name;
}
$ok = $errors === [];
echo json_encode([
    'ok' => $ok,
    'version' => $version,
    'check' => 'formador_extended_territorial_menu_1.0.0.0',
    'checks' => $checks,
    'errors' => $errors,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 2);
