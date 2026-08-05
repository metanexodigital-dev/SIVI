<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_reports_center.php
 * Propósito: Verifica automáticamente que la funcionalidad «reports center» esté presente y sea coherente antes o después del despliegue.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$version = trim((string)@file_get_contents($root.'/VERSION'));
$reports = (string)@file_get_contents($root.'/src/ReportsCenter.php');
$index = (string)@file_get_contents($root.'/public/index.php');
$views = (string)@file_get_contents($root.'/src/views.php');
$schema = (string)@file_get_contents($root.'/database/schema.sql');
$css = (string)@file_get_contents($root.'/public/assets/app.css');
$printJs = (string)@file_get_contents($root.'/public/assets/report-print.js');
$printCss = (string)@file_get_contents($root.'/public/assets/report-print.css');

$checks = [
    'version_0_0_0_39' => $version === '1.0.0.0',
    'reports_class' => str_contains($reports, 'final class ReportsCenter'),
    'twelve_report_types' => substr_count($reports, "'roles' =>") >= 12,
    'territorial_scope' => str_contains($reports, "Scope::sedeCondition"),
    'xlsx_csv_exports' => str_contains($index, "format'=>'xlsx'") && str_contains($index, "format'=>'csv'"),
    'print_pdf_view' => str_contains($index, 'function reports_print_page') && str_contains($printCss, '@page{size:landscape') && str_contains($printJs, 'window.print'),
    'report_routes' => str_contains($index, "case 'informes'") && str_contains($index, "case 'informe_exportar'") && str_contains($index, "case 'informe_imprimir'"),
    'formador_menu' => str_contains($views, "['informes','Informes','▤']"),
    'admin_menu' => str_contains($views, "['informes','Centro de informes','▤']"),
    'export_audit_table' => str_contains($schema, 'CREATE TABLE IF NOT EXISTS report_exports'),
    'export_trace' => str_contains($reports, "audit('export_report'") && str_contains($reports, 'filters_json'),
    'responsive_styles' => str_contains($css, '.report-catalog') && str_contains($css, '.report-filter-grid'),
];
$ok = !in_array(false, $checks, true);
echo json_encode(['ok'=>$ok,'version'=>$version,'check'=>'integral_reports_center_1.0.0.0','checks'=>$checks], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:2);
