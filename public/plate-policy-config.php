<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: public/plate-policy-config.php
 * Propósito: Entrega al navegador la configuración vigente de la Placa RNEC.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/SiviRuntimeBridge.php';
require_once dirname(__DIR__) . '/src/PlatePolicy.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

try {
    $pdo = SiviRuntimeBridge::pdo();
    $totalCharacters = PlatePolicy::totalCharacters($pdo);
} catch (Throwable) {
    $totalCharacters = PlatePolicy::defaultTotalCharacters();
}

$digitCount = PlatePolicy::digitCount($totalCharacters);

echo json_encode([
    'ok' => true,
    'total_characters' => $totalCharacters,
    'digit_count' => $digitCount,
    'prefix_digits' => PlatePolicy::PREFIX_DIGITS,
    'hyphen_position' => PlatePolicy::PREFIX_DIGITS + 1,
    'format' => '000-' . str_repeat('0', PlatePolicy::suffixDigits($totalCharacters)),
    'example' => PlatePolicy::example($totalCharacters),
    'message' => sprintf(
        'Escriba o pegue la placa completa en un solo campo. Puede usar %d caracteres con guion o ingresar %d números continuos.',
        $totalCharacters,
        $digitCount
    ),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
