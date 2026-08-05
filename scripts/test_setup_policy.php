<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/test_setup_policy.php
 * Propósito: Ejecuta pruebas técnicas sobre «setup policy» y muestra un resultado verificable.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Env.php';
require_once dirname(__DIR__) . '/src/SetupPolicy.php';

$original = [
    'APP_ENV' => getenv('APP_ENV'),
    'SETUP_REQUIRE_KEY' => getenv('SETUP_REQUIRE_KEY'),
    'APP_SETUP_KEY' => getenv('APP_SETUP_KEY'),
];

$checks = [];

putenv('APP_ENV=testing');
putenv('SETUP_REQUIRE_KEY=false');
putenv('APP_SETUP_KEY=');
$checks['testing_without_key'] = !SetupPolicy::requiresKey() && SetupPolicy::validate('cualquier-valor');

putenv('APP_ENV=testing');
putenv('SETUP_REQUIRE_KEY=true');
putenv('APP_SETUP_KEY=abc123');
$checks['testing_with_key_valid'] = SetupPolicy::requiresKey() && SetupPolicy::validate('abc123');
$checks['testing_with_key_invalid'] = !SetupPolicy::validate('incorrecta');

putenv('APP_ENV=production');
putenv('SETUP_REQUIRE_KEY=false');
putenv('APP_SETUP_KEY=prod-secret');
$checks['production_forces_key'] = SetupPolicy::requiresKey();
$checks['production_rejects_invalid'] = !SetupPolicy::validate('incorrecta');
$checks['production_accepts_valid'] = SetupPolicy::validate('prod-secret');

foreach ($original as $key => $value) {
    if ($value === false) {
        putenv($key);
    } else {
        putenv($key . '=' . $value);
    }
}

$ok = !in_array(false, $checks, true);
echo json_encode([
    'ok' => $ok,
    'version' => trim((string)@file_get_contents(dirname(__DIR__) . '/VERSION')),
    'checks' => $checks,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 2);
