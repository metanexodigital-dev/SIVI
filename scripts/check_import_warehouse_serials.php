<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_import_warehouse_serials.php
 * Propósito: Verifica la normalización y prioridad de seriales del inventario de Almacén.
 */
declare(strict_types=1);

$root=dirname(__DIR__);
$quality=(string)@file_get_contents($root.'/src/ImportQuality.php');
$warehouse=(string)@file_get_contents($root.'/src/WarehouseImporter.php');
$directory=(string)@file_get_contents($root.'/src/DirectoryImporter.php');
$entrypoint=(string)@file_get_contents($root.'/docker/entrypoint.sh');
$compose=(string)@file_get_contents($root.'/docker-compose.yml');
$dbCompose=(string)@file_get_contents($root.'/docker-compose-db.yml');
$dbRelease=(string)@file_get_contents($root.'/docker/db/99-sivi-register-release.sh');
$release=(string)@file_get_contents($root.'/RELEASE.json');
$version=trim((string)@file_get_contents($root.'/VERSION'));

$checks=[
    'issue_accepts_mixed'=>str_contains($quality,'string $message,mixed $value'),
    'issue_normalizes_scalar'=>str_contains($quality,'$normalizedValue=(string)$value'),
    'serial_keys_prefixed'=>str_contains($quality,"\$serialRows['serial:'.\$serialKey]"),
    'generic_internal_removed'=>str_contains($quality,'genericSerial($internalSerialKey)'),
    'warehouse_serial_fallback'=>str_contains($quality,"\$warehouseSerialKey!==''?\$warehouseSerialKey"),
    'warehouse_effective_serial'=>str_contains($warehouse,'$effectiveSerialNormalized'),
    'warehouse_zero_invalid'=>str_contains($warehouse,"'00000000'"),
    'directory_returns_catalog_codes'=>str_contains($directory,'private static function upsertCatalogs(array $d): array'),
    'directory_reuses_department_code'=>str_contains($directory,'SELECT code FROM departments WHERE name=? LIMIT 1'),
    'directory_normalizes_codes'=>str_contains($directory,'normalizeTerritorialCode'),
    'release_registration_script'=>is_file($root.'/scripts/register_current_release.php'),
    'entrypoint_registers_release'=>str_contains($entrypoint,'scripts/register_current_release.php'),
    'db_health_engine_ready'=>str_contains($dbCompose,'mysqladmin --defaults-extra-file=$$CNF ping --silent'),
    'db_initial_release_registered'=>str_contains($dbRelease,'app_release_history') && str_contains($dbRelease,'is_current=1'),
    'no_migrations'=>!str_contains($entrypoint,'scripts/migrate.php'),
];

$ok=!in_array(false,$checks,true);
echo json_encode([
    'ok'=>$ok,
    'check'=>'import_fix_1.0.0.0',
    'checks'=>$checks,
],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
exit($ok?0:2);
