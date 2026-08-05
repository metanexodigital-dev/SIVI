<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_site_closure_consistency.php
 * Propósito: Verifica automáticamente que la funcionalidad «site closure consistency» esté presente y sea coherente antes o después del despliegue.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);
$root=dirname(__DIR__);
$index=(string)@file_get_contents($root.'/public/index.php');
$experience=(string)@file_get_contents($root.'/src/OperationalExperience.php');
$version=trim((string)@file_get_contents($root.'/VERSION'));
$checks=[
    'version_0_0_0_34'=>$version==='1.0.0.0',
    'unresolved_draft_count'=>str_contains($experience,'public static function unresolvedDraftCount'),
    'resolved_draft_cleanup'=>str_contains($experience,'public static function clearResolvedDrafts'),
    'same_closure_rules'=>str_contains($index,'OperationalExperience::siteState($campaignId,$sedeId')
        && str_contains($index,"if(empty(\$closureState['ready_to_close']))"),
    'delete_drafts_by_sede'=>str_contains($index,"DELETE FROM validation_drafts WHERE campaign_id=? AND equipment_id=? AND sede_id=?"),
    'closure_reason_detail'=>str_contains($index,"borrador(es) sin confirmar") && str_contains($index,"corrección(es) pendiente(s)"),
];
$ok=!in_array(false,$checks,true);
echo json_encode(['ok'=>$ok,'version'=>$version,'check'=>'site_closure_consistency_1.0.0.0','checks'=>$checks],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
exit($ok?0:2);
