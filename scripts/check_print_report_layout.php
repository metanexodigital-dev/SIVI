<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_print_report_layout.php
 * Propósito: Verifica automáticamente que la funcionalidad «print report layout» esté presente y sea coherente antes o después del despliegue.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$version = trim((string)@file_get_contents($root . '/VERSION'));
$index = (string)@file_get_contents($root . '/public/index.php');
$css = (string)@file_get_contents($root . '/public/assets/app.css');
$views = (string)@file_get_contents($root . '/src/views.php');

$checks = [
    'version_0_0_0_38' => $version === '1.0.0.0',
    'letter_page' => str_contains($css, '@page{size:Letter portrait'),
    'shell_reset' => str_contains($css, '.app-shell,.main,.content{display:block!important') && str_contains($css, 'grid-template-columns:none!important'),
    'navigation_hidden' => str_contains($css, '.skip-link,.sidebar,.topbar,.no-print'),
    'certificate_full_width' => str_contains($css, '.print-certificate,.closure-certificate{display:block!important;width:100%!important'),
    'readable_table' => str_contains($css, '.certificate-details{display:table!important;width:100%!important;table-layout:fixed!important'),
    'verification_wrap' => str_contains($css, '.certificate-verification code') && str_contains($css, 'word-break:break-all!important'),
    'certificate_structure' => str_contains($index, 'closure-certificate') && str_contains($index, 'certificate-header') && str_contains($index, 'certificate-details') && str_contains($index, 'certificate-declaration'),
    'print_action_preserved' => str_contains($index, 'data-print-page'),
    'page_data_attribute' => str_contains($views, 'data-page="'),
];

$ok = !in_array(false, $checks, true);
echo json_encode([
    'ok' => $ok,
    'version' => $version,
    'check' => 'print_report_layout_1.0.0.0',
    'checks' => $checks,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
