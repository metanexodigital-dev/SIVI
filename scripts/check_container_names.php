<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_container_names.php
 * Propósito: Verifica la topología de dos servidores sin perder los nombres
 * lógicos de contenedores y volúmenes de SIVI.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$appCompose = str_replace(
    ["\r\n", "\r"],
    "\n",
    (string)@file_get_contents($root . '/docker-compose.yml')
);
$dbCompose = str_replace(
    ["\r\n", "\r"],
    "\n",
    (string)@file_get_contents($root . '/docker-compose-db.yml')
);

/** @return array<int,string> */
function yamlTopLevelChildKeys(string $yaml, string $section): array
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
            ) === 1) {
                $inside = true;
            }
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

    $minimumIndent = min(array_column($candidates, 'indent'));
    $keys = [];
    foreach ($candidates as $candidate) {
        if ($candidate['indent'] === $minimumIndent) {
            $keys[] = $candidate['key'];
        }
    }
    return array_values(array_unique($keys));
}

$checks = [
    'app_container' => str_contains(
        $appCompose,
        'container_name: ${APP_CONTAINER_NAME:-sivi-produccion-app}'
    ),
    'notifications_container' => str_contains(
        $appCompose,
        'container_name: ${NOTIFICATIONS_CONTAINER_NAME:-sivi-produccion-notificaciones}'
    ),
    'backup_container' => str_contains(
        $appCompose,
        'container_name: ${BACKUP_CONTAINER_NAME:-sivi-produccion-respaldos}'
    ),
    'db_container' => str_contains(
        $dbCompose,
        'container_name: ${DB_CONTAINER_NAME:-sivi-produccion-db}'
    ),
    'db_not_embedded_in_app_stack' =>
        !preg_match('/^  db:\s*$/m', $appCompose),
    'external_db_host' => str_contains(
        $appCompose,
        'DB_HOST: ${DB_HOST:?Configure DB_HOST}'
    ),
    'db_private_bind_required' => str_contains(
        $dbCompose,
        '${DB_LISTEN_IP:?Configure DB_LISTEN_IP}'
    ),
    'db_port_3306' => str_contains($dbCompose, '${DB_PORT:-3306}:3306'),
    'auto_migrate_false_app' =>
        substr_count($appCompose, 'AUTO_MIGRATE: "false"') >= 2,
    'app_storage_mount' => str_contains(
        $appCompose,
        'app_storage:/var/www/html/storage'
    ),
    'backup_storage_mount' => str_contains(
        $appCompose,
        'sivi_backups:/backups'
    ),
    'db_data_mount' => str_contains(
        $dbCompose,
        'db_data:/var/lib/mysql'
    ),
];

$appVolumes = yamlTopLevelChildKeys($appCompose, 'volumes');
$dbVolumes = yamlTopLevelChildKeys($dbCompose, 'volumes');

$checks['app_storage_declared'] =
    in_array('app_storage', $appVolumes, true);
$checks['sivi_backups_declared'] =
    in_array('sivi_backups', $appVolumes, true);
$checks['db_data_declared'] =
    in_array('db_data', $dbVolumes, true);

$checks['volumes_preserved'] =
    $checks['app_storage_mount']
    && $checks['backup_storage_mount']
    && $checks['db_data_mount']
    && $checks['app_storage_declared']
    && $checks['sivi_backups_declared']
    && $checks['db_data_declared'];

$ok = !in_array(false, $checks, true);

echo json_encode([
    'ok' => $ok,
    'check' => 'split_production_containers_and_volumes',
    'topology' => [
        'application_server' => 'APP_SERVER_HOSTNAME',
        'database_server' => 'DB_SERVER_HOSTNAME / DB_HOST',
        'dns' => 'APP_INTERNAL_DNS / APP_URL',
        'parameterized' => true,
    ],
    'app_volumes' => $appVolumes,
    'db_volumes' => $dbVolumes,
    'checks' => $checks,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

exit($ok ? 0 : 2);
