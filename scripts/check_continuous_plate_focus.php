<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_continuous_plate_focus.php
 * Propósito: Verifica automáticamente que la funcionalidad «continuous plate focus» esté presente y sea coherente antes o después del despliegue.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$errors = [];

$index = file_get_contents($root . '/public/index.php') ?: '';
$app = file_get_contents($root . '/public/assets/app.js') ?: '';
$views = file_get_contents($root . '/src/views.php') ?: '';
$entry = file_get_contents($root . '/public/assets/plate-entry.js') ?: '';
$policy = file_get_contents($root . '/src/PlatePolicy.php') ?: '';

$mustContain = [
    [$views, 'assets/plate-entry.js', 'views.php debe cargar plate-entry.js'],
    [$entry, "const REQUIRED_PREFIX = '000'", 'plate-entry.js debe definir el prefijo 000'],
    [$entry, "return REQUIRED_PREFIX + '-'", 'plate-entry.js debe insertar el guion automáticamente'],
    [$entry, 'input.setSelectionRange(nextCaret, nextCaret)', 'plate-entry.js debe conservar el cursor'],
    [$entry, 'La Placa RNEC debe iniciar con 000 antes del guion.', 'plate-entry.js debe mostrar la validación del prefijo'],
    [$app, 'digits.indexOf("000")===0', 'app.js debe exigir el prefijo 000 al completar la placa'],
    [$index, 'placeholder="Inicie con 000; el guion se agrega automáticamente"', 'Validación de inventario debe explicar el formato'],
    [$index, "'placeholder'=>'Inicie con 000; el guion se agrega automáticamente'", 'Equipo adicional debe explicar el formato'],
    [$policy, "public const REQUIRED_PREFIX = '000';", 'El servidor debe definir el prefijo obligatorio'],
    [$policy, 'La Placa RNEC debe iniciar con 000 antes del guion.', 'El servidor debe rechazar prefijos diferentes'],
];

foreach ($mustContain as [$haystack, $needle, $message]) {
    if (!str_contains($haystack, $needle)) $errors[] = $message;
}

$mustNotContain = [
    [$views, 'assets/plate-ux.js', 'views.php no debe cargar plate-ux.js'],
    [$entry, 'input.replaceWith', 'plate-entry.js no debe reemplazar el campo'],
    [$entry, '.focus()', 'plate-entry.js no debe forzar cambios de foco'],
];

foreach ($mustNotContain as [$haystack, $needle, $message]) {
    if (str_contains($haystack, $needle)) $errors[] = $message;
}

if ($errors) {
    fwrite(STDERR, "FALLO\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "OK: escritura continua, guion automático y prefijo 000 configurados sin reemplazar el campo.\n";
