<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_intuitive_validation.php
 * Propósito: Verifica automáticamente que la funcionalidad «intuitive validation» esté presente y sea coherente antes o después del despliegue.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
$root = dirname(__DIR__);
$index = file_get_contents($root . '/public/index.php') ?: '';
$views = file_get_contents($root . '/src/views.php') ?: '';
$css = file_get_contents($root . '/public/assets/app.css') ?: '';
$javascript = file_get_contents($root . '/public/assets/app.js') ?: '';
$checks = [
    'version' => version_compare(AppVersion::package(), '0.0.0.9', '>='),
    'three_steps' => substr_count($index, 'data-wizard-step=') >= 3,
    'ownership_cards' => str_contains($index, 'choice-card-grid-3'),
    'status_cards' => str_contains($index, 'choice-card-status'),
    'confirmation_required' => str_contains($index, 'validation_confirmation') && str_contains($index, 'verificación física del equipo'),
    'serial_comparison' => str_contains($index, 'data-verification-result="serial"'),
    'plate_comparison' => str_contains($index, 'data-verification-result="plate"'),
    'wizard_navigation' => str_contains($index, 'data-wizard-next') && str_contains($javascript, 'validateStep'),
    'responsive_styles' => str_contains($css, 'Formularios de validación intuitivos') && str_contains($css, '.choice-card-grid'),
];
$errors=[];foreach($checks as $name=>$ok){if(!$ok)$errors[]=$name;}
$result=['ok'=>$errors===[],'version'=>AppVersion::package(),'check'=>'intuitive_validation_0.0.0.9','checks'=>$checks,'errors'=>$errors];
header('Content-Type: application/json; charset=utf-8');
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($result['ok']?0:2);
