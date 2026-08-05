<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_mobile_scan_robust.php
 * Propósito: Verifica automáticamente que la funcionalidad «mobile scan robust» esté presente y sea coherente antes o después del despliegue.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$version = trim((string)@file_get_contents($root.'/VERSION'));
$index = (string)@file_get_contents($root.'/public/index.php');
$js = (string)@file_get_contents($root.'/public/assets/mobile-scanner.js');
$appJs = (string)@file_get_contents($root.'/public/assets/app.js');
$css = (string)@file_get_contents($root.'/public/assets/mobile-scanner.css');
$bridge = (string)@file_get_contents($root.'/src/MobileScanBridge.php');
$schema = (string)@file_get_contents($root.'/database/schema.sql');
$helpers = (string)@file_get_contents($root.'/src/helpers.php');
$docker = (string)@file_get_contents($root.'/Dockerfile');

$checks = [
    'version_0_0_0_37' => $version === '1.0.0.0',
    'status_ack_renew_routes' => str_contains($index, 'mobile_scan_status') && str_contains($index, 'mobile_scan_ack') && str_contains($index, 'mobile_scan_renew'),
    'computer_acknowledgement' => str_contains($appJs, 'data-mobile-scan-bridge') && str_contains($appJs, 'acknowledge(seq)') && str_contains($helpers, 'data-ack-url'),
    'session_renewal' => str_contains($bridge, 'function renew') && str_contains($helpers, 'data-mobile-scan-renew') && str_contains($appJs, 'function renew()'),
    'ack_schema' => str_contains($schema, 'ack_sequence INT UNSIGNED') && str_contains($schema, 'last_acknowledged_at DATETIME'),
    'idempotent_requests' => str_contains($bridge, 'last_request_id') && str_contains($js, 'request_id'),
    'in_app_detection' => str_contains($js, 'isWhatsApp') && str_contains($js, 'isInApp') && str_contains($index, 'data-scanner-browser-help'),
    'photo_and_gallery' => str_contains($index, 'data-scanner-photo-input') && str_contains($index, 'data-scanner-gallery-input') && str_contains($js, 'decodeImage'),
    'ocr_fallback' => str_contains($docker, 'tesseract-ocr') && str_contains($bridge, "['tesseract',\$tmp,'stdout'") && str_contains($bridge, 'extractOcrCandidates'),
    'candidate_selection' => str_contains($index, 'data-scanner-candidates') && str_contains($js, 'renderCandidates'),
    'guided_capture' => str_contains($index, 'data-scanner-guided') && str_contains($js, 'guidedStage') && str_contains($js, 'Paso 2 de 2'),
    'receipt_confirmation' => str_contains($js, 'confirmado por el computador') && str_contains($appJs, 'Lectura aplicada y confirmada al celular'),
    'reconnection' => str_contains($js, "window.addEventListener('online'") && str_contains($js, 'checkSessionStatus'),
    'torch_support' => str_contains($js, 'capabilities.torch') && str_contains($index, 'data-scanner-torch'),
    'robust_styles' => str_contains($css, '.scanner-connection-dot') && str_contains($css, '.scanner-candidates'),
];

$ok = !in_array(false, $checks, true);
echo json_encode(['ok'=>$ok,'version'=>$version,'check'=>'mobile_scan_robust_1.0.0.0','checks'=>$checks], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
