<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_quality_flow.php
 * Propósito: Verifica automáticamente que la funcionalidad «quality flow» esté presente y sea coherente antes o después del despliegue.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$quality = (string)@file_get_contents($root . '/src/ImportQuality.php');
$controller = (string)@file_get_contents($root . '/public/index.php');
$schema = (string)@file_get_contents($root . '/database/schema.sql');
$views = (string)@file_get_contents($root . '/src/views.php');
$checks = [
    'quality_service' => str_contains($quality, 'final class ImportQuality'),
    'validation_without_import' => str_contains($quality, 'validateFile') && str_contains($controller, "name=\"import_action\" value=\"validate\""),
    'controlled_application' => str_contains($quality, 'applicableValidation') && str_contains($controller, "name=\"import_action\" value=\"apply\""),
    'xlsx_issue_report' => str_contains($quality, 'writeIssueReport') && str_contains($quality, 'XlsxWriter::create'),
    'traffic_light' => str_contains($quality, 'traffic_light') && str_contains($controller, 'quality-banner'),
    'campaign_quality_gate' => str_contains($controller, 'ImportQuality::campaignsAllowed()'),
    'diagnostic_center' => str_contains($controller, 'function diagnostics_page') && str_contains($views, "['diagnostico','Centro de diagnóstico'"),
    'validation_history' => str_contains($schema, 'CREATE TABLE IF NOT EXISTS import_validations'),
    'quality_snapshots' => str_contains($schema, 'CREATE TABLE IF NOT EXISTS data_quality_snapshots'),
];
$ok = !in_array(false, $checks, true);
echo json_encode([
    'ok'=>$ok,
    'version'=>trim((string)@file_get_contents($root . '/VERSION')),
    'check'=>'quality_validation_and_diagnostics',
    'checks'=>$checks,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 2);
