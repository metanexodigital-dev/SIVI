<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/process_notification_queue.php
 * Propósito: Procesa un lote controlado y reporta métricas.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$startedAt = microtime(true);
$limit = 25;
foreach ($argv ?? [] as $argument) {
    if (preg_match('/^--limit=(\d+)$/', $argument, $match)) {
        $limit = (int)$match[1];
    }
}
$limit = max(1, min(100, $limit));

try {
    $result = NotificationQueue::processBatch($limit);
    $payload = [
        'ok' => true,
        'limit' => $limit,
        'duration_ms' => (int)round(
            (microtime(true) - $startedAt) * 1000
        ),
        'memory_peak_mb' => round(
            memory_get_peak_usage(true) / 1048576,
            2
        ),
    ] + $result;
    echo json_encode(
        $payload,
        JSON_PRETTY_PRINT
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    $reference = log_exception_reference(
        $exception,
        'process_notification_queue'
    );
    fwrite(STDERR, json_encode([
        'ok' => false,
        'reference' => $reference,
        'duration_ms' => (int)round(
            (microtime(true) - $startedAt) * 1000
        ),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
