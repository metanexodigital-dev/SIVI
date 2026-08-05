<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_guided_ux.php
 * Propósito: Verifica automáticamente que la funcionalidad «guided ux» esté presente y sea coherente antes o después del despliegue.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/PlatePolicy.php';

$required = [
    'src/PlatePolicy.php',
    'src/OnboardingService.php',
    'public/plate-policy-config.php',
    'public/admin-plate-policy.php',
    'public/onboarding-status.php',
    'public/assets/plate-ux.css',
    'public/assets/plate-entry.js',
    'public/assets/sivi-onboarding.css',
    'public/assets/sivi-onboarding.js',
    'database/schema.sql',
];

$files = [];
foreach ($required as $file) {
    $files[$file] = is_file($root . '/' . $file);
}

$samples = [];
foreach (['000-12345', '00012345', '000 12345', '000-1234', 'ABC-12345'] as $sample) {
    $samples[$sample] = PlatePolicy::validate($sample, 9, true);
}

$schema=(string)@file_get_contents($root.'/database/schema.sql');

$logic = [
    'keeps_hyphen' => $samples['000-12345']['ok'] && $samples['000-12345']['value'] === '000-12345',
    'auto_formats_digits' => $samples['00012345']['ok'] && $samples['00012345']['value'] === '000-12345',
    'auto_formats_spaces' => $samples['000 12345']['ok'] && $samples['000 12345']['value'] === '000-12345',
    'rejects_wrong_length' => !$samples['000-1234']['ok'],
    'rejects_nonmatching_digit_count' => !$samples['ABC-12345']['ok'],
    'default_example' => PlatePolicy::example(9) === '000-12345',
    'baseline_contains_onboarding' => str_contains($schema, 'sivi_user_onboarding'),
    'baseline_contains_runtime_settings' => str_contains($schema, 'sivi_runtime_settings'),
];

$ok = !in_array(false, $files, true) && !in_array(false, $logic, true);
echo json_encode([
    'ok' => $ok,
    'version' => '1.0.0.0',
    'check' => 'guided_onboarding_and_plate_format',
    'files' => $files,
    'logic' => $logic,
    'samples' => $samples,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
