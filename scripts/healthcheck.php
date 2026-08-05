<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/healthcheck.php
 * Propósito: Ejecuta la tarea técnica «healthcheck» para operación, validación o mantenimiento de SIVI.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

$context = stream_context_create([
    'http' => [
        'timeout' => 4,
        'ignore_errors' => true,
    ],
]);

$body = @file_get_contents('http://127.0.0.1/health.php', false, $context);
if (!is_string($body) || $body === '') {
    exit(1);
}

$data = json_decode($body, true);
exit(is_array($data) && ($data['status'] ?? null) === 'ok' ? 0 : 1);
