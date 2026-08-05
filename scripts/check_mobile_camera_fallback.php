<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_mobile_camera_fallback.php
 * Propósito: Verifica automáticamente que la funcionalidad «mobile camera fallback» esté presente y sea coherente antes o después del despliegue.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$version = trim((string)@file_get_contents($root.'/VERSION'));
$index = (string)@file_get_contents($root.'/public/index.php');
$js = (string)@file_get_contents($root.'/public/assets/mobile-scanner.js');
$css = (string)@file_get_contents($root.'/public/assets/mobile-scanner.css');
$bridge = (string)@file_get_contents($root.'/src/MobileScanBridge.php');
$docker = (string)@file_get_contents($root.'/Dockerfile');

$checks = [
    'version_0_0_0_37' => $version === '1.0.0.0',
    'zbar_installed' => str_contains($docker, 'zbar-tools'),
    'public_image_route' => str_contains($index, "mobile_scan_decode_image"),
    'image_endpoint' => str_contains($index, 'function mobile_scan_decode_image_page'),
    'image_url_bound' => str_contains($index, 'data-image-url'),
    'photo_capture_input' => str_contains($index, 'data-scanner-photo-input') && str_contains($index, 'capture="environment"'),
    'photo_action' => str_contains($index, 'data-scanner-photo'),
    'ios_help' => str_contains($index, 'Compatibilidad con iPhone') || str_contains($js, 'Compatibilidad con iPhone'),
    'server_decoder' => str_contains($bridge, 'decodeUploadedImage') && str_contains($bridge, "['zbarimg','--quiet',\$tmp]"),
    'upload_limits' => str_contains($bridge, '12 * 1024 * 1024') && str_contains($bridge, "image/jpeg"),
    'synchronous_ios_fallback' => str_contains($js, "if(!detector)") && str_contains($js, "openImageInput(photoInput,'camera')"),
    'photo_upload' => str_contains($js, "data.append('image',file") && str_contains($js, 'decodeImage'),
    'browser_guidance' => str_contains($js, 'isIOS') && str_contains($js, 'isInApp') && str_contains($js, 'isWhatsApp'),
    'fallback_styles' => str_contains($css, '.scanner-browser-help'),
];
$ok = !in_array(false, $checks, true);
echo json_encode(['ok'=>$ok,'version'=>$version,'check'=>'mobile_camera_fallback_1.0.0.0','checks'=>$checks], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
exit($ok?0:1);
