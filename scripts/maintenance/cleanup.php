<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/maintenance/cleanup.php
 * Propósito: Previsualiza o elimina temporales y archivos vencidos.
 */
declare(strict_types=1);

$startedAt = microtime(true);
$root = dirname(__DIR__, 2);
$apply = in_array('--apply', $argv ?? [], true);

$previewHours = max(
    1,
    (int)(getenv('IMPORT_PREVIEW_RETENTION_HOURS') ?: 24)
);
$tempHours = max(
    1,
    (int)(getenv('TEMP_FILE_RETENTION_HOURS') ?: 12)
);
$logDays = max(
    7,
    (int)(getenv('LOG_RETENTION_DAYS') ?: 90)
);

$rules = [
    [
        'directory' => $root . '/storage/import-previews',
        'seconds' => $previewHours * 3600,
        'match' => static fn(SplFileInfo $file): bool => true,
    ],
    [
        'directory' => $root . '/storage',
        'seconds' => $tempHours * 3600,
        'match' => static fn(SplFileInfo $file): bool =>
            str_ends_with(strtolower($file->getFilename()), '.tmp'),
    ],
    [
        'directory' => $root . '/storage/logs',
        'seconds' => $logDays * 86400,
        'match' => static fn(SplFileInfo $file): bool =>
            preg_match('/\.(?:log|json)\.\d+(?:\.gz)?$/i', $file->getFilename()) === 1,
    ],
];

$now = time();
$candidates = [];
$totalBytes = 0;
$deleted = 0;
$errors = [];

foreach ($rules as $rule) {
    if (!is_dir($rule['directory'])) continue;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $rule['directory'],
            FilesystemIterator::SKIP_DOTS
        )
    );

    foreach ($iterator as $file) {
        if (!$file->isFile()) continue;
        if (!$rule['match']($file)) continue;
        if (($now - $file->getMTime()) < $rule['seconds']) continue;

        $relative = str_replace(
            '\\',
            '/',
            substr($file->getPathname(), strlen($root) + 1)
        );
        $size = $file->getSize();
        $candidates[] = [
            'file' => $relative,
            'size' => $size,
            'age_hours' => round(($now - $file->getMTime()) / 3600, 1),
        ];
        $totalBytes += $size;

        if ($apply) {
            if (@unlink($file->getPathname())) {
                $deleted++;
            } else {
                $errors[] = $relative;
            }
        }
    }
}

$result = [
    'ok' => $errors === [],
    'mode' => $apply ? 'apply' : 'preview',
    'candidates' => count($candidates),
    'deleted' => $deleted,
    'recoverable_mb' => round($totalBytes / 1048576, 2),
    'errors' => $errors,
    'files' => $candidates,
    'duration_ms' => (int)round(
        (microtime(true) - $startedAt) * 1000
    ),
    'memory_peak_mb' => round(
        memory_get_peak_usage(true) / 1048576,
        2
    ),
];

echo json_encode(
    $result,
    JSON_PRETTY_PRINT
    | JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
) . PHP_EOL;

exit($result['ok'] ? 0 : 2);
