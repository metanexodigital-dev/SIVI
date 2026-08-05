<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_full_plate_entry.php
 * Propósito: Verifica automáticamente que la funcionalidad «full plate entry» esté presente y sea coherente antes o después del despliegue.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);
$root = dirname(__DIR__);
$js = (string) file_get_contents($root . '/public/assets/plate-entry.js');
$app = (string) file_get_contents($root . '/public/assets/app.js');
$policy = (string) file_get_contents($root . '/src/PlatePolicy.php');
$index = (string) file_get_contents($root . '/public/index.php');
$version = trim((string) file_get_contents($root . '/VERSION'));
$checks = [
    'version_actual' => $version === '1.0.0.0',
    'main_plate_selector_supported' => str_contains($js, '[data-sivi-plate-input="1"]') || str_contains($js, '[data-placa-rnec]'),
    'single_text_input' => str_contains($index, 'data-sivi-plate-input="1"') && !str_contains($index, 'inputmode="numeric"'),
    'no_per_keystroke_formatting' => str_contains($js, "const REQUIRED_PREFIX = '000'") && str_contains($js, 'input.setSelectionRange(nextCaret, nextCaret)'),
    'paste_supported' => str_contains($js, "addEventListener('input'"),
    'accepts_with_or_without_hyphen' => str_contains($js, "return REQUIRED_PREFIX + '-'") && str_contains($policy, 'normalizeDigits') && str_contains($policy, 'public static function format'),
    'backend_normalization_kept' => str_contains((string) file_get_contents($root . '/src/PlatePolicy.php'), 'normalizeDigits'),
];
$ok = !in_array(false, $checks, true);
echo json_encode(['ok'=>$ok,'version'=>$version,'check'=>'full_plate_entry_' . $version,'checks'=>$checks], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok ? 0 : 1);
