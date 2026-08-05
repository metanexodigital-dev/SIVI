<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_guided_experience.php
 * Propósito: Verifica automáticamente que la funcionalidad «guided experience» esté presente y sea coherente antes o después del despliegue.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

$root=dirname(__DIR__);
$version=trim((string)@file_get_contents($root.'/VERSION'));
$index=(string)@file_get_contents($root.'/public/index.php');
$views=(string)@file_get_contents($root.'/src/views.php');
$javascript=(string)@file_get_contents($root.'/public/assets/app.js');
$css=(string)@file_get_contents($root.'/public/assets/app.css');
$bootstrap=(string)@file_get_contents($root.'/src/bootstrap.php');
$experience=(string)@file_get_contents($root.'/src/OperationalExperience.php');

$checks=[
    'version_0_0_0_31'=>$version==='1.0.0.0',
    'experience_service'=>is_file($root.'/src/OperationalExperience.php')&&str_contains($experience,'final class OperationalExperience'),
    'experience_loaded'=>str_contains($bootstrap,'OperationalExperience.php'),
    'guided_site_route'=>str_contains($index,'guided_site_work_panel')&&str_contains($index,'Mi ruta de trabajo'),
    'guided_formador_route'=>str_contains($index,'guided_territory_panel')&&str_contains($index,'Seguimiento departamental'),
    'next_action'=>str_contains($experience,"'next_action'")&&str_contains($index,'¿Qué debe hacer ahora?'),
    'pending_center'=>str_contains($index,'Qué requiere atención')&&str_contains($index,'Seriales por registrar'),
    'global_search_route'=>str_contains($index,"case 'buscar_equipo'")&&str_contains($index,'function equipment_search_page'),
    'global_search_scope'=>str_contains($experience,'Scope::sedeCondition')&&str_contains($experience,'additional_equipment'),
    'topbar_search'=>str_contains($views,'topbarGlobalSearch')&&str_contains($views,'data-network-indicator'),
    'keyboard_search'=>str_contains($javascript,'event.ctrlKey||event.metaKey')&&str_contains($javascript,'topbarGlobalSearch'),
    'filter_context'=>str_contains($javascript,'data-persist-filters')&&str_contains($javascript,'Restaurar últimos filtros'),
    'friendly_form_errors'=>str_contains($javascript,'form-error-summary')&&str_contains($javascript,'Revise la información antes de continuar'),
    'guided_closure'=>str_contains($index,'site_closure_panel')&&str_contains($index,'La sede todavía no puede finalizarse'),
    'duplicate_location_message'=>str_contains($index,'ya está registrado en')&&str_contains($index,'activePlateMatches'),
    'mobile_experience'=>str_contains($css,'Experiencia guiada e intuitiva')&&str_contains($css,'@media (max-width:600px)')&&str_contains($css,'data-label'),
];
$errors=[];
foreach($checks as $name=>$ok)if(!$ok)$errors[]='No se cumple el control: '.$name;
$ok=$errors===[];
echo json_encode([
    'ok'=>$ok,
    'version'=>$version,
    'check'=>'guided_user_experience_1.0.0.0',
    'checks'=>$checks,
    'errors'=>$errors,
],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:2);
