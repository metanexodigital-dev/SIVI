<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: public/health.php
 * Propósito: Indica disponibilidad sin divulgar versión, esquema, rutas o latencias.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

try {
    $startedAt = microtime(true);
    Database::connection()->query('SELECT 1')->fetchColumn();
    $databaseMilliseconds = (microtime(true) - $startedAt) * 1000;
    $storage = dirname(__DIR__) . '/storage';
    $writable = is_dir($storage) && is_writable($storage);
    $schema = Database::schemaStatus();
    $version = AppVersion::status();

    $ok = $writable
        && $databaseMilliseconds < 3000
        && $schema['ok']
        && $version['ok'];

    http_response_code($ok ? 200 : 503);
    echo json_encode([
        'status' => $ok ? 'ok' : 'degraded',
        'application' => 'SIVI',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    log_exception_reference($exception, 'health_database');
    http_response_code(503);
    echo json_encode([
        'status' => 'error',
        'application' => 'SIVI',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
