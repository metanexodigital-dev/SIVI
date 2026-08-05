<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_campaign_operation.php
 * Propósito: Verifica automáticamente que la funcionalidad «campaign operation» esté presente y sea coherente antes o después del despliegue.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

$root=dirname(__DIR__);
$controller=(string)@file_get_contents($root.'/public/index.php');
$schema=(string)@file_get_contents($root.'/database/schema.sql');
$javascript=(string)@file_get_contents($root.'/public/assets/app.js');
$database=(string)@file_get_contents($root.'/src/Database.php');
$stylesheet=(string)@file_get_contents($root.'/public/assets/app.css');
$serviceWorker=(string)@file_get_contents($root.'/public/sw.js');
$checks=[
    'version_0_0_0_31'=>trim((string)@file_get_contents($root.'/VERSION'))==='1.0.0.0',
    'campaign_equipment_snapshot'=>str_contains($schema,'CREATE TABLE IF NOT EXISTS campaign_equipment')&&str_contains($controller,'campaign_equipment_exists'),
    'campaign_status_lifecycle'=>str_contains($schema,"'programada','activa','cerrada','en_revision','finalizada','cancelada'")&&str_contains($controller,'refresh_campaign_states'),
    'territorial_campaign_wizard'=>str_contains($controller,'data-campaign-wizard')&&str_contains($controller,'scope_mode')&&str_contains($javascript,'[data-campaign-wizard]'),
    'campaign_progressive_enhancement'=>!str_contains($controller,'data-campaign-step="2" hidden')&&str_contains($javascript,'campaign-wizard-enhanced')&&str_contains($stylesheet,':not(.campaign-wizard-enhanced)'),
    'campaign_visible_validation'=>str_contains($controller,'data-campaign-error')&&str_contains($javascript,'showCampaignError'),
    'service_worker_network_first'=>str_contains($serviceWorker,"fetch(request, {cache: 'no-store'})")&&!str_contains($serviceWorker,'cached || network'),
    'overlap_prevention'=>str_contains($controller,'campaign_overlap_summary')&&str_contains($controller,'allow_overlap'),
    'registrar_panel'=>str_contains($controller,'registrar_campaign_panel')&&str_contains($controller,'registrar-campaign-hero'),
    'explicit_office_membership'=>str_contains($controller,'name="belongs_status"')&&str_contains($controller,'belongs_reason')&&str_contains($javascript,'belongsInputs'),
    'server_validation_drafts'=>str_contains($schema,'CREATE TABLE IF NOT EXISTS validation_drafts')&&str_contains($controller,'function validation_draft_page')&&str_contains($javascript,'saveDraftServer'),
    'controlled_site_closure'=>str_contains($controller,'closure_acceptance')&&str_contains($controller,'OperationalExperience::clearResolvedDrafts')&&str_contains($controller,"if(empty(\$closureState['ready_to_close']))"),
    'campaign_evidence_rule'=>str_contains($controller,'campaignRequiresEvidence')&&str_contains($controller,"evidence_type='general'"),
    'schema_runtime_checks'=>str_contains($database,"'campaign_equipment', 'validation_drafts'")&&str_contains($database,"'campaigns.scope_json'"),
];
$ok=!in_array(false,$checks,true);
echo json_encode([
    'ok'=>$ok,
    'version'=>trim((string)@file_get_contents($root.'/VERSION')),
    'check'=>'campaigns_and_territorial_operation_1.0.0.0',
    'checks'=>$checks,
],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:2);
