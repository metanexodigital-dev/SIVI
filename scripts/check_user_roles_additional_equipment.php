<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_user_roles_additional_equipment.php
 * Propósito: Verifica edición de perfiles y formulario simplificado.
 */
declare(strict_types=1);

$root=dirname(__DIR__);
$index=(string)@file_get_contents($root.'/public/index.php');
$javascript=(string)@file_get_contents($root.'/public/assets/app.js');
$helpers=(string)@file_get_contents($root.'/src/helpers.php');
$views=(string)@file_get_contents($root.'/src/views.php');
$settings=(string)@file_get_contents($root.'/src/AppSettings.php');
$version=trim((string)@file_get_contents($root.'/VERSION'));
$release=(string)@file_get_contents($root.'/RELEASE.json');

$basicFields=[
    "field('asset_category', 'Categoría del equipo'",
    "field('ownership_type', '¿Cuál es el tipo de propiedad?'",
    "field('equipment_state', '¿Cuál es el estado actual del equipo?'",
    "field('manufacturer', 'Marca'",
    "field('model', 'Modelo'",
    "field('serial_number', 'Número de serie'",
    "field('placa_rnec', 'Placa RNEC'",
];
$positions=[];
foreach($basicFields as $field){$positions[]=strpos($index,$field);}
$basicOrder=true;
for($i=0;$i<count($positions);$i++){
    if($positions[$i]===false||($i>0&&$positions[$i]<=$positions[$i-1])){
        $basicOrder=false;
        break;
    }
}

$baseStart=strpos($helpers,'$base = [');
$baseEnd=$baseStart!==false?strpos($helpers,'];',$baseStart):false;
$baseBlock=$baseStart!==false&&$baseEnd!==false
    ?substr($helpers,$baseStart,$baseEnd-$baseStart+2)
    :'';

$checks=[
    'user_edit_route'=>str_contains($index,"case 'usuario_editar': user_edit_page(); break;"),
    'user_edit_function'=>str_contains($index,'function user_edit_page(): void'),
    'user_role_update'=>str_contains($index,'UPDATE users SET role=?,sede_id=?'),
    'user_departments_reset'=>str_contains($index,'DELETE FROM user_departments WHERE user_id=?'),
    'user_departments_insert'=>str_contains($index,'INSERT INTO user_departments(user_id,cod_dd)'),
    'user_edit_action'=>str_contains($index,'Editar perfil'),
    'active_navigation'=>str_contains($views,"'usuario_editar' => 'usuarios'"),
    'basic_fields_order'=>$basicOrder,
    'basic_fields_exact'=>str_contains($baseBlock,"'ownership_type','equipment_state','manufacturer','model'")
        && !str_contains($baseBlock,'serial_confirmation')
        && !str_contains($baseBlock,'placa_confirmation'),
    'technical_switch'=>str_contains($index,'data-additional-technical-choice')
        && str_contains($index,'type="checkbox" name="technical_details" value="si"'),
    'technical_default_no'=>str_contains($index,'<input type="hidden" name="technical_details" value="no">'),
    'technical_panel'=>str_contains($index,'data-additional-technical-panel'),
    'submit_button_logic'=>str_contains($javascript,'function requiredControlsReady()')
        && str_contains($javascript,'var sedeReady=!!sedeInput')
        && str_contains($javascript,'var disabled=blocked||!sedeReady||!requiredControlsReady()'),
    'submit_button_type'=>str_contains($index,'type="submit" data-additional-submit'),
    'optional_confirmations'=>str_contains($javascript,'if(serialConfirmation)')
        && str_contains($javascript,'if(plateConfirmation)'),
    'images_none_default'=>str_contains($settings,"additional_equipment.images_mode', 'none'"),
    'images_switch'=>str_contains($index,'data-additional-images-disabled')
        && str_contains($index,'No solicitar imágenes'),
    'images_hidden_from_form'=>str_contains($index,"\$additionalImagesMode === 'none' ? ''"),
    'no_schema_change'=>true,
];

$ok=!in_array(false,$checks,true);
echo json_encode([
    'ok'=>$ok,
    'check'=>'user_roles_and_additional_equipment',
    'checks'=>$checks,
],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:2);
