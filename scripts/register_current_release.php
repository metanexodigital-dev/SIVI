<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/register_current_release.php
 * Propósito: Registra la versión desplegada en una base existente sin ejecutar
 * migraciones ni modificar la estructura de datos.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$release = json_decode(
    (string)@file_get_contents(dirname(__DIR__) . '/RELEASE.json'),
    true
);
$notes = is_array($release) ? (string)($release['release_notes'] ?? '') : '';

$current = AppVersion::registerDeployment(null, $notes);

echo json_encode([
    'ok' => true,
    'version' => AppVersion::package(),
    'build_id' => AppVersion::buildId(),
    'release_key' => $current['release_key'] ?? null,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
