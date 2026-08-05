<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_environment.php
 * Propósito: Verifica automáticamente que la funcionalidad «environment» esté presente y sea coherente antes o después del despliegue.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Env.php';
require_once dirname(__DIR__) . '/src/SetupPolicy.php';
Env::load(dirname(__DIR__) . '/.env');

$required = [
    'APP_VERSION', 'APP_ENV',
    'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
];

$checks = [];
$ok = true;
foreach ($required as $key) {
    $value = (string)Env::get($key, '');
    $present = $value !== '';
    $checks[$key] = [
        'present' => $present,
        'length' => $present ? strlen($value) : 0,
        'source' => Env::source($key),
    ];
    $ok = $ok && $present;
}

$checks['SETUP_REQUIRE_KEY'] = [
    'configured_value' => Env::get('SETUP_REQUIRE_KEY', ''),
    'effective_required' => SetupPolicy::requiresKey(),
    'environment' => SetupPolicy::environment(),
    'mode' => SetupPolicy::modeLabel(),
];

$setupKey = (string)Env::get('APP_SETUP_KEY', '');
$checks['APP_SETUP_KEY'] = [
    'present' => $setupKey !== '',
    'length' => strlen($setupKey),
    'source' => Env::source('APP_SETUP_KEY'),
    'fingerprint' => $setupKey === '' ? null : substr(hash('sha256', $setupKey), 0, 12),
    'expected_format' => preg_match('/^[A-Fa-f0-9]{64}$/', $setupKey) === 1 ? 'hex64' : 'custom',
    'required' => SetupPolicy::requiresKey(),
];
if (SetupPolicy::requiresKey() && $setupKey === '') {
    $ok = false;
}

$result = [
    'ok' => $ok,
    'version' => Env::get('APP_VERSION'),
    'environment' => Env::get('APP_ENV'),
    'checks' => $checks,
    'note' => 'No se muestran valores secretos; solo presencia, longitud, fuente y huella parcial.',
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 2);
