<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_production_baseline.php
 * Propósito: Verifica la línea base oficial SIVI 1.0.0.0 para producción.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$compose = (string)@file_get_contents($root . '/docker-compose.yml');
$dbCompose = (string)@file_get_contents($root . '/docker-compose-db.yml');
$entrypoint = (string)@file_get_contents($root . '/docker/entrypoint.sh');
$dbDockerfile = (string)@file_get_contents($root . '/docker/db/Dockerfile');
$dbInit = (string)@file_get_contents($root . '/docker/db/99-sivi-register-release.sh');
$release = (string)@file_get_contents($root . '/RELEASE.json');
$version = trim((string)@file_get_contents($root . '/VERSION'));

$checks = [
    'version' => $version === '1.0.0.0',
    'official_build' => str_contains($release, '"build_id": "SIVI-1.0.0.0"'),
    'release_no_migrations' => str_contains($release, '"automatic_migrations": false')
        && str_contains($release, '"database_migration_required": false'),
    'custom_database_image' => str_contains($dbCompose, 'dockerfile: docker/db/Dockerfile')
        && str_contains($dbCompose, 'image: sivi-mysql84:${APP_VERSION:-1.0.0.0}'),
    'app_forces_auto_migrate_false' => substr_count($compose, 'AUTO_MIGRATE: "false"') >= 2,
    'entrypoint_does_not_call_migrate' => !str_contains($entrypoint, 'scripts/migrate.php'),
    'schema_copied_to_mysql_init' => str_contains($dbDockerfile, '01-sivi-schema.sql'),
    'clean_schema_has_no_duplicate_mobile_columns' => substr_count((string)@file_get_contents($root . '/database/schema.sql'), 'ALTER TABLE mobile_scan_sessions ADD COLUMN') === 0,
    'release_registration_script' => str_contains($dbInit, 'schema_migrations')
        && str_contains($dbInit, 'app_release_history')
        && str_contains($dbInit, 'RELEASE_KEY'),
    'database_health_avoids_password_arguments' => str_contains($dbCompose, 'mysqladmin --defaults-extra-file=$$CNF ping --silent')
        && !str_contains($dbCompose, '-p"$$(cat /run/secrets/db_password)"'),
    'database_initialization_registers_schema' => str_contains($dbDockerfile, '99-sivi-register-release.sh'),
    'setup_required' => str_contains($release, '"setup_required": true'),
    'split_topology' => !preg_match('/^  db:\s*$/m', $compose)
        && preg_match('/^  db:\s*$/m', $dbCompose) === 1,
    'external_db_host' => str_contains($compose, 'DB_HOST: ${DB_HOST:?Configure DB_HOST}'),
];

$ok = !in_array(false, $checks, true);
echo json_encode([
    'ok' => $ok,
    'check' => 'production_baseline_1.0.0.0',
    'checks' => $checks,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($ok ? 0 : 2);
