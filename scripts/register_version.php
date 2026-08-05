<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/register_version.php
 * Propósito: Ejecuta la tarea técnica «register version» para operación, validación o mantenimiento de SIVI.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

try {
    $notes = trim((string)($argv[1] ?? 'Registro por consola'));
    $release = AppVersion::registerDeployment(null, $notes !== '' ? $notes : null);
    echo "SIVI " . AppVersion::package() . " registrado para " . AppVersion::environmentLabel() . ".\n";
    echo json_encode($release, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "No fue posible registrar la versión: {$e->getMessage()}\n");
    exit(1);
}
