<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_performance_optimization.php
 * Propósito: Verifica las optimizaciones de validación y procesamiento.
 */
declare(strict_types=1);

$root=dirname(__DIR__);
$javascript=(string)@file_get_contents($root.'/public/assets/app.js');
$index=(string)@file_get_contents($root.'/public/index.php');
$integrity=(string)@file_get_contents($root.'/src/AdditionalEquipmentIntegrity.php');
$phpIni=(string)@file_get_contents($root.'/php.ini');
$schema=(string)@file_get_contents($root.'/database/schema.sql');
$indexScript=(string)@file_get_contents($root.'/scripts/ensure_performance_indexes.php');
$version=trim((string)@file_get_contents($root.'/VERSION'));
$release=(string)@file_get_contents($root.'/RELEASE.json');

$checks=[
    'identity_timeout_3s'=>str_contains($javascript,'identityTimeoutMs=3000'),
    'identity_cache_60s'=>str_contains($javascript,'identityCacheTtlMs=60000')
        && str_contains($javascript,'identityResultCache=new Map()'),
    'catalog_cache_5m'=>str_contains($javascript,'catalogCacheTtlMs=300000')
        && str_contains($javascript,'catalogResultCache=new Map()'),
    'cache_bounded'=>str_contains($javascript,'function boundedCacheSet'),
    'serial_minimum'=>str_contains($javascript,'length<4'),
    'debounce_800ms'=>str_contains($javascript,'setTimeout(checkIdentity,800)'),
    'fast_before_full'=>str_contains($integrity,'$fastResult = self::checkFast')
        && str_contains($integrity,'if (!empty($fastResult[\'has_conflicts\']))'),
    'separate_equipment_queries'=>str_contains(
        $integrity,
        'AND e.serial_number IN ('
    ) && str_contains(
        $integrity,
        'AND e.placa_rnec IN ('
    ),
    'separate_additional_queries'=>str_contains(
        $integrity,
        'AND ae.serial_number IN ('
    ) && str_contains(
        $integrity,
        'AND ae.placa_rnec IN ('
    ),
    'result_limit'=>substr_count($integrity,'LIMIT 10')>=4,
    'endpoint_timing'=>str_contains($index,'X-SIVI-Validation-Time-Ms')
        && str_contains($index,'SIVI slow identity validation'),
    'performance_index_script'=>str_contains(
        $indexScript,
        'information_schema.statistics'
    ) && str_contains(
        $indexScript,
        "'data_changed'=>false"
    ),
    'schema_indexes'=>str_contains($schema,'idx_eq_serial_active')
        && str_contains($schema,'idx_eq_placa_active')
        && str_contains($schema,'idx_ae_serial_review')
        && str_contains($schema,'idx_ae_placa_review'),
    'opcache_optimized'=>str_contains($phpIni,'opcache.validate_timestamps=0')
        && str_contains($phpIni,'opcache.memory_consumption=192')
        && str_contains($phpIni,'opcache.max_accelerated_files=20000'),
    'realpath_cache'=>str_contains($phpIni,'realpath_cache_size=8192K'),
    'no_automatic_migrations'=>!str_contains(
        (string)@file_get_contents($root.'/docker/entrypoint.sh'),
        'scripts/migrate.php'
    ),
];

$ok=!in_array(false,$checks,true);

echo json_encode([
    'ok'=>$ok,
    'check'=>'performance_optimization',
    'checks'=>$checks,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    . PHP_EOL;

exit($ok?0:2);
