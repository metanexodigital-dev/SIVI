<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_additional_equipment_form.php
 * Propósito: Verifica automáticamente que la funcionalidad «additional equipment form» esté presente y sea coherente antes o después del despliegue.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);
$root=dirname(__DIR__);
$index=(string)@file_get_contents($root.'/public/index.php');
$js=(string)@file_get_contents($root.'/public/assets/app.js');
$helpers=(string)@file_get_contents($root.'/src/helpers.php');
$schema=(string)@file_get_contents($root.'/database/schema.sql');
$version=trim((string)@file_get_contents($root.'/VERSION'));
$requiredBlock="\$required = ['ownership_type','equipment_state','serial_number','placa_rnec','technical_details'];";
$checks=[
    'version_0_0_0_34'=>$version==='1.0.0.0',
    'category_required'=>str_contains($index,"field('asset_category', 'Categoría del equipo'") && str_contains($index,"['required'=>true"),
    'ownership_required'=>str_contains($index,"field('ownership_type', '¿Cuál es el tipo de propiedad?'") && str_contains($index,"'required'=>true,'choices'=>additional_equipment_ownership_choices()"),
    'state_required'=>str_contains($index,"field('equipment_state', '¿Cuál es el estado actual del equipo?'") && str_contains($index,"'required'=>true,'choices'=>additional_equipment_state_choices()"),
    'serial_required'=>str_contains($index,"field('serial_number', 'Número de serie'") && str_contains($index,"'required'=>true,'help'=>'Escríbalo exactamente"),
    'plate_required_for_all'=>str_contains($index,"field('placa_rnec', 'Placa RNEC'")
        && str_contains($index,"['required'=>true,'placeholder'=>'Inicie con 000; el guion se agrega automáticamente'")
        && str_contains($js,'plate.required=!!rule;'),
    'serial_confirmation_optional'=>str_contains($index, "if (\$serialConfirmation !== ''"),
    'plate_confirmation_optional'=>str_contains($index,'if ($plateConfirmation !== null'),
    'required_basic_fields'=>str_contains($helpers,$requiredBlock),
    'technical_fields_conditional'=>str_contains($index,'technical_details')
        && str_contains($js,'technicalEnabled=!!technicalChoice.checked')
        && str_contains($helpers,'technical_required'),
    'category_rules'=>str_contains($helpers,'additional_equipment_category_rules') && str_contains($js,'applyCategory'),
    'only_selected_category_fields'=>str_contains($js,'var show=key==="asset_category"||!!rule&&visible.indexOf(key)>=0') && !str_contains($js,'var common=['),
    'initial_category_only'=>str_contains((string)@file_get_contents($root.'/public/assets/app.css'), 'not([data-category-ready="1"])'),
    'clear_previous_category_fields'=>str_contains($js,'previousCategory!==category.value'),
    'brand_model_catalog'=>str_contains($index,'additional_catalog_page') && str_contains($index,'data-catalog-url') && str_contains($js,'loadModels'),
    'server_duplicate_validation'=>str_contains($index,'AdditionalEquipmentIntegrity::check'),
    'technical_catalogs'=>str_contains($helpers,'additional_equipment_technical_catalogs') && str_contains($index,'data-technical-catalogs'),
    'images_admin_setting'=>str_contains($index,'additional_equipment.images_mode') && str_contains($index,'additional_equipment_disable_images') && str_contains((string)@file_get_contents($root.'/src/AppSettings.php'),'additional_equipment.images_mode'),
    'ownership_schema'=>str_contains($schema,'ALTER TABLE additional_equipment ADD COLUMN IF NOT EXISTS ownership_type'),
];
$ok=!in_array(false,$checks,true);
echo json_encode(['ok'=>$ok,'version'=>$version,'check'=>'additional_equipment_optional_details_1.0.0.0','checks'=>$checks],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
exit($ok?0:2);
