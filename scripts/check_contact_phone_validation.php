<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_contact_phone_validation.php
 * Propósito: Verifica que los números de contacto tengan diez dígitos y
 * comiencen por 60 para fijo o por 3 para celular.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$helpers = (string)@file_get_contents($root . '/src/helpers.php');
$controller = (string)@file_get_contents($root . '/public/index.php');
$appJs = (string)@file_get_contents($root . '/public/assets/app.js');
$views = (string)@file_get_contents($root . '/src/views.php');
$serviceWorker = (string)@file_get_contents($root . '/public/sw.js');
$version = trim((string)@file_get_contents($root . '/VERSION'));

$checks = [
    'server_regex' => str_contains($helpers, '/^(?:60[0-9]{8}|3[0-9]{9})$/D'),
    'server_normalizer' => str_contains($helpers, 'function normalize_contact_phone'),
    'server_validator' => str_contains($helpers, 'function valid_contact_phone'),
    'sede_server_validation' => str_contains($controller, 'valid_contact_phone($contactPhone, true)'),
    'campaign_server_validation' => str_contains($controller, 'valid_contact_phone($phone,true)'),
    'contact_inputs_marked' => substr_count($controller, "'data-contact-phone'=>true") >= 2,
    'contact_inputs_numeric' => substr_count($controller, "'inputmode'=>'numeric'") >= 2,
    'contact_inputs_length' => substr_count($controller, "'maxlength'=>'10'") >= 2,
    'contact_inputs_pattern' => substr_count($controller, "'pattern'=>contact_phone_pattern()") >= 2,
    'browser_numeric_filter' => str_contains($appJs, 'replace(/[^0-9]/g,"").slice(0,10)'),
    'browser_prefix_validation' => str_contains($appJs, '/^(?:60[0-9]{8}|3[0-9]{9})$/'),
    'asset_cache_uses_build' => str_contains($views, 'rawurlencode(AppVersion::buildId())'),
    'service_worker_cache' => $version !== '' && str_contains($serviceWorker, 'sivi-static-' . $version),
];

$ok = !in_array(false, $checks, true);

echo json_encode([
    'ok' => $ok,
    'check' => 'contact_phone_validation_10_digits',
    'checks' => $checks,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

exit($ok ? 0 : 2);
