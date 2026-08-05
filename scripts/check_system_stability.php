<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_system_stability.php
 * Propósito: Verifica automáticamente que la funcionalidad «system stability» esté presente y sea coherente antes o después del despliegue.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$version = trim((string)@file_get_contents($root . '/VERSION'));
$checks = [];
$errors = [];

$check = static function (string $key, bool $ok, string $detail = '') use (&$checks, &$errors): void {
    $checks[$key] = ['ok' => $ok, 'detail' => $detail];
    if (!$ok) $errors[] = $key . ($detail !== '' ? ': ' . $detail : '');
};

$required = [
    'src/SystemHealth.php',
    'scripts/check_system_stability.php',
    'scripts/check_repository_integrity.sh',
    'scripts/backup_dokploy.sh',
    'scripts/restore_dokploy_backup.sh',
    '.github/workflows/sivi-ci.yml',
];
foreach ($required as $relative) {
    $check('file_' . str_replace(['/', '.'], '_', $relative), is_file($root . '/' . $relative) && filesize($root . '/' . $relative) > 0, $relative);
}

$bootstrap = (string)@file_get_contents($root . '/src/bootstrap.php');
$index = (string)@file_get_contents($root . '/public/index.php');
$views = (string)@file_get_contents($root . '/src/views.php');
$dockerfile = (string)@file_get_contents($root . '/Dockerfile');
$compose = (string)@file_get_contents($root . '/docker-compose.yml');
$dbCompose = (string)@file_get_contents($root . '/docker-compose-db.yml');

$check('bootstrap_system_health', str_contains($bootstrap, "require_once __DIR__ . '/SystemHealth.php';"));
$check('route_system_health', str_contains($index, "case 'sistema': system_health_page(); break;"));
$check('page_system_health', str_contains($index, 'function system_health_page(): void'));
$check('menu_system_health', str_contains($views, "['sistema','Estado del sistema'"));
$check('permission_admin_system_health', str_contains($views, "'diagnostico','sistema','usuarios'"));
$check('docker_build_gate', str_contains($dockerfile, 'sh scripts/run_production_build_checks.sh') && str_contains((string)@file_get_contents($root . '/scripts/run_production_build_checks.sh'), 'php scripts/build/run.php'));
$check('compose_version', str_contains($compose, 'APP_VERSION: ${APP_VERSION:-' . $version . '}'));

foreach (['Dockerfile'=>$dockerfile, 'docker-compose.yml'=>$compose, 'docker-compose-db.yml'=>$dbCompose] as $name=>$contents) {
    $check('conflicts_' . str_replace(['.', '-'], '_', $name), preg_match('/^(<<<<<<< .*|=======|>>>>>>> .*)$/m', $contents) !== 1, $name);
}

$result = [
    'ok' => $errors === [],
    'version' => $version,
    'check' => 'system_stability_' . $version,
    'checks' => $checks,
    'errors' => $errors,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 2);
