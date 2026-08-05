<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_release_files.php
 * Propósito: Verifica automáticamente que la funcionalidad «release files» esté presente y sea coherente antes o después del despliegue.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$errors = [];
$warnings = [];
$checks = [];

/**
 * Lee una variable dotenv admitiendo espacios, comillas y archivos CRLF.
 */
function dotenvValue(string $contents, string $key): ?string
{
    // Tolera BOM UTF-8 agregado por editores o interfaces web.
    $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
    $lines = preg_split('/\R/u', $contents) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!preg_match('/^' . preg_quote($key, '/') . '\s*=\s*(.*)$/', $line, $match)) {
            continue;
        }
        $value = trim($match[1]);
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }
        return trim($value);
    }
    return null;
}

function readJsonFile(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : null;
}

$version = trim((string)@file_get_contents($root . '/VERSION'));
$validFormat = preg_match('/^\d+\.\d+\.\d+\.\d+$/', $version) === 1;
$checks['version_format'] = $validFormat;
if (!$validFormat) {
    $errors[] = 'VERSION no tiene el formato N.N.N.N.';
}

$release = readJsonFile($root . '/RELEASE.json');
$releaseOk = is_array($release) && (string)($release['version'] ?? '') === $version;
$checks['release_json'] = $releaseOk;
if (!$releaseOk) {
    $errors[] = 'RELEASE.json no coincide con VERSION.';
}

$requiredEnvOk = is_array($release)
    && (string)($release['required_environment_variable'] ?? '') === 'APP_VERSION=' . $version;
$checks['release_required_environment'] = $requiredEnvOk;
if (!$requiredEnvOk) {
    $errors[] = 'RELEASE.json debe declarar required_environment_variable=APP_VERSION=' . $version . '.';
}

$manifest = readJsonFile($root . '/public/manifest.webmanifest');
$manifestOk = is_array($manifest) && (string)($manifest['version'] ?? '') === $version;
$checks['web_manifest'] = $manifestOk;
if (!$manifestOk) {
    $errors[] = 'public/manifest.webmanifest no coincide con VERSION.';
}

$brandManifest = readJsonFile($root . '/public/assets/brand/pwa/manifest.webmanifest');
$brandManifestOk = is_array($brandManifest) && (string)($brandManifest['version'] ?? '') === $version;
$checks['brand_web_manifest'] = $brandManifestOk;
if (!$brandManifestOk) {
    $errors[] = 'El manifiesto PWA de identidad visual no coincide con VERSION.';
}

// La plantilla obligatoria usa un nombre no oculto para evitar que herramientas
// de carga, clientes Git o interfaces web omitan accidentalmente .env.example.
$canonicalEnvPath = $root . '/config/environment.example';
$canonicalEnv = is_file($canonicalEnvPath) ? (string)file_get_contents($canonicalEnvPath) : '';
$canonicalEnvVersion = dotenvValue($canonicalEnv, 'APP_VERSION');
$canonicalEnvOk = is_file($canonicalEnvPath) && $canonicalEnvVersion === $version;
$checks['canonical_environment_template'] = $canonicalEnvOk;
if (!$canonicalEnvOk) {
    $shown = $canonicalEnvVersion === null ? 'no definido' : $canonicalEnvVersion;
    $errors[] = 'config/environment.example debe definir APP_VERSION=' . $version . '; valor detectado: ' . $shown . '.';
}

// .env.example se conserva por compatibilidad, pero su ausencia ya no bloquea
// la construcción porque algunos métodos de carga omiten archivos ocultos.
$hiddenEnvPath = $root . '/.env.example';
$hiddenEnvPresent = is_file($hiddenEnvPath);
$hiddenEnvVersion = $hiddenEnvPresent
    ? dotenvValue((string)file_get_contents($hiddenEnvPath), 'APP_VERSION')
    : null;
$hiddenEnvOk = !$hiddenEnvPresent || $hiddenEnvVersion === $version;
$checks['optional_hidden_env_example'] = $hiddenEnvOk;
if ($hiddenEnvPresent && !$hiddenEnvOk) {
    $shown = $hiddenEnvVersion === null ? 'no definido' : $hiddenEnvVersion;
    $errors[] = '.env.example está presente, pero debe definir APP_VERSION=' . $version . '; valor detectado: ' . $shown . '.';
} elseif (!$hiddenEnvPresent) {
    $warnings[] = '.env.example no está presente. Se usa config/environment.example como plantilla oficial versionada.';
}

$readmePath = $root . '/README.md';
$readme = is_file($readmePath) ? (string)file_get_contents($readmePath) : '';
$readmeOk = is_file($readmePath)
    && str_contains($readme, '**Versión actual:** `' . $version . '`')
    && str_contains($readme, '`Actualizaciones.md`');
$checks['readme_current_version'] = $readmeOk;
if (!$readmeOk) {
    $errors[] = 'README.md debe declarar la versión actual ' . $version . ' y referenciar Actualizaciones.md.';
}

$updatesPath = $root . '/Actualizaciones.md';
$updates = is_file($updatesPath) ? (string)file_get_contents($updatesPath) : '';
$updatesOk = is_file($updatesPath)
    && str_contains($updates, '# Actualizaciones de SIVI')
    && str_contains($updates, '## ' . $version)
    && str_contains($updates, 'fuente oficial y única');
$checks['updates_document'] = $updatesOk;
if (!$updatesOk) {
    $errors[] = 'Actualizaciones.md debe existir y contener el historial oficial de la versión ' . $version . '.';
}

$markdownFiles = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'md') {
        continue;
    }
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    $markdownFiles[] = $relative;
}
sort($markdownFiles);

$allowedMarkdown = ['Actualizaciones.md', 'README.md'];
$markdownPolicyOk = $markdownFiles === $allowedMarkdown;
$checks['markdown_documentation_policy'] = $markdownPolicyOk;
if (!$markdownPolicyOk) {
    $errors[] = 'Solo se permiten README.md y Actualizaciones.md. Detectados: '
        . implode(', ', $markdownFiles) . '.';
}

$legacyFiles = [
    'CHANGELOG.md',
    'IDENTIDAD_VISUAL.md',
    'docs/historico',
];
$legacyPresent = [];
foreach ($legacyFiles as $relative) {
    if (file_exists($root . '/' . $relative)) {
        $legacyPresent[] = $relative;
    }
}
$historyRemovedOk = $legacyPresent === [];
$checks['documentation_history_removed'] = $historyRemovedOk;
if (!$historyRemovedOk) {
    $errors[] = 'Debe eliminarse el historial documental: ' . implode(', ', $legacyPresent) . '.';
}

$stage = strtolower((string)($release['stage'] ?? ''));
$major = $validFormat ? (int)explode('.', $version)[0] : -1;
$policyOk = !($stage === 'production' && $major < 1);
$checks['stage_policy'] = $policyOk;
if (!$policyOk) {
    $errors[] = 'Una versión 0.x no puede marcarse como producción.';
}

$result = [
    'ok' => $errors === [],
    'version' => $version,
    'stage' => $stage,
    'checks' => $checks,
    'detected' => [
        'canonical_environment_app_version' => $canonicalEnvVersion,
        'hidden_env_example_present' => $hiddenEnvPresent,
        'hidden_env_example_app_version' => $hiddenEnvVersion,
    ],
    'warnings' => $warnings,
    'errors' => $errors,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 2);
