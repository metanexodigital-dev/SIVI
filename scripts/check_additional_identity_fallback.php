<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_additional_identity_fallback.php
 * Propósito: Verifica que una falla temporal de comprobación no bloquee el
 * botón de registro y que el servidor conserve la validación final.
 */
declare(strict_types=1);

$root=dirname(__DIR__);
$javascript=(string)@file_get_contents($root.'/public/assets/app.js');
$index=(string)@file_get_contents($root.'/public/index.php');
$integrity=(string)@file_get_contents($root.'/src/AdditionalEquipmentIntegrity.php');
$version=trim((string)@file_get_contents($root.'/VERSION'));
$release=(string)@file_get_contents($root.'/RELEASE.json');

$message='No fue posible comprobar el serial y la placa en este momento. El servidor volverá a validarlos al registrar.';

$checks=[
    'exact_message'=>str_contains($javascript,$message),
    'unavailable_state'=>str_contains(
        $javascript,
        'identityCheckUnavailable=true'
    ),
    'temporary_error_unblocks'=>str_contains(
        $javascript,
        'function renderError()'
    ) && str_contains(
        $javascript,
        'setBlocked(false);'
    ),
    'button_uses_required_controls'=>str_contains(
        $javascript,
        'function requiredControlsReady()'
    ),
    'button_requires_sede'=>str_contains(
        $javascript,
        'var sedeReady=!!sedeInput'
    ),
    'button_not_tied_to_precheck'=>!str_contains(
        $javascript,
        'var disabled=blocked||identityCheckUnavailable'
    ),
    'sede_change_dispatches_events'=>str_contains(
        $javascript,
        'input.dispatchEvent(new Event("change",{bubbles:true}))'
    ),
    'form_change_refreshes_button'=>str_contains(
        $javascript,
        'form.addEventListener("change",updateSubmitState)'
    ),
    'real_conflict_still_blocks'=>str_contains(
        $javascript,
        'setBlocked(true);'
    ),
    'server_first_check'=>str_contains(
        $index,
        'Primera validación:'
    ),
    'server_second_check'=>str_contains(
        $index,
        'Segunda validación protegida'
    ),
    'database_lock'=>str_contains(
        $integrity,
        'public static function lockName'
    ) && str_contains(
        $index,
        'SELECT GET_LOCK(?,10) acquired'
    ),
    'no_migrations'=>!str_contains(
        (string)@file_get_contents($root.'/docker/entrypoint.sh'),
        'scripts/migrate.php'
    ),
];

$ok=!in_array(false,$checks,true);

echo json_encode([
    'ok'=>$ok,
    'check'=>'additional_identity_fallback',
    'checks'=>$checks,
],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;

exit($ok?0:2);
