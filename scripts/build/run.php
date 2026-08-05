<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/build/run.php
 * Propósito: Ejecuta controles críticos secuenciales y complementarios en paralelo.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/ProcessRunner.php';

$root = dirname(__DIR__, 2);
$jsonMode = in_array('--json', $argv ?? [], true);
$manifestPath = __DIR__ . '/checks.json';
$startedAt = microtime(true);

try {
    $manifest = json_decode(
        (string)file_get_contents($manifestPath),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
} catch (Throwable $exception) {
    fwrite(STDERR, "No fue posible leer scripts/build/checks.json.\n");
    exit(2);
}

$critical = is_array($manifest['critical'] ?? null)
    ? $manifest['critical']
    : [];
$advisory = is_array($manifest['advisory'] ?? null)
    ? $manifest['advisory']
    : [];

$parallelism = (int)(getenv('SIVI_BUILD_PARALLELISM')
    ?: ($manifest['default_parallelism'] ?? 4));
$parallelism = max(1, min(8, $parallelism));

$results = [
    'critical' => [],
    'advisory' => [],
];

$printResult = static function (
    string $type,
    string $name,
    array $result
) use ($jsonMode): void {
    if ($jsonMode) return;

    $separator = $type === 'critical'
        ? str_repeat('=', 64)
        : str_repeat('-', 64);

    echo PHP_EOL . $separator . PHP_EOL;
    echo strtoupper($type) . ': ' . $name . PHP_EOL;
    echo 'COMANDO: ' . implode(' ', $result['command']) . PHP_EOL;
    echo 'DURACIÓN: ' . $result['duration_ms'] . " ms\n";
    echo $separator . PHP_EOL;

    if ($result['stdout'] !== '') {
        echo $result['stdout'] . PHP_EOL;
    }
    if ($result['stderr'] !== '') {
        fwrite(STDERR, $result['stderr'] . PHP_EOL);
    }

    $label = $result['ok']
        ? 'OK'
        : ($type === 'critical' ? 'ERROR CRÍTICO' : 'ADVERTENCIA');
    echo 'RESULTADO: ' . $label . ' - ' . $name . PHP_EOL;
};

$saveReport = static function (array $report) use ($root): void {
    $logDirectory = $root . '/storage/logs';
    if (!is_dir($logDirectory)) {
        @mkdir($logDirectory, 0775, true);
    }
    if (!is_dir($logDirectory) || !is_writable($logDirectory)) {
        return;
    }
    $path = $logDirectory . '/build-checks-last.json';
    $temporary = $path . '.tmp';
    @file_put_contents(
        $temporary,
        json_encode(
            $report,
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        ) . PHP_EOL
    );
    @rename($temporary, $path);
};

foreach ($critical as $definition) {
    $name = (string)($definition['name'] ?? 'Control crítico');
    $command = array_values(array_map(
        'strval',
        (array)($definition['command'] ?? [])
    ));

    if ($command === []) {
        $result = [
            'ok' => false,
            'exit_code' => 2,
            'command' => [],
            'stdout' => '',
            'stderr' => 'Comando vacío.',
            'duration_ms' => 0,
        ];
    } else {
        try {
            $result = ProcessRunner::run($command, $root);
        } catch (Throwable $exception) {
            $result = [
                'ok' => false,
                'exit_code' => 2,
                'command' => $command,
                'stdout' => '',
                'stderr' => $exception->getMessage(),
                'duration_ms' => 0,
            ];
        }
    }

    $results['critical'][] = [
        'name' => $name,
        'result' => $result,
    ];
    $printResult('critical', $name, $result);

    if (!$result['ok']) {
        $report = [
            'ok' => false,
            'stopped_on' => $name,
            'parallelism' => $parallelism,
            'duration_ms' => (int)round(
                (microtime(true) - $startedAt) * 1000
            ),
            'memory_peak_mb' => round(
                memory_get_peak_usage(true) / 1048576,
                2
            ),
            'results' => $results,
        ];
        $saveReport($report);
        if ($jsonMode) {
            echo json_encode(
                $report,
                JSON_PRETTY_PRINT
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            ) . PHP_EOL;
        }
        exit((int)($result['exit_code'] ?: 2));
    }
}

$queue = array_values($advisory);
$running = [];
$completed = [];

while ($queue !== [] || $running !== []) {
    while ($queue !== [] && count($running) < $parallelism) {
        $definition = array_shift($queue);
        $name = (string)($definition['name'] ?? 'Control complementario');
        $command = array_values(array_map(
            'strval',
            (array)($definition['command'] ?? [])
        ));

        try {
            $job = ProcessRunner::start($command, $root);
            $running[] = [
                'name' => $name,
                'job' => $job,
            ];
        } catch (Throwable $exception) {
            $completed[] = [
                'name' => $name,
                'result' => [
                    'ok' => false,
                    'exit_code' => 2,
                    'command' => $command,
                    'stdout' => '',
                    'stderr' => $exception->getMessage(),
                    'duration_ms' => 0,
                ],
            ];
        }
    }

    foreach ($running as $index => &$entry) {
        $result = ProcessRunner::poll($entry['job']);
        if ($result === null) {
            continue;
        }
        $completed[] = [
            'name' => $entry['name'],
            'result' => $result,
        ];
        unset($running[$index]);
    }
    unset($entry);
    $running = array_values($running);

    if ($running !== []) {
        usleep(20000);
    }
}

foreach ($completed as $entry) {
    $results['advisory'][] = $entry;
    $printResult('advisory', $entry['name'], $entry['result']);
}

$criticalPassed = count($results['critical']);
$advisoryPassed = count(array_filter(
    $results['advisory'],
    static fn(array $entry): bool => $entry['result']['ok']
));
$advisoryFailed = count($results['advisory']) - $advisoryPassed;

$report = [
    'ok' => true,
    'parallelism' => $parallelism,
    'critical_passed' => $criticalPassed,
    'advisory_passed' => $advisoryPassed,
    'advisory_failed' => $advisoryFailed,
    'duration_ms' => (int)round(
        (microtime(true) - $startedAt) * 1000
    ),
    'memory_peak_mb' => round(
        memory_get_peak_usage(true) / 1048576,
        2
    ),
    'results' => $results,
];

$saveReport($report);

if ($jsonMode) {
    echo json_encode(
        $report,
        JSON_PRETTY_PRINT
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
} else {
    echo PHP_EOL . str_repeat('=', 64) . PHP_EOL;
    echo "VALIDACIÓN DE CONSTRUCCIÓN FINALIZADA\n";
    echo "Controles críticos aprobados: {$criticalPassed}\n";
    echo "Controles complementarios aprobados: {$advisoryPassed}\n";
    echo "Advertencias complementarias: {$advisoryFailed}\n";
    echo "Paralelismo complementario: {$parallelism}\n";
    echo "Duración total: {$report['duration_ms']} ms\n";
    echo "Resultado del build: APROBADO\n";
    echo str_repeat('=', 64) . PHP_EOL;
}

exit(0);
