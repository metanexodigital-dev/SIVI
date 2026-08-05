<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_container_name_formats.php
 * Propósito: Verifica formatos de los volúmenes en ambos Compose.
 */
declare(strict_types=1);

/** @return array<int,string> */
function yamlKeys(string $yaml, string $section): array
{
    $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $yaml));
    $inside = false;
    $candidates = [];

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (!$inside) {
            if (preg_match(
                '/^' . preg_quote($section, '/') . ':\s*(?:#.*)?$/',
                $line
            ) === 1) $inside = true;
            continue;
        }
        if ($trimmed === '' || str_starts_with($trimmed, '#')) continue;
        if (preg_match('/^[^\s#][^:]*:\s*(?:#.*)?$/', $line) === 1) break;
        if (preg_match(
            '/^([ \t]+)([A-Za-z0-9_.-]+):(?:\s|$)/',
            $line,
            $match
        ) === 1) {
            $candidates[] = [
                'indent' => strlen(str_replace("\t", "    ", $match[1])),
                'key' => $match[2],
            ];
        }
    }

    if ($candidates === []) return [];
    $minimum = min(array_column($candidates, 'indent'));
    return array_values(array_unique(array_map(
        static fn(array $item): string => $item['key'],
        array_values(array_filter(
            $candidates,
            static fn(array $item): bool => $item['indent'] === $minimum
        ))
    )));
}

$root = dirname(__DIR__);
$app = (string)@file_get_contents($root . '/docker-compose.yml');
$db = (string)@file_get_contents($root . '/docker-compose-db.yml');

$checks = [
    'app_volumes' =>
        array_diff(['app_storage','sivi_backups'], yamlKeys($app, 'volumes'))
        === [],
    'db_volume' =>
        in_array('db_data', yamlKeys($db, 'volumes'), true),
    'app_lf' =>
        array_diff(
            ['app_storage','sivi_backups'],
            yamlKeys(str_replace("\r\n", "\n", $app), 'volumes')
        ) === [],
    'db_crlf' =>
        in_array(
            'db_data',
            yamlKeys(str_replace("\n", "\r\n", $db), 'volumes'),
            true
        ),
];

$ok = !in_array(false, $checks, true);

echo json_encode([
    'ok' => $ok,
    'check' => 'split_volume_format_compatibility',
    'checks' => $checks,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

exit($ok ? 0 : 2);
