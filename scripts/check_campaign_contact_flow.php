<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_campaign_contact_flow.php
 * Propósito: Verifica automáticamente que la funcionalidad «campaign contact flow» esté presente y sea coherente antes o después del despliegue.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

$root=dirname(__DIR__);
$controller=(string)@file_get_contents($root.'/public/index.php');
$schema=(string)@file_get_contents($root.'/database/schema.sql');
$database=(string)@file_get_contents($root.'/src/Database.php');
$initialization=(string)@file_get_contents($root.'/src/InitializationState.php');
$checks=[
    'version_0_0_0_31'=>trim((string)@file_get_contents($root.'/VERSION'))==='1.0.0.0',
    'campaign_site_contact_columns'=>str_contains($schema,'responsible_name VARCHAR(180)')
        &&str_contains($schema,'responsible_email VARCHAR(255)')
        &&str_contains($schema,'contact_confirmed_at DATETIME')
        &&str_contains($schema,'site_confirmed_at DATETIME'),
    'schema_runtime_contact_columns'=>str_contains($database,"'campaign_sedes.responsible_name'")
        &&str_contains($database,"'campaign_sedes.responsible_email'")
        &&str_contains($database,"'campaign_sedes.contact_confirmed_at'")
        &&str_contains($database,"'campaign_sedes.site_confirmed_at'"),
    'contact_confirmation_route'=>str_contains($controller,"case 'campania_sede_contacto': campaign_site_contact_page();")
        &&str_contains($initialization,"'campania_sede_contacto'"),
    'contact_form_required_fields'=>str_contains($controller,"field('responsible_name','Nombre completo del Registrador o responsable'")
        &&str_contains($controller,"field('responsible_email','Correo electrónico de contacto'")
        &&str_contains($controller,'contact_confirmed_at=NOW()'),
    'campaign_publication_not_blocked_by_contact'=>str_contains($controller,'$critical=$overlapCritical;')
        &&str_contains($controller,"'contacto_pendiente'=>\$contactPending")
        &&str_contains($controller,'Publicable con datos pendientes'),
    'validation_requires_site_profile'=>str_contains($controller,"redirect('campania_sede_contacto'")
        &&str_contains($controller,"'equipment_id'=>\$id")
        &&str_contains($controller,'Antes de validar los equipos debe confirmar la información general, dirección y responsable de la sede.')
        &&str_contains($controller,'campaign_site_profile_complete'),
    'closure_requires_site_profile'=>str_contains($controller,'Antes de finalizar debe validar la información general, dirección y responsable de la sede.'),
    'zero_inventory_site_can_close'=>!str_contains($controller,'$assigned===0||$validated<$assigned'),
    'notifications_use_confirmed_contact'=>str_contains($controller,'cs.responsible_email,cs.responsible_name')
        &&str_contains($controller,"responsible_email']?:"),
];
$ok=!in_array(false,$checks,true);
echo json_encode([
    'ok'=>$ok,
    'version'=>trim((string)@file_get_contents($root.'/VERSION')),
    'check'=>'campaign_site_contact_on_validation_1.0.0.0',
    'checks'=>$checks,
],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:2);
