<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_optimization.php
 * Propósito: Verifica automáticamente que la funcionalidad «optimization» esté presente y sea coherente antes o después del despliegue.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$version = trim((string)@file_get_contents($root . '/VERSION'));
$checks = [];
$errors = [];

$requiredFiles = [
    'public/assets/vendor/bootstrap.min.css',
    'public/assets/vendor/bootstrap.bundle.min.js',
    'public/assets/app.js',
    'public/sw.js',
    'docker/entrypoint.sh',
    'scripts/healthcheck.php',
];
foreach ($requiredFiles as $relative) {
    $ok = is_file($root . '/' . $relative) && filesize($root . '/' . $relative) > 0;
    $checks['file:' . $relative] = $ok;
    if (!$ok) $errors[] = 'Falta el archivo optimizado: ' . $relative;
}

$views = (string)@file_get_contents($root . '/src/views.php');
$index = (string)@file_get_contents($root . '/public/index.php');
$dockerfile = (string)@file_get_contents($root . '/Dockerfile');
$compose = (string)@file_get_contents($root . '/docker-compose.yml');
$phpIni = (string)@file_get_contents($root . '/php.ini');
$htaccess = (string)@file_get_contents($root . '/public/.htaccess');
$serviceWorker = (string)@file_get_contents($root . '/public/sw.js');

$assertions = [
    'bootstrap_local_css' => str_contains($views, 'assets/vendor/bootstrap.min.css'),
    'bootstrap_local_js' => str_contains($views, 'assets/vendor/bootstrap.bundle.min.js'),
    'application_js_deferred' => str_contains($views, 'assets/app.js') && str_contains($views, 'defer'),
    'no_bootstrap_cdn' => !str_contains($views, 'cdn.jsdelivr.net'),
    'no_inline_script_blocks' => !preg_match('/<script(?![^>]*src=)[^>]*>/i', $views . $index),
    'no_inline_event_handlers' => !preg_match('/\son(?:click|change|submit|input|load)\s*=/i', $views . $index),
    'service_worker_version' => str_contains($serviceWorker, 'sivi-static-' . $version),
    'docker_healthcheck' => str_contains($dockerfile, 'HEALTHCHECK') && str_contains($dockerfile, 'scripts/healthcheck.php'),
    'docker_entrypoint' => str_contains($dockerfile, 'docker/entrypoint.sh'),
    'compose_internal_port_only' => str_contains($compose, 'expose:') && str_contains($compose, '- "80"') && !str_contains($compose, 'ports:'),
    'compose_log_rotation' => str_contains($compose, 'max-size: "20m"')
        && str_contains($compose, 'max-file: "5"'),
    'opcache_enabled' => str_contains($phpIni, 'opcache.enable=1'),
    'static_compression' => str_contains($htaccess, 'mod_deflate'),
    'content_security_policy' => str_contains($htaccess, 'Content-Security-Policy'),
];
foreach ($assertions as $name => $ok) {
    $checks[$name] = (bool)$ok;
    if (!$ok) $errors[] = 'No se cumple el control: ' . $name;
}

$result = [
    'ok' => $errors === [],
    'version' => $version,
    'check' => 'application_optimization_' . $version,
    'checks' => $checks,
    'errors' => $errors,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 2);
