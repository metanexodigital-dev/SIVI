<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_schema.php
 * Propósito: Verifica automáticamente que la funcionalidad «schema» esté presente y sea coherente antes o después del despliegue.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

try {
    $status = Database::schemaStatus();
    echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($status['ok'] ? 0 : 2);
} catch (Throwable $e) {
    fwrite(STDERR, "No fue posible verificar el esquema: {$e->getMessage()}\n");
    exit(1);
}
