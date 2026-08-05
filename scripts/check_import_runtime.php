<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_import_runtime.php
 * Propósito: Verifica automáticamente que la funcionalidad «import runtime» esté presente y sea coherente antes o después del despliegue.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/bootstrap.php';

$checks = [
    'zip_extension' => class_exists(ZipArchive::class),
    'xmlreader_extension' => class_exists(XMLReader::class),
    'simplexml_extension' => function_exists('simplexml_load_string'),
    'upload_directory' => is_dir(dirname(__DIR__) . '/storage/uploads') && is_writable(dirname(__DIR__) . '/storage/uploads'),
    'log_directory' => is_dir(dirname(__DIR__) . '/storage/logs') && is_writable(dirname(__DIR__) . '/storage/logs'),
    'bundled_sede_master' => is_file(dirname(__DIR__) . '/docs/Sedes-RNEC-MAESTRO.xlsx'),
    'initialization_state_class' => class_exists(InitializationState::class),
    'initialization_three_stage_api' => method_exists(InitializationState::class, 'markSedesComplete')
        && method_exists(InitializationState::class, 'markGlpiComplete')
        && method_exists(InitializationState::class, 'markWarehouseComplete'),
];

try {
    $status = Database::schemaStatus();
    $checks['database_schema'] = $status['ok'];
} catch (Throwable $e) {
    $checks['database_schema'] = false;
    $status = ['error' => $e->getMessage()];
}

$ok = !in_array(false, $checks, true);
echo json_encode([
    'ok' => $ok,
    'version' => AppVersion::package(),
    'check' => 'inventory_import_runtime',
    'checks' => $checks,
    'schema' => $status,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 2);
