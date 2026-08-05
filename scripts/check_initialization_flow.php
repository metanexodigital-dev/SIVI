<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_initialization_flow.php
 * Propósito: Verifica automáticamente que la funcionalidad «initialization flow» esté presente y sea coherente antes o después del despliegue.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'state' => $root . '/src/InitializationState.php',
    'controller' => $root . '/public/index.php',
    'views' => $root . '/src/views.php',
    'directory_importer' => $root . '/src/DirectoryImporter.php',
    'glpi_importer' => $root . '/src/Importer.php',
    'warehouse_importer' => $root . '/src/WarehouseImporter.php',
];
$content = [];
foreach ($files as $key => $path) {
    $content[$key] = is_file($path) ? (string)file_get_contents($path) : '';
}

$checks = [
    'initialization_state_file' => $content['state'] !== '',
    'three_stage_markers' => str_contains($content['state'], 'markSedesComplete')
        && str_contains($content['state'], 'markGlpiComplete')
        && str_contains($content['state'], 'markWarehouseComplete'),
    'sequence_assertions' => str_contains($content['state'], 'assertSedesComplete')
        && str_contains($content['state'], 'assertGlpiComplete'),
    'operational_gate' => str_contains($content['controller'], 'InitializationState::operationalPages()')
        && str_contains($content['controller'], "redirect('importar')"),
    'stage_1_sedes' => str_contains($content['controller'], "'sedes' => 'sedes_file'")
        && str_contains($content['controller'], 'DirectoryImporter::import'),
    'stage_2_glpi_computers' => str_contains($content['controller'], "'glpi_computers' => 'glpi_computers_file'")
        && str_contains($content['controller'], "importAssetReport(\$target, \$originalName, \$userId, 'computador', false)"),
    'stage_3_warehouse' => str_contains($content['controller'], "'warehouse' => 'warehouse_file'")
        && str_contains($content['controller'], 'WarehouseImporter::import'),
    'navigation_restricted' => str_contains($content['views'], "['dashboard','importar','diagnostico']")
        && str_contains($content['views'], "['dashboard']"),
    'actual_sede_headers' => str_contains($content['directory_importer'], 'db_Identificador_Sede')
        && str_contains($content['directory_importer'], 'db_Nombre_Sede')
        && str_contains($content['directory_importer'], 'db_Direccion'),
    'glpi_without_early_warehouse_reconciliation' => str_contains($content['glpi_importer'], 'bool $reconcileWarehouse = true')
        && str_contains($content['glpi_importer'], 'resetComputerReconciliation'),
    'glpi_physical_computer_filter' => str_contains($content['glpi_importer'], 'isComputerInventoryRow')
        && str_contains($content['glpi_importer'], 'skipped_non_computer'),
    'warehouse_reconciliation_reset' => str_contains($content['warehouse_importer'], 'resetComputerReconciliation'),
];

$ok = !in_array(false, $checks, true);
echo json_encode([
    'ok' => $ok,
    'version' => trim((string)@file_get_contents($root . '/VERSION')),
    'check' => 'mandatory_three_stage_initialization',
    'checks' => $checks,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 2);
