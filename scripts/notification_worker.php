<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/notification_worker.php
 * Propósito: Procesa notificaciones con heartbeat, backoff y apagado limpio.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$interval = max(
    10,
    min(
        300,
        (int)(Env::get('NOTIFICATION_WORKER_INTERVAL_SECONDS', '20') ?? '20')
    )
);
$batch = max(
    1,
    min(
        100,
        (int)(Env::get('NOTIFICATION_WORKER_BATCH', '25') ?? '25')
    )
);
$maximumBackoff = max(
    $interval,
    min(
        900,
        (int)(Env::get('NOTIFICATION_WORKER_MAX_BACKOFF_SECONDS', '300') ?? '300')
    )
);
$maximumCycles = max(
    0,
    min(
        100000,
        (int)(Env::get('NOTIFICATION_WORKER_MAX_CYCLES', '5000') ?? '5000')
    )
);
$memoryLimitMb = max(
    64,
    min(
        1024,
        (int)(Env::get('NOTIFICATION_WORKER_MEMORY_LIMIT_MB', '192') ?? '192')
    )
);

$running = true;
$shutdownReason = 'normal';
$heartbeatPath = dirname(__DIR__)
    . '/storage/logs/notification-worker-heartbeat.json';

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$running, &$shutdownReason): void {
        $shutdownReason = 'sigterm';
        $running = false;
    });
    pcntl_signal(SIGINT, static function () use (&$running, &$shutdownReason): void {
        $shutdownReason = 'sigint';
        $running = false;
    });
}

$writeHeartbeat = static function (array $payload) use ($heartbeatPath): void {
    $directory = dirname($heartbeatPath);
    if (!is_dir($directory)) {
        @mkdir($directory, 0775, true);
    }
    if (!is_dir($directory) || !is_writable($directory)) return;

    $temporary = $heartbeatPath . '.tmp';
    @file_put_contents(
        $temporary,
        json_encode(
            $payload,
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        ) . PHP_EOL
    );
    @rename($temporary, $heartbeatPath);
};

$cycle = 0;
$failureStreak = 0;
$nextSleep = $interval;
$totals = [
    'processed' => 0,
    'sent' => 0,
    'failed' => 0,
];

fwrite(STDOUT, json_encode([
    'event' => 'worker_started',
    'time' => date(DATE_ATOM),
    'interval_seconds' => $interval,
    'batch' => $batch,
    'maximum_backoff_seconds' => $maximumBackoff,
    'maximum_cycles' => $maximumCycles,
    'memory_limit_mb' => $memoryLimitMb,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);

while ($running) {
    $cycle++;
    $startedAt = microtime(true);
    $status = 'ok';
    $reference = null;
    $result = [];

    try {
        $result = NotificationQueue::processBatch($batch);
        $failureStreak = 0;
        $nextSleep = $interval;

        foreach (array_keys($totals) as $key) {
            $totals[$key] += (int)($result[$key] ?? 0);
        }
    } catch (Throwable $exception) {
        $status = 'error';
        $failureStreak++;
        $reference = log_exception_reference(
            $exception,
            'notification_worker'
        );
        $nextSleep = min(
            $maximumBackoff,
            $interval * (2 ** min(5, $failureStreak))
        );
        $totals['failed']++;
    }

    $memoryMb = round(memory_get_usage(true) / 1048576, 2);
    $event = [
        'event' => 'worker_cycle',
        'status' => $status,
        'time' => date(DATE_ATOM),
        'cycle' => $cycle,
        'duration_ms' => (int)round(
            (microtime(true) - $startedAt) * 1000
        ),
        'memory_mb' => $memoryMb,
        'failure_streak' => $failureStreak,
        'next_sleep_seconds' => $nextSleep,
        'result' => $result,
        'reference' => $reference,
        'totals' => $totals,
    ];

    $writeHeartbeat($event);

    if ($status === 'error' || (int)($result['processed'] ?? 0) > 0) {
        $stream = $status === 'error' ? STDERR : STDOUT;
        fwrite(
            $stream,
            json_encode(
                $event,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ) . PHP_EOL
        );
    }

    if ($maximumCycles > 0 && $cycle >= $maximumCycles) {
        $shutdownReason = 'maximum_cycles';
        break;
    }
    if ($memoryMb >= $memoryLimitMb) {
        $shutdownReason = 'memory_limit';
        break;
    }

    for ($second = 0; $second < $nextSleep && $running; $second++) {
        sleep(1);
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }
}

$shutdown = [
    'event' => 'worker_stopped',
    'time' => date(DATE_ATOM),
    'reason' => $shutdownReason,
    'cycles' => $cycle,
    'totals' => $totals,
    'memory_peak_mb' => round(
        memory_get_peak_usage(true) / 1048576,
        2
    ),
];
$writeHeartbeat($shutdown);
fwrite(
    STDOUT,
    json_encode(
        $shutdown,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL
);

exit(0);
