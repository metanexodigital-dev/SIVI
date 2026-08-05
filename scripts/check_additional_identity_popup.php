<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_additional_identity_popup.php
 * Propósito: Verifica el popup automático de ubicación ante coincidencias.
 */
declare(strict_types=1);

$root=dirname(__DIR__);
$index=(string)@file_get_contents($root.'/public/index.php');
$javascript=(string)@file_get_contents($root.'/public/assets/app.js');
$css=(string)@file_get_contents($root.'/public/assets/app.css');
$integrity=(string)@file_get_contents($root.'/src/AdditionalEquipmentIntegrity.php');
$version=trim((string)@file_get_contents($root.'/VERSION'));
$release=(string)@file_get_contents($root.'/RELEASE.json');

$checks=[
    'modal_markup'=>str_contains($index,'id="additionalIdentityConflictModal"')
        && str_contains($index,'data-additional-conflict-modal'),
    'modal_title'=>str_contains($index,'Equipo ya registrado'),
    'modal_body'=>str_contains($index,'data-additional-conflict-modal-body'),
    'auto_show'=>str_contains($javascript,'dataset.autoShow==="1"'),
    'bootstrap_modal'=>str_contains($javascript,'bootstrap.Modal.getOrCreateInstance'),
    'dynamic_open'=>str_contains($javascript,'showConflictModal(conflictHtml)'),
    'reopen_button'=>str_contains($index,'Ver ubicación del equipo')
        && str_contains($javascript,'data-additional-conflict-open'),
    'location'=>str_contains($javascript,'<b>Sede:</b>')
        && str_contains($javascript,'item.municipio')
        && str_contains($javascript,'item.departamento'),
    'identifiers'=>str_contains($javascript,'<b>Registro:</b> #')
        && str_contains($javascript,'<b>Placa:</b>')
        && str_contains($javascript,'<b>Serial:</b>'),
    'campaign'=>str_contains($javascript,'<b>Campaña:</b>'),
    'view_link'=>str_contains($javascript,'Ver equipo registrado'),
    'blocks_registration'=>str_contains($javascript,'setBlocked(true);'),
    'modal_styles'=>str_contains($css,'.additional-conflict-modal-content'),
    'server_revalidation'=>str_contains($index,'Segunda validación protegida'),
    'database_lock'=>str_contains($integrity,'public static function lockName'),
    'no_migrations'=>!str_contains(
        (string)@file_get_contents($root.'/docker/entrypoint.sh'),
        'scripts/migrate.php'
    ),
];

$ok=!in_array(false,$checks,true);

echo json_encode([
    'ok'=>$ok,
    'check'=>'additional_identity_popup',
    'checks'=>$checks,
],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;

exit($ok?0:2);
