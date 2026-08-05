<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_additional_identity_timeout.php
 * Propósito: Verifica el tiempo máximo de la comprobación previa.
 */
declare(strict_types=1);

$root=dirname(__DIR__);
$index=(string)@file_get_contents($root.'/public/index.php');
$javascript=(string)@file_get_contents($root.'/public/assets/app.js');
$integrity=(string)@file_get_contents($root.'/src/AdditionalEquipmentIntegrity.php');
$version=trim((string)@file_get_contents($root.'/VERSION'));
$release=(string)@file_get_contents($root.'/RELEASE.json');

$checks=[
    'client_timeout'=>str_contains($javascript,'identityTimeoutMs=3000')
        && str_contains($javascript,'setTimeout(function()'),
    'timeout_renders_fallback'=>str_contains(
        $javascript,
        'controller.abort();'
    ) && str_contains(
        $javascript,
        'renderError();'
    ),
    'stale_responses_ignored'=>str_contains(
        $javascript,
        'requestSequence!==identityRequestSequence'
    ),
    'request_cleanup'=>str_contains(
        $javascript,
        '.finally(function()'
    ) && str_contains(
        $javascript,
        'clearTimeout(timeoutId)'
    ),
    'no_store_fetch'=>str_contains($javascript,'cache:"no-store"'),
    'session_released'=>str_contains(
        $index,
        'session_write_close();'
    ),
    'endpoint_no_cache'=>str_contains(
        $index,
        'Cache-Control: no-store'
    ),
    'fast_check_used'=>str_contains(
        $index,
        'AdditionalEquipmentIntegrity::checkFast'
    ),
    'fast_check_exists'=>str_contains(
        $integrity,
        'public static function checkFast'
    ),
    'indexed_equipment_lookup'=>str_contains(
        $integrity,
        'e.serial_number IN ('
    ) && str_contains(
        $integrity,
        'e.placa_rnec IN ('
    ),
    'quick_additional_lookup'=>str_contains(
        $integrity,
        'ae.serial_number IN ('
    ) && str_contains(
        $integrity,
        'ae.placa_rnec IN ('
    ),
    'full_server_check_preserved'=>substr_count(
        $index,
        'AdditionalEquipmentIntegrity::check('
    )>=2,
    'popup_preserved'=>str_contains(
        $index,
        'additionalIdentityConflictModal'
    ),
    'no_migrations'=>!str_contains(
        (string)@file_get_contents($root.'/docker/entrypoint.sh'),
        'scripts/migrate.php'
    ),
];

$ok=!in_array(false,$checks,true);

echo json_encode([
    'ok'=>$ok,
    'check'=>'additional_identity_timeout',
    'checks'=>$checks,
],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;

exit($ok?0:2);
