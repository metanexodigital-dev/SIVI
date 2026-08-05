<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_script_architecture_1_0_0_9.php
 * Propósito: Verifica la organización y seguridad de los scripts.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CheckRunner.php';

$root = dirname(__DIR__);
$check = new CheckRunner('script_architecture_1.0.0.0', $root);

foreach ([
    'scripts/lib/CheckRunner.php',
    'scripts/lib/ProcessRunner.php',
    'scripts/build/run.php',
    'scripts/build/checks.json',
    'scripts/maintenance/cleanup.php',
] as $file) {
    $check->assertFile($file);
}

$check->assertJson('scripts/build/checks.json');

$manifestPath = $root . '/scripts/build/checks.json';
$manifest = is_file($manifestPath)
    ? json_decode((string)file_get_contents($manifestPath), true)
    : null;

$critical = is_array($manifest['critical'] ?? null)
    ? $manifest['critical']
    : [];
$advisory = is_array($manifest['advisory'] ?? null)
    ? $manifest['advisory']
    : [];

$check->add(
    'manifest_critical',
    count($critical) >= 10,
    (string)count($critical)
);
$check->add(
    'manifest_advisory',
    count($advisory) >= 10,
    (string)count($advisory)
);
$check->add(
    'parallelism',
    (int)($manifest['default_parallelism'] ?? 0) === 4,
    (string)($manifest['default_parallelism'] ?? 0)
);

$commands = [];
$missingCommands = [];
foreach (array_merge($critical, $advisory) as $definition) {
    $command = array_values((array)($definition['command'] ?? []));
    $key = implode("\0", array_map('strval', $command));
    if ($key !== '') $commands[] = $key;

    $script = (string)($command[1] ?? '');
    if (
        str_starts_with($script, 'scripts/')
        && !is_file($root . '/' . $script)
    ) {
        $missingCommands[] = $script;
    }
}
$check->add(
    'commands_unique',
    count($commands) === count(array_unique($commands)),
    count($commands) . ' comandos'
);
$check->add(
    'commands_exist',
    $missingCommands === [],
    implode(', ', $missingCommands)
);

$runner = (string)@file_get_contents(
    $root . '/scripts/run_production_build_checks.sh'
);
$check->add(
    'thin_wrapper',
    str_contains($runner, 'php scripts/build/run.php')
        && !str_contains($runner, 'run_critical'),
    'Motor central'
);

$preflight = (string)@file_get_contents($root . '/scripts/preflight.php');
$check->add(
    'preflight_no_migrate_requirement',
    !str_contains($preflight, "'scripts/migrate.php'"),
    'migrate.php no es requisito'
);
$check->add(
    'preflight_metrics',
    str_contains($preflight, "'duration_ms'")
        && str_contains($preflight, "'memory_peak_mb'"),
    'Duración y memoria'
);

$legacyFiles = [
    'scripts/migrate.php',
    'scripts/apply_secure_schema.php',
    'scripts/install_guided_ux_patch.php',
];
$legacyRemoved = true;
foreach ($legacyFiles as $file) {
    $legacyRemoved = $legacyRemoved && !is_file($root . '/' . $file);
}
$check->add(
    'legacy_schema_tools_removed',
    $legacyRemoved,
    'La línea base 1.0.0.0 no distribuye herramientas DDL históricas'
);

$worker = (string)@file_get_contents(
    $root . '/scripts/notification_worker.php'
);
$check->add(
    'notification_worker_resilience',
    str_contains($worker, 'pcntl_signal')
        && str_contains($worker, 'failureStreak')
        && str_contains($worker, 'notification-worker-heartbeat.json'),
    'Señales, backoff y heartbeat'
);

$backup = (string)@file_get_contents(
    $root . '/scripts/backup_dokploy.sh'
);
$check->add(
    'backup_optimized',
    str_contains($backup, 'pigz -6')
        && str_contains($backup, 'gzip -6')
        && str_contains($backup, 'flock -n')
        && str_contains($backup, '--hex-blob'),
    'Compresión 6, flock y dump seguro'
);

$postDeploy = (string)@file_get_contents(
    $root . '/scripts/post_deploy_check.sh'
);
$check->add(
    'post_deploy_metrics',
    str_contains($postDeploy, 'delays=(2 3 5 8)')
        && str_contains($postDeploy, 'health_ms')
        && str_contains($postDeploy, 'ready_ms'),
    'Backoff y métricas'
);

$entrypoint = (string)@file_get_contents(
    $root . '/docker/entrypoint.sh'
);
$check->add(
    'no_automatic_migrations',
    !str_contains($entrypoint, 'scripts/migrate.php'),
    'AUTO_MIGRATE=false'
);

$check->outputAndExit();
