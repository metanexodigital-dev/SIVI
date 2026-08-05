<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: public/ping.php
 * Propósito: Entrega una respuesta mínima para comprobar conectividad con la aplicación.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
http_response_code(200);
echo json_encode([
    'status' => 'ok',
    'application' => (string)Env::get('APP_NAME', 'SIVI'),
    'version' => AppVersion::package(),
    'environment' => AppVersion::environment(),
    'build_id' => AppVersion::buildId(),
    'php' => PHP_VERSION,
    'time' => date(DATE_ATOM),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
