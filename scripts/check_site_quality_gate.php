<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_site_quality_gate.php
 * Propósito: Verifica automáticamente que la funcionalidad «site quality gate» esté presente y sea coherente antes o después del despliegue.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);
$root=dirname(__DIR__);
$required=['src/SiteQualityGate.php','database/schema.sql','public/index.php'];
$missing=[];foreach($required as $f){if(!is_file($root.'/'.$f))$missing[]=$f;}
$source=is_file($root.'/src/SiteQualityGate.php')?(string)file_get_contents($root.'/src/SiteQualityGate.php'):'';
$ok=$missing===[]&&str_contains($source,'site_quality_runs')&&str_contains($source,'site_quality_findings')&&str_contains($source,'DUPLICATE_PLATE');
echo json_encode(['ok'=>$ok,'check'=>'advanced_site_quality_gate','missing'=>$missing],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
