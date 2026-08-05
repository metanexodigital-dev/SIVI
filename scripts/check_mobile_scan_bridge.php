<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_mobile_scan_bridge.php
 * Propósito: Verifica automáticamente que la funcionalidad «mobile scan bridge» esté presente y sea coherente antes o después del despliegue.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$errors = [];
$required = [
    'src/MobileScanBridge.php',
    'public/assets/mobile-scanner.js',
    'public/assets/mobile-scanner.css',
];
foreach ($required as $file) {
    if (!is_file($root.'/'.$file)) $errors[] = 'Falta '.$file;
}
$index = (string)@file_get_contents($root.'/public/index.php');
$schema = (string)@file_get_contents($root.'/database/schema.sql');
$helpers = (string)@file_get_contents($root.'/src/helpers.php');
$docker = (string)@file_get_contents($root.'/Dockerfile');
foreach (['mobile_scan_start','mobile_scan_poll','mobile_scan_ack','mobile_scan_renew','mobile_scan_stop','mobile_scan_qr','mobile_scanner','mobile_scan_submit','mobile_scan_status','mobile_scan_decode_image'] as $route) {
    if (!str_contains($index, "'{$route}'") && !str_contains($index, "=== '{$route}'")) $errors[] = 'Falta ruta '.$route;
}
if (!str_contains($schema, 'CREATE TABLE IF NOT EXISTS mobile_scan_sessions')) $errors[] = 'Falta tabla mobile_scan_sessions';
if (!str_contains($helpers, 'function mobile_scan_connection_panel')) $errors[] = 'Falta panel de conexión móvil';
if (!str_contains($docker, 'qrencode')) $errors[] = 'Dockerfile no instala qrencode';
if (!str_contains($docker, 'zbar-tools')) $errors[] = 'Dockerfile no instala zbar-tools';
if (!str_contains($docker, 'tesseract-ocr')) $errors[] = 'Dockerfile no instala tesseract-ocr';
if (!str_contains($index, 'data-scanner-photo-input')) $errors[] = 'Falta captura fotográfica para iPhone';
if (!str_contains($index, 'data-scanner-gallery-input')) $errors[] = 'Falta selección desde galería';
if (!str_contains((string)@file_get_contents($root.'/src/MobileScanBridge.php'), 'decodeUploadedImage')) $errors[] = 'Falta decodificación de fotografía';
if (!str_contains($index, 'data-mobile-scan-target="serial_number"')) $errors[] = 'Falta destino móvil para serial';
if (!str_contains($index, 'data-mobile-scan-target="placa_rnec"')) $errors[] = 'Falta destino móvil para placa';

$result = ['ok'=>$errors===[],'errors'=>$errors];
echo json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
exit($errors===[]?0:1);
