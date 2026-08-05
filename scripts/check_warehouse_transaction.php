<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_warehouse_transaction.php
 * Propósito: Verifica que la importación de Almacén confirme la carga base
 * antes de la conciliación y no ejecute commit() sin transacción activa.
 */
declare(strict_types=1);

$root=dirname(__DIR__);
$source=(string)@file_get_contents($root.'/src/WarehouseImporter.php');
$version=trim((string)@file_get_contents($root.'/VERSION'));
$release=(string)@file_get_contents($root.'/RELEASE.json');

$beginPos=strpos($source,'$pdo->beginTransaction();');
$verifyPos=strpos($source,'SELECT COUNT(*) total FROM warehouse_assets WHERE import_id=?');
$guardPos=strpos($source,'if ($pdo->inTransaction())');
$sedesPos=strpos($source,"\$sedes = Database::fetchAll('SELECT id,identificador");

$checks=[
    'transaction_starts'=>$beginPos!==false,
    'stored_assets_verified'=>$verifyPos!==false,
    'commit_guarded'=>$guardPos!==false,
    'verification_before_reconciliation'=>$verifyPos!==false
        && $sedesPos!==false
        && $verifyPos<$sedesPos,
    'single_commit_call'=>substr_count($source,'$pdo->commit();')===1,
    'result_contains_stored_assets'=>str_contains($source,"compact('importId', 'rows', 'storedAssets'"),
    'rollback_is_guarded'=>str_contains($source,'if ($pdo->inTransaction()) $pdo->rollBack();'),
    'no_migrations'=>!str_contains(
        (string)@file_get_contents($root.'/docker/entrypoint.sh'),
        'scripts/migrate.php'
    ),
];

$ok=!in_array(false,$checks,true);
echo json_encode([
    'ok'=>$ok,
    'check'=>'warehouse_transaction_control',
    'checks'=>$checks,
],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
exit($ok?0:2);
