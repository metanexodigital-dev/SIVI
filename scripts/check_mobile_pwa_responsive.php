<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_mobile_pwa_responsive.php
 * Propósito: Verifica automáticamente que la funcionalidad «mobile pwa responsive» esté presente y sea coherente antes o después del despliegue.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$version = trim((string)@file_get_contents($root.'/VERSION'));
$settings = (string)@file_get_contents($root.'/src/AppSettings.php');
$index = (string)@file_get_contents($root.'/public/index.php');
$views = (string)@file_get_contents($root.'/src/views.php');
$helpers = (string)@file_get_contents($root.'/src/helpers.php');
$appJs = (string)@file_get_contents($root.'/public/assets/app.js');
$scannerJs = (string)@file_get_contents($root.'/public/assets/mobile-scanner.js');
$css = (string)@file_get_contents($root.'/public/assets/app.css');
$printJs = (string)@file_get_contents($root.'/public/assets/report-print.js');
$sw = (string)@file_get_contents($root.'/public/sw.js');
$manifest = json_decode((string)@file_get_contents($root.'/public/manifest.webmanifest'), true);

$checks = [
    'version' => $version === '1.0.0.0',
    'settings_class' => str_contains($settings, 'final class AppSettings')
        && str_contains($settings, 'mobile_capture.enabled')
        && str_contains($settings, 'pwa.install_enabled'),
    'admin_configuration_route' => str_contains($index, "case 'configuracion': configuration_page();")
        && str_contains($index, "Auth::requireRole(['admin_gi','superadmin']);")
        && str_contains($views, "['configuracion','Configuración','⚙']"),
    'capture_global_guard' => str_contains($index, 'La captura móvil está deshabilitada por el administrador.')
        && str_contains($helpers, 'AppSettings::mobileCaptureEnabled()'),
    'capture_methods_configurable' => str_contains($settings, 'liveCameraEnabled')
        && str_contains($settings, 'imageUploadEnabled')
        && str_contains($settings, 'manualEntryEnabled')
        && str_contains($scannerJs, 'root.dataset.liveEnabled'),
    'report_close_fallback' => str_contains($index, 'data-close-url')
        && str_contains($printJs, 'window.location.assign(fallback)')
        && str_contains($printJs, 'window.opener'),
    'responsive_navigation' => str_contains($css, '@media(max-width:820px)')
        && str_contains($css, '.sidebar-backdrop')
        && str_contains($css, '100dvh')
        && str_contains($appJs, 'mobileNavigation'),
    'pwa_manifest' => is_array($manifest)
        && ($manifest['version'] ?? '') === '1.0.0.0'
        && ($manifest['display'] ?? '') === 'standalone'
        && ($manifest['scope'] ?? '') === '/'
        && !empty($manifest['shortcuts']),
    'pwa_install_ui' => str_contains($views, 'data-pwa-install')
        && str_contains($views, 'Añadir a pantalla de inicio')
        && str_contains($appJs, 'beforeinstallprompt')
        && str_contains($appJs, 'appinstalled'),
    'offline_without_inventory_cache' => is_file($root.'/public/offline.html')
        && str_contains($sw, "request.mode === 'navigate'")
        && str_contains($sw, "fetch(request, {cache: 'no-store'})")
        && str_contains($sw, './offline.html'),
];

$errors = [];
foreach ($checks as $name => $ok) if (!$ok) $errors[] = 'No se cumple: '.$name;
$ok = $errors === [];
echo json_encode([
    'ok'=>$ok,
    'version'=>$version,
    'check'=>'mobile_pwa_responsive_1.0.0.0',
    'checks'=>$checks,
    'errors'=>$errors,
], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:2);
