<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_clean_database_schema.php
 * Propósito: Evita que el esquema para una base nueva vuelva a agregar columnas
 * que ya están incluidas en la sentencia CREATE TABLE.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$schema = (string)@file_get_contents($root . '/database/schema.sql');

$columns = [
    'ack_sequence',
    'last_request_id',
    'last_acknowledged_at',
    'mobile_last_seen_at',
    'renewed_at',
];

$checks = [];
foreach ($columns as $column) {
    $definedInCreate = preg_match(
        '/CREATE TABLE IF NOT EXISTS mobile_scan_sessions\s*\(.*?\b'
        . preg_quote($column, '/')
        . '\b.*?\) ENGINE=/si',
        $schema
    ) === 1;

    $addedAgain = preg_match(
        '/ALTER TABLE mobile_scan_sessions\s+ADD COLUMN(?: IF NOT EXISTS)?\s+'
        . preg_quote($column, '/')
        . '\b/i',
        $schema
    ) === 1;

    $checks[$column] = $definedInCreate && !$addedAgain;
}

$ok = !in_array(false, $checks, true);

echo json_encode([
    'ok' => $ok,
    'check' => 'clean_database_schema_no_duplicate_mobile_columns',
    'checks' => $checks,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

exit($ok ? 0 : 2);
