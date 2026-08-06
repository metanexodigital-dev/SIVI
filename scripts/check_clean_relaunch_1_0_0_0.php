<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_clean_relaunch_1_0_0_0.php
 * Propósito: Verifica la línea base oficial del nuevo servidor.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CheckRunner.php';

$root = dirname(__DIR__);
$check = new CheckRunner('clean_relaunch_1.0.0.0', $root);

$version = trim((string)@file_get_contents($root . '/VERSION'));
$release = json_decode(
    (string)@file_get_contents($root . '/RELEASE.json'),
    true
);
$updates = (string)@file_get_contents($root . '/Actualizaciones.md');
$compose = (string)@file_get_contents($root . '/docker-compose.yml');
$dbCompose = (string)@file_get_contents($root . '/docker-compose-db.yml');
$entrypoint = (string)@file_get_contents($root . '/docker/entrypoint.sh');
$dbDockerfile = (string)@file_get_contents($root . '/docker/db/Dockerfile');

$check->add('version', $version === 'Pre-1.0.0.1', $version);
$check->add(
    'build_id',
    (string)($release['build_id'] ?? '') === 'SIVI-Pre-1.0.0.1',
    (string)($release['build_id'] ?? '')
);
$check->add(
    'production_baseline',
    (string)($release['production_baseline'] ?? '') === '1.0.0.0',
    (string)($release['production_baseline'] ?? '')
);
$check->add(
    'fresh_install',
    ($release['stage'] ?? '') === 'preproduction'
        && empty($release['fresh_install'])
        && empty($release['clean_relaunch']),
    'Actualización controlada de preproducción'
);
$check->add(
    'next_version',
    (string)($release['next_version'] ?? '') === 'Pre-1.0.0.2',
    (string)($release['next_version'] ?? '')
);
$check->add(
    'official_history',
    preg_match_all('/^## 1\.0\.0\.\d+\b/m', $updates) === 1
        && preg_match('/^## 1\.0\.0\.0\b/m', $updates) === 1,
    'Solo 1.0.0.0'
);
$check->add(
    'db_host_parameterized',
    str_contains($compose, '${DB_HOST:?Configure DB_HOST}'),
    'DB_HOST'
);
$check->add(
    'volumes',
    str_contains($compose, 'app_storage:')
        && str_contains($compose, 'sivi_backups:')
        && str_contains($dbCompose, 'db_data:'),
    'Persistencia distribuida'
);
$check->add(
    'containers',
    str_contains($compose, 'sivi-produccion-app')
        && str_contains($compose, 'sivi-produccion-notificaciones')
        && str_contains($compose, 'sivi-produccion-respaldos')
        && str_contains($dbCompose, 'sivi-produccion-db'),
    'Cuatro contenedores en dos servidores'
);
$check->add(
    'split_topology',
    !preg_match('/^  db:\s*$/m', $compose)
        && preg_match('/^  db:\s*$/m', $dbCompose) === 1,
    'APP y DB separados'
);
$check->add(
    'application_dns_parameterized',
    str_contains($compose, '${APP_URL:?Configure APP_URL}')
        && str_contains($compose, '${APP_INTERNAL_DNS:?Configure APP_INTERNAL_DNS}'),
    'APP_URL / APP_INTERNAL_DNS'
);
$check->add(
    'no_automatic_migrations',
    !str_contains($entrypoint, 'scripts/migrate.php'),
    'AUTO_MIGRATE=false'
);
$check->add(
    'schema_init',
    str_contains($dbDockerfile, '/docker-entrypoint-initdb.d/01-sivi-schema.sql'),
    'Inicialización limpia de MySQL'
);

$check->outputAndExit();
