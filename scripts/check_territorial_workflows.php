<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_territorial_workflows.php
 * Propósito: Verifica automáticamente que la funcionalidad «territorial workflows» esté presente y sea coherente antes o después del despliegue.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$result = [
    'ok' => true,
    'application' => 'SIVI',
    'version' => AppVersion::package(),
    'check' => 'territorial_workflows_0.0.0.3',
    'checks' => [],
];

$check = static function (string $name, callable $callback) use (&$result): void {
    try {
        $detail = $callback();
        $result['checks'][$name] = ['ok'=>true,'detail'=>$detail];
    } catch (Throwable $exception) {
        $result['ok'] = false;
        $result['checks'][$name] = ['ok'=>false,'error'=>$exception->getMessage()];
    }
};

$check('database_connection', static function (): array {
    Database::connection();
    return ['connected'=>true];
});

$check('sede_catalog', static function (): array {
    $row = Database::fetchOne("SELECT COUNT(*) total,
        SUM(NULLIF(TRIM(tipo_sede),'') IS NULL) sin_tipo,
        SUM(NULLIF(TRIM(departamento),'') IS NULL) sin_departamento,
        SUM(NULLIF(TRIM(municipio),'') IS NULL) sin_municipio,
        SUM(NULLIF(TRIM(nombre_sede),'') IS NULL) sin_nombre
        FROM sedes") ?: [];
    return [
        'total'=>(int)($row['total']??0),
        'sin_tipo'=>(int)($row['sin_tipo']??0),
        'sin_departamento'=>(int)($row['sin_departamento']??0),
        'sin_municipio'=>(int)($row['sin_municipio']??0),
        'sin_nombre'=>(int)($row['sin_nombre']??0),
    ];
});

$check('module_queries', static function (): array {
    Database::fetchOne('SELECT id,sede_id FROM additional_equipment ORDER BY id DESC LIMIT 1');
    Database::fetchOne('SELECT id,sede_id,equipment_id FROM incidents ORDER BY id DESC LIMIT 1');
    Database::fetchOne('SELECT id,origin_sede_id,destination_sede_id,equipment_id FROM equipment_transfers ORDER BY id DESC LIMIT 1');
    Database::fetchOne('SELECT campaign_id,sede_id,status FROM campaign_sedes ORDER BY campaign_id DESC LIMIT 1');
    return ['additional_equipment'=>true,'incidents'=>true,'transfers'=>true,'workflow'=>true];
});

$check('source_markers', static function (): array {
    $index = file_get_contents(dirname(__DIR__) . '/public/index.php');
    $helpers = file_get_contents(dirname(__DIR__) . '/src/helpers.php');
    $javascript = file_get_contents(dirname(__DIR__) . '/public/assets/app.js');
    $required = [
        'module_sede_selector_fields' => str_contains($helpers, 'function module_sede_selector_fields'),
        'selection_profile_rule' => str_contains($helpers, 'function profile_requires_sede_selection'),
        'equipment_additional_gate' => str_contains($index, "module_sede_selector_fields(\$sedes, \$selectedSedeId, 'additional_scope'"),
        'incidents_gate' => str_contains($index, "module_sede_selector_fields(\$sedes,\$selectedSedeId,'incident_scope'"),
        'workflow_gate' => str_contains($index, "module_sede_selector_fields(\$selectorSedes, \$selectedSedeId, 'workflow_scope'"),
        'transfers_origin_gate' => str_contains($index, "module_sede_selector_fields(\$originSedes,\$selectedSedeId,'transfer_origin'"),
        'cascade_javascript' => str_contains($javascript, '[data-cascade-sede-selector]'),
        'form_gate_javascript' => str_contains($javascript, '[data-sede-gate]'),
    ];
    foreach ($required as $name=>$ok) {
        if (!$ok) throw new RuntimeException('Falta marcador: ' . $name);
    }
    return $required;
});

header('Content-Type: application/json; charset=utf-8');
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);
