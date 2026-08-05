<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_glpi_integration.php
 * Propósito: Verifica automáticamente que la funcionalidad «glpi integration» esté presente y sea coherente antes o después del despliegue.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);
$root=dirname(__DIR__);$files=['src/GlpiControlledSync.php','public/index.php','database/schema.sql'];$missing=[];foreach($files as $f){if(!is_file($root.'/'.$f))$missing[]=$f;}
$source=is_file($root.'/src/GlpiControlledSync.php')?(string)file_get_contents($root.'/src/GlpiControlledSync.php'):'';
$ok=$missing===[]&&str_contains($source,'glpi_sync_runs')&&str_contains($source,'glpi_location_mappings')&&str_contains($source,'/api.php/token')&&str_contains($source,'apirest.php/initSession')&&str_contains($source,'isScannerPeripheral');
echo json_encode(['ok'=>$ok,'check'=>'controlled_glpi_read_only_integration','missing'=>$missing,'writes_to_glpi'=>false],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;exit($ok?0:1);
