<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_ux_optimization.php
 * Propósito: Verifica automáticamente que la funcionalidad «ux optimization» esté presente y sea coherente antes o después del despliegue.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$checks = [];
$errors = [];

$files = [
    'views' => $root . '/src/views.php',
    'index' => $root . '/public/index.php',
    'css' => $root . '/public/assets/app.css',
    'auth' => $root . '/src/Auth.php',
    'bootstrap' => $root . '/src/bootstrap.php',
    'javascript' => $root . '/public/assets/app.js',
];
foreach ($files as $key => $file) {
    $checks[$key . '_exists'] = is_file($file);
    if (!$checks[$key . '_exists']) $errors[] = "Falta {$file}.";
}

$views = is_file($files['views']) ? (string)file_get_contents($files['views']) : '';
$index = is_file($files['index']) ? (string)file_get_contents($files['index']) : '';
$css = is_file($files['css']) ? (string)file_get_contents($files['css']) : '';
$auth = is_file($files['auth']) ? (string)file_get_contents($files['auth']) : '';
$bootstrap = is_file($files['bootstrap']) ? (string)file_get_contents($files['bootstrap']) : '';
$javascript = is_file($files['javascript']) ? (string)file_get_contents($files['javascript']) : '';

$requirements = [
    'grouped_navigation' => str_contains($views, 'nav-group-title') && str_contains($views, 'Trabajo diario'),
    'dashboard_priorities' => str_contains($index, 'Qué debe atender ahora') && str_contains($index, 'taskItems'),
    'validation_sequence' => str_contains($index, 'equipment-sequence-bar') && str_contains($index, 'previousEquipment'),
    'local_drafts' => str_contains($javascript, 'localStorage.setItem(draftKey') && str_contains($index, 'data-draft-key'),
    'dirty_guard' => str_contains($javascript, 'beforeunload') && str_contains($index, 'data-dirty-guard'),
    'submit_lock' => str_contains($javascript, 'Procesando') && str_contains($javascript, 'dataset.submitting'),
    'session_env' => str_contains($auth, 'SESSION_IDLE_TIMEOUT_MINUTES') && str_contains($bootstrap, 'COOKIE_SAMESITE'),
    'responsive_styles' => str_contains($css, 'SIVI 0.0.0.11') && str_contains($css, '.task-grid'),
];
foreach ($requirements as $key => $ok) {
    $checks[$key] = $ok;
    if (!$ok) $errors[] = "No se encontró el control requerido: {$key}.";
}

$result = [
    'ok' => $errors === [],
    'version' => trim((string)@file_get_contents($root . '/VERSION')),
    'check' => 'ux_optimization_0.0.0.11',
    'checks' => $checks,
    'errors' => $errors,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 2);
