<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_serial_integrity.php
 * Propósito: Verifica automáticamente que la funcionalidad «serial integrity» esté presente y sea coherente antes o después del despliegue.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

function is_computer_category(string $category): bool
{
    return in_array($category, ['cpu','portatil','pc_todo_en_uno'], true);
}

final class Database
{
    /** @var array<int,array<string,mixed>> */
    public static array $rows = [
        ['id'=>1,'serial_number'=>'ABC-123','serial_source_original'=>null,'serial_verified_at'=>null,'active'=>1],
        ['id'=>2,'serial_number'=>'abc 123','serial_source_original'=>null,'serial_verified_at'=>null,'active'=>1],
        ['id'=>3,'serial_number'=>'UNICO-9','serial_source_original'=>null,'serial_verified_at'=>null,'active'=>1],
    ];
    /** @var array<int,array{sql:string,params:array<int,mixed>}> */
    public static array $updates = [];

    public static function fetchAll(string $sql, array $params = []): array
    {
        if (str_contains($sql, 'FROM equipment')) return self::$rows;
        return [];
    }

    public static function execute(string $sql, array $params = []): void
    {
        self::$updates[] = ['sql'=>$sql,'params'=>$params];
    }
}

require_once dirname(__DIR__) . '/src/SerialIntegrity.php';

$result = SerialIntegrity::clearActiveDuplicates();
$matches = SerialIntegrity::activeMatches('ABC123', 1);
$root = dirname(__DIR__);
$schema = (string)@file_get_contents($root . '/database/schema.sql');
$importer = (string)@file_get_contents($root . '/src/Importer.php');
$warehouse = (string)@file_get_contents($root . '/src/WarehouseImporter.php');
$quality = (string)@file_get_contents($root . '/src/ImportQuality.php');
$controller = (string)@file_get_contents($root . '/public/index.php');

$checks = [
    'normalized_duplicate_group_detected' => $result['duplicate_groups'] === 1,
    'all_duplicate_rows_cleared' => $result['cleared_equipment'] === 2 && count(Database::$updates) === 2,
    'unique_serial_not_cleared' => !in_array(3, array_map(static fn(array $u): int => (int)($u['params'][0] ?? 0), Database::$updates), true),
    'duplicate_lookup_excludes_current' => count($matches) === 1 && (int)$matches[0]['id'] === 2,
    'schema_has_traceability' => str_contains($schema, 'serial_source_original') && str_contains($schema, 'serial_review_required') && str_contains($schema, 'serial_verified_at'),
    'glpi_import_clears_duplicates' => str_contains($importer, 'SerialIntegrity::clearActiveDuplicates()') && str_contains($importer, '$serialForInventory = $serialIsDuplicate ?'),
    'warehouse_import_clears_duplicates' => str_contains($warehouse, 'SerialIntegrity::clearActiveDuplicates()'),
    'duplicates_are_warning_not_blocker' => str_contains($quality, "'advertencia','GLPI_DUPLICATE_SERIAL'") && str_contains($quality, "'advertencia','WAREHOUSE_DUPLICATE_SERIAL'"),
    'user_must_enter_unique_serial' => str_contains($controller, 'SerialIntegrity::activeMatches($serialReported, $id)') && str_contains($controller, 'serial_verified_at=NOW()'),
];
$ok = !in_array(false, $checks, true);
echo json_encode([
    'ok'=>$ok,
    'version'=>trim((string)@file_get_contents($root . '/VERSION')),
    'check'=>'active_inventory_serial_integrity',
    'checks'=>$checks,
    'sample_result'=>$result,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 2);
