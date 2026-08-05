<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_registrador_flow.php
 * Propósito: Verifica automáticamente que la funcionalidad «registrador flow» esté presente y sea coherente antes o después del despliegue.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$version = trim((string)@file_get_contents($root . '/VERSION'));
$views = (string)@file_get_contents($root . '/src/views.php');
$index = (string)@file_get_contents($root . '/public/index.php');
$schema = (string)@file_get_contents($root . '/database/schema.sql');

$checks = [
    'version_0_0_0_31' => $version === '1.0.0.0',
    'registrador_menu_validate_inventory' => str_contains($views, "['equipos','Validar inventario'"),
    'registrador_menu_additional' => str_contains($views, "['adicionales','Registrar equipos adicionales'"),
    'registrador_menu_incidents' => str_contains($views, "['novedades','Novedades'"),
    'registrador_menu_notifications' => str_contains($views, "['notificaciones','Notificaciones'"),
    'registrador_menu_corrections' => str_contains($views, "['correcciones','Correcciones'"),
    'registrador_menu_restricted' => str_contains($views, "'registrador' => ['equipos','adicionales','novedades','notificaciones','correcciones']"),
    'registrador_route_guard' => str_contains($index, '$operationalPages = [') && str_contains($index, "Auth::is('registrador') || Auth::is('formador')") && str_contains($index, 'El perfil Registrador solo puede validar'),
    'site_profile_required_before_equipment' => substr_count($index, 'campaign_site_profile_complete') >= 3,
    'site_profile_form' => str_contains($index, 'Validar información de la sede') && str_contains($index, 'site_identity_confirmation'),
    'site_profile_migration' => str_contains($schema, 'site_confirmation_status') && str_contains($schema, 'site_confirmed_at'),
];
$errors = [];
foreach ($checks as $name => $ok) if (!$ok) $errors[] = 'No se cumple el control: ' . $name;
$ok = $errors === [];
echo json_encode([
    'ok' => $ok,
    'version' => $version,
    'check' => 'registrador_menu_and_site_first_flow_1.0.0.0',
    'checks' => $checks,
    'errors' => $errors,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 2);
