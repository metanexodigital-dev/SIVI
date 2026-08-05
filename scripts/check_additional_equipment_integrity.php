<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_additional_equipment_integrity.php
 * Propósito: Verifica automáticamente que la funcionalidad «additional equipment integrity» esté presente y sea coherente antes o después del despliegue.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

function asset_category_labels(bool $includeOther = false): array
{
    $labels = [
        'cpu'=>'CPU','portatil'=>'Portátil','pc_todo_en_uno'=>'PC Todo en Uno','monitor'=>'Monitor',
        'impresora'=>'Impresora','escaner'=>'Escáner','ups'=>'UPS',
    ];
    if ($includeOther) $labels['otro'] = 'Otro';
    return $labels;
}
function asset_category_label(string $category): string { return asset_category_labels(true)[$category] ?? 'Otro'; }
function normalize_placa_rnec(string $plate): ?string { return preg_match('/^000-[0-9]{5}$/', trim($plate)) ? trim($plate) : null; }
function route_url(string $page, array $params=[]): string { return 'index.php?' . http_build_query(['page'=>$page]+$params); }
final class Scope { public static function canAccessSede(int $id): bool { return $id === 7; } }
final class Database
{
    public static function fetchAll(string $sql, array $params=[]): array
    {
        if (str_contains($sql, 'FROM equipment e')) {
            return [[
                'id'=>44,'name'=>'MON-CAN-01','serial_number'=>'AB-C 123','placa_rnec'=>'000-12345',
                'asset_category'=>'monitor','equipment_type'=>'Monitor LCD','inventory_status'=>'activo','current_sede_id'=>7,
                'identificador'=>'CAN','nombre_sede'=>'Centro Administrativo Nacional','municipio'=>'Bogotá D.C','departamento'=>'CAN',
            ]];
        }
        if (str_contains($sql, 'FROM additional_equipment ae')) return [];
        return [];
    }
}
require_once dirname(__DIR__) . '/src/SerialIntegrity.php';
require_once dirname(__DIR__) . '/src/AdditionalEquipmentIntegrity.php';

$result = AdditionalEquipmentIntegrity::check('abc-123', '000-12345', 'cpu');
$empty = AdditionalEquipmentIntegrity::check('', null, 'cpu');
$root = dirname(__DIR__);
$controller = (string)@file_get_contents($root . '/public/index.php');
$javascript = (string)@file_get_contents($root . '/public/assets/app.js');
$bootstrap = (string)@file_get_contents($root . '/src/bootstrap.php');

$conflict = $result['conflicts'][0] ?? [];
$checks = [
    'version_0_0_0_31' => trim((string)@file_get_contents($root . '/VERSION')) === '1.0.0.0',
    'serial_and_plate_match' => $result['has_conflicts'] === true && ($conflict['matched_by'] ?? '') === 'serial y placa',
    'registered_location_returned' => ($conflict['sede_identificador'] ?? '') === 'CAN' && ($conflict['sede_nombre'] ?? '') === 'Centro Administrativo Nacional',
    'category_mismatch_reported' => ($conflict['category_mismatch'] ?? false) === true && ($conflict['category_label'] ?? '') === 'Monitor',
    'view_link_only_inside_scope' => str_contains((string)($conflict['view_url'] ?? ''), 'historial_equipo'),
    'empty_identity_has_no_conflict' => $empty['has_conflicts'] === false,
    'class_loaded_in_bootstrap' => str_contains($bootstrap, "AdditionalEquipmentIntegrity.php"),
    'server_blocks_duplicate' => substr_count($controller, 'AdditionalEquipmentIntegrity::check(') >= 2
        && str_contains($controller, 'additional_duplicate_redirect('),
    'server_preserves_form' => str_contains($controller, "additional_form_data") && str_contains($controller, "additional_identity_conflicts"),
    'ajax_endpoint_available' => str_contains($controller, "case 'additional_identity_check'")
        && str_contains($controller, 'function additional_identity_check_page'),
    'browser_checks_before_submit' => str_contains($javascript, '[data-additional-equipment-form]')
        && str_contains($javascript, 'Este elemento ya está registrado')
        && str_contains($javascript, 'Categoría diferente:'),
];
$errors=[];
foreach($checks as $name=>$ok) if(!$ok) $errors[]='No se cumple el control: '.$name;
$ok=$errors===[];
echo json_encode([
    'ok'=>$ok,
    'version'=>trim((string)@file_get_contents($root . '/VERSION')),
    'check'=>'additional_equipment_identity_integrity_1.0.0.0',
    'checks'=>$checks,
    'sample'=>$result,
    'errors'=>$errors,
], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:2);
