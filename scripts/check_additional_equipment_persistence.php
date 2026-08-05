<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_additional_equipment_persistence.php
 * Propósito: Verifica persistencia, revalidación y notificación de duplicados.
 */
declare(strict_types=1);

$root=dirname(__DIR__);
$index=(string)@file_get_contents($root.'/public/index.php');
$integrity=(string)@file_get_contents($root.'/src/AdditionalEquipmentIntegrity.php');
$javascript=(string)@file_get_contents($root.'/public/assets/app.js');
$entrypoint=(string)@file_get_contents($root.'/docker/entrypoint.sh');
$version=trim((string)@file_get_contents($root.'/VERSION'));
$release=(string)@file_get_contents($root.'/RELEASE.json');

$firstCheck=strpos($index,'Primera validación:');
$lockCheck=strpos($index,'SELECT GET_LOCK(?,10) acquired');
$secondCheck=strpos($index,'Segunda validación protegida');
$insertPos=strpos($index,'INSERT INTO additional_equipment');
$verifyPos=strpos($index,'El equipo no pudo verificarse después del guardado');

$checks=[
    'lock_name_method'=>str_contains($integrity,'public static function lockName'),
    'first_duplicate_check'=>$firstCheck!==false,
    'mariadb_lock'=>$lockCheck!==false,
    'second_duplicate_check'=>$secondCheck!==false,
    'recheck_before_insert'=>$secondCheck!==false
        && $insertPos!==false
        && $secondCheck<$insertPos,
    'insert_additional_equipment'=>$insertPos!==false,
    'last_insert_id'=>str_contains($index,'$pdo->lastInsertId()'),
    'saved_record_verified'=>str_contains(
        $index,
        'FROM additional_equipment ae '
    ) && $verifyPos!==false,
    'transaction_commit'=>str_contains($index,'$pdo->commit();'),
    'rollback_on_error'=>str_contains(
        $index,
        'if ($pdo->inTransaction())'
    ) && str_contains($index,'$pdo->rollBack();'),
    'lock_released'=>str_contains($index,'SELECT RELEASE_LOCK(?) released'),
    'duplicate_location'=>str_contains($index,'El equipo ya está registrado en')
        && str_contains($index,"'municipio'")
        && str_contains($index,"'departamento'"),
    'duplicate_record_id'=>str_contains($index,'con registro #'),
    'duplicate_audit'=>str_contains($index,'duplicate_additional_equipment_blocked'),
    'creation_audit'=>str_contains($index,"'persistence_verified'=>true"),
    'success_notification'=>str_contains(
        $index,
        'El equipo adicional fue registrado correctamente con el número #'
    ),
    'browser_available_message'=>str_contains(
        $javascript,
        'Equipo no registrado previamente'
    ),
    'browser_duplicate_record'=>str_contains(
        $javascript,
        '<b>Registro:</b> #'
    ),
    'no_schema_change'=>!str_contains($entrypoint,'scripts/migrate.php'),
];

$ok=!in_array(false,$checks,true);
echo json_encode([
    'ok'=>$ok,
    'check'=>'additional_equipment_persistence',
    'checks'=>$checks,
],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:2);
