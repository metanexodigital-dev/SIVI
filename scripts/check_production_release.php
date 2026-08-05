<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_production_release.php
 * Propósito: Impide construir una publicación 1.0.0.0 cuando los metadatos,
 * manifiestos o plantillas todavía conservan valores del ambiente de pruebas.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$version = trim((string)@file_get_contents($root . '/VERSION'));
$release = json_decode((string)@file_get_contents($root . '/RELEASE.json'), true);
$manifest = json_decode((string)@file_get_contents($root . '/public/manifest.webmanifest'), true);
$brandManifest = json_decode((string)@file_get_contents($root . '/public/assets/brand/pwa/manifest.webmanifest'), true);
$environment = (string)@file_get_contents($root . '/config/environment.example');
$compose = (string)@file_get_contents($root . '/docker-compose.yml');
$readme = (string)@file_get_contents($root . '/README.md');

$checks = [
    'version' => $version === '1.0.0.0',
    'release_stage' => ($release['stage'] ?? '') === 'production',
    'release_status' => ($release['status'] ?? '') === 'production',
    // La versión funcional permanece en 1.0.0.0; la siguiente actualización compatible será 1.0.0.1.
    'release_build' => (string)($release['build_id'] ?? '') === 'SIVI-1.0.0.0',
    'manifest_version' => ($manifest['version'] ?? '') === $version,
    'manifest_environment' => ($manifest['environment'] ?? '') === 'production',
    'brand_manifest_version' => ($brandManifest['version'] ?? '') === $version,
    'brand_manifest_environment' => ($brandManifest['environment'] ?? '') === 'production',
    'environment_app_env' => str_contains($environment, 'APP_ENV=production'),
    'environment_release_channel' => str_contains($environment, 'APP_RELEASE_CHANNEL=production'),
    'environment_setup_protected' => str_contains($environment, 'SETUP_REQUIRE_KEY=true'),
    'environment_csp_enforced' => str_contains($environment, 'CSP_REPORT_ONLY=false'),
    'compose_production_default' => str_contains($compose, '${APP_ENV:-production}'),
    'compose_migrations_disabled' => str_contains($compose, '${AUTO_MIGRATE:-false}')
        || substr_count($compose, 'AUTO_MIGRATE: "false"') >= 2,
    'plate_entry_current' => is_file($root . '/public/assets/plate-entry.js')
        && !is_file($root . '/public/assets/plate-ux.js'),
    'readme_version' => str_contains($readme, '**Versión actual:** `1.0.0.0`'),
    'readme_channel' => str_contains($readme, '**Canal:** producción'),
];

$ok = !in_array(false, $checks, true);
echo json_encode([
    'ok' => $ok,
    'version' => $version,
    'check' => 'production_release_1.0.0.0',
    'checks' => $checks,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 2);
