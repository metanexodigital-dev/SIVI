<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_asset_validation.php
 * Propósito: Verifica automáticamente que la funcionalidad «asset validation» esté presente y sea coherente antes o después del despliegue.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$result = [
    'ok' => true,
    'version' => AppVersion::package(),
    'check' => 'asset_validation_0.0.0.9',
    'checks' => [],
    'errors' => [],
];

try {
    $required = [
        ['equipment','ownership_type'],
        ['equipment','inventory_status'],
        ['equipment_validations','physical_condition'],
        ['equipment_validations','ownership_type'],
        ['equipment_validations','destination_sede_id'],
        ['equipment_validations','disposal_date'],
        ['equipment_validations','disposal_document'],
        ['equipment_validations','serial_reported'],
        ['equipment_validations','placa_reported'],
    ];
    foreach ($required as [$table,$column]) {
        $exists = (int)(Database::fetchOne(
            'SELECT COUNT(*) total FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',
            [$table,$column]
        )['total'] ?? 0) > 0;
        $result['checks']["{$table}.{$column}"] = $exists;
        if (!$exists) $result['errors'][] = "Falta {$table}.{$column}";
    }

    $statusColumn = Database::fetchOne(
        "SELECT column_type FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='equipment_validations' AND column_name='physical_condition'"
    );
    $statusType = (string)($statusColumn['column_type'] ?? '');
    foreach (['activo','inactivo','para_baja','dado_baja','en_almacen','en_mantenimiento','trasladado'] as $status) {
        $ok = str_contains($statusType, "'{$status}'");
        $result['checks']["status_{$status}"] = $ok;
        if (!$ok) $result['errors'][] = "El estado {$status} no está disponible";
    }

    $source = file_get_contents(dirname(__DIR__) . '/public/index.php') ?: '';
    $result['checks']['question_removed'] = !str_contains($source, '¿El equipo pertenece actualmente a esta oficina?');
    $result['checks']['possible_site_removed'] = !str_contains($source, 'Posible sede a la que pertenece');
    $result['checks']['serial_required_message'] = str_contains($source, 'El Número de serie verificado es obligatorio');
    $result['checks']['conditional_plate_rule'] = str_contains($source, 'La Placa RNEC es obligatoria para los equipos propios');

    foreach (['question_removed','possible_site_removed','serial_required_message','conditional_plate_rule'] as $key) {
        if (!$result['checks'][$key]) $result['errors'][] = "Falló {$key}";
    }
} catch (Throwable $e) {
    $result['errors'][] = $e->getMessage();
}

$result['ok'] = $result['errors'] === [];
header('Content-Type: application/json; charset=utf-8');
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 2);
