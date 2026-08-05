<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_campaign_departments.php
 * Propósito: Verifica automáticamente que la funcionalidad «campaign departments» esté presente y sea coherente antes o después del despliegue.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/bootstrap.php';

$result = [
    'ok' => true,
    'application' => 'SIVI',
    'version' => AppVersion::package(),
    'check' => 'campaigns_by_departments_0.0.0.7',
    'checks' => [],
];

try {
    $column = Database::fetchOne("SELECT COUNT(*) total FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='campaigns' AND COLUMN_NAME='scope_type'");
    $table = Database::fetchOne("SELECT COUNT(*) total FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='campaign_departments'");
    $result['checks']['schema'] = [
        'ok' => (int)($column['total'] ?? 0) === 1 && (int)($table['total'] ?? 0) === 1,
        'scope_type_column' => (int)($column['total'] ?? 0) === 1,
        'campaign_departments_table' => (int)($table['total'] ?? 0) === 1,
    ];
    $result['checks']['data'] = [
        'ok' => true,
        'campaigns' => (int)(Database::fetchOne('SELECT COUNT(*) total FROM campaigns')['total'] ?? 0),
        'department_links' => (int)(Database::fetchOne('SELECT COUNT(*) total FROM campaign_departments')['total'] ?? 0),
        'campaign_sites' => (int)(Database::fetchOne('SELECT COUNT(*) total FROM campaign_sedes')['total'] ?? 0),
    ];
} catch (Throwable $e) {
    $result['ok'] = false;
    $result['checks']['database'] = ['ok' => false, 'error' => $e->getMessage()];
}

$source = file_get_contents(dirname(__DIR__) . '/public/index.php') ?: '';
$markers = [
    'campaign_departments' => str_contains($source, 'campaign_departments'),
    'department_codes' => str_contains($source, 'department_codes[]'),
    'scope_validation' => str_contains($source, 'campaign_manageable_by_current_user'),
    'campaign_membership' => str_contains($source, 'Equipo fuera de la campaña'),
];
$result['checks']['source'] = ['ok' => !in_array(false, $markers, true), 'markers' => $markers];
foreach ($result['checks'] as $check) if (($check['ok'] ?? true) !== true) $result['ok'] = false;
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($result['ok'] ? 0 : 2);
