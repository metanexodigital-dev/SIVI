<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_plate_prefix_000.php
 * Propósito: Verifica automáticamente que la funcionalidad «plate prefix 000» esté presente y sea coherente antes o después del despliegue.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/PlatePolicy.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$total = 9;
$assert(PlatePolicy::format('000', $total) === '000-', 'Debe insertar el guion después de 000.');
$assert(PlatePolicy::format('0001', $total) === '000-1', 'Debe conservar el cuarto número después del guion.');
$assert(PlatePolicy::format('00012345', $total) === '000-12345', 'Debe formatear la placa completa sin guion.');
$assert(PlatePolicy::format('12345678', $total) === '12345678', 'No debe insertar guion si el prefijo no es 000.');

$valid = PlatePolicy::validate('00012345', $total, true);
$assert($valid['ok'] === true, '00012345 debe ser válida.');
$assert($valid['value'] === '000-12345', 'La placa válida debe normalizarse a 000-12345.');

$invalidPrefix = PlatePolicy::validate('12345678', $total, true);
$assert($invalidPrefix['ok'] === false, 'Una placa con prefijo distinto de 000 debe ser inválida.');
$assert(str_contains((string) $invalidPrefix['message'], 'iniciar con 000'), 'Debe explicar que el prefijo 000 es obligatorio.');

$invalidLength = PlatePolicy::validate('00012', $total, true);
$assert($invalidLength['ok'] === false, 'Una placa incompleta debe ser inválida al guardar.');

if ($failures !== []) {
    fwrite(STDERR, "FALLÓ\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK: guion automático y prefijo 000 validados.\n";
