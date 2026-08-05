<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_secure_closure.php
 * Propósito: Verifica automáticamente que la funcionalidad «secure closure» esté presente y sea coherente antes o después del despliegue.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$required = [
    'VERSION',
    'RELEASE.json',
    'public/ready.php',
    'scripts/backup_dokploy.sh',
    'scripts/verify_dokploy_backup.sh',
    'scripts/restore_dokploy_backup.sh',
    'scripts/pre_deploy_secure.sh',
    'scripts/post_deploy_check.sh',
    'docker/backup/Dockerfile',
    'docker/backup/entrypoint.sh',
    'docker-compose.secure-fragment.yml',
];

$missing = [];
foreach ($required as $file) {
    if (!is_file($root . '/' . $file)) {
        $missing[] = $file;
    }
}

$version = is_file($root . '/VERSION') ? trim((string) file_get_contents($root . '/VERSION')) : '';
$release = [];
if (is_file($root . '/RELEASE.json')) {
    $decoded = json_decode((string) file_get_contents($root . '/RELEASE.json'), true);
    if (is_array($decoded)) {
        $release = $decoded;
    }
}

$dangerousFiles = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $item) {
    if (!$item->isFile()) {
        continue;
    }
    $path = str_replace('\\', '/', $item->getPathname());
    $relative = ltrim(substr($path, strlen(str_replace('\\', '/', $root))), '/');
    if (preg_match('#(^|/)(\.env|id_rsa|id_ed25519|.*\.pem|.*\.key)$#i', $relative)) {
        $dangerousFiles[] = $relative;
    }
}

require_once $root . '/src/Env.php';
Env::load($root . '/.env');
$requireRuntimeSecrets = Env::bool('SIVI_REQUIRE_RUNTIME_SECRETS', false);

$checks = [
    'required_files' => $missing === [],
    'version' => $version === '1.0.0.0',
    'release_json' => ($release['version'] ?? null) === '1.0.0.0',
    'no_secret_files' => $dangerousFiles === [],
    'backup_encryption_configured' => !$requireRuntimeSecrets
        || (getenv('BACKUP_ENCRYPTION_ENABLED') ?: 'true') !== 'true'
        || Env::source('BACKUP_ENCRYPTION_KEY') === 'file'
        || trim((string)Env::get('BACKUP_ENCRYPTION_KEY', '')) !== '',
    'cookie_secure' => strtolower((string) (getenv('COOKIE_SECURE') ?: 'true')) === 'true',
    'display_errors_disabled' => strtolower((string) (getenv('DISPLAY_ERRORS') ?: 'false')) === 'false',
];

$ok = !in_array(false, $checks, true);
echo json_encode([
    'ok' => $ok,
    'application' => 'SIVI',
    'version' => $version,
    'build_id' => $release['build_id'] ?? null,
    'runtime_secrets_required' => $requireRuntimeSecrets,
    'checks' => $checks,
    'missing_files' => $missing,
    'dangerous_files' => $dangerousFiles,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($ok ? 0 : 1);
