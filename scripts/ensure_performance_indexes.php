<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/ensure_performance_indexes.php
 * Propósito: Crea índices de rendimiento idempotentes sin modificar datos.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$definitions = [
    [
        'table'=>'equipment',
        'name'=>'idx_eq_serial_active',
        'columns'=>['serial_number','active'],
    ],
    [
        'table'=>'equipment',
        'name'=>'idx_eq_placa_active',
        'columns'=>['placa_rnec','active'],
    ],
    [
        'table'=>'additional_equipment',
        'name'=>'idx_ae_serial_review',
        'columns'=>['serial_number','review_status'],
    ],
    [
        'table'=>'additional_equipment',
        'name'=>'idx_ae_placa_review',
        'columns'=>['placa_rnec','review_status'],
    ],
];

$databaseRow = Database::fetchOne('SELECT DATABASE() database_name');
$databaseName = (string)($databaseRow['database_name'] ?? '');
if ($databaseName === '') {
    fwrite(STDERR, "No fue posible identificar la base de datos activa.\n");
    exit(2);
}

$results = [];
$ok = true;

foreach ($definitions as $definition) {
    $exists = Database::fetchOne(
        'SELECT 1 found FROM information_schema.statistics '
        . 'WHERE table_schema=? AND table_name=? AND index_name=? LIMIT 1',
        [$databaseName,$definition['table'],$definition['name']]
    );

    if ($exists) {
        $results[] = [
            'table'=>$definition['table'],
            'index'=>$definition['name'],
            'status'=>'existing',
        ];
        continue;
    }

    $table = '`' . str_replace('`', '``', $definition['table']) . '`';
    $index = '`' . str_replace('`', '``', $definition['name']) . '`';
    $columns = implode(',', array_map(
        static fn(string $column): string => '`'
            . str_replace('`', '``', $column)
            . '`',
        $definition['columns']
    ));

    $sql = "ALTER TABLE {$table} ADD INDEX {$index} ({$columns})";
    try {
        Database::execute($sql . ', ALGORITHM=INPLACE, LOCK=NONE');
        $status = 'created_online';
    } catch (Throwable $onlineError) {
        try {
            Database::execute($sql);
            $status = 'created';
        } catch (Throwable $fallbackError) {
            $ok = false;
            $status = 'error';
            $results[] = [
                'table'=>$definition['table'],
                'index'=>$definition['name'],
                'status'=>$status,
                'message'=>$fallbackError->getMessage(),
            ];
            continue;
        }
    }

    $results[] = [
        'table'=>$definition['table'],
        'index'=>$definition['name'],
        'status'=>$status,
    ];
}

echo json_encode([
    'ok'=>$ok,
    'database'=>$databaseName,
    'data_changed'=>false,
    'indexes'=>$results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    . PHP_EOL;

exit($ok ? 0 : 2);
