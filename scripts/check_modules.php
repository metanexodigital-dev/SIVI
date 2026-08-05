<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_modules.php
 * Propósito: Verifica automáticamente que la funcionalidad «modules» esté presente y sea coherente antes o después del despliegue.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$result = [
    'ok' => true,
    'application' => 'SIVI',
    'version' => AppVersion::package(),
    'checks' => [],
];

$add = static function (string $name, bool $ok, ?string $detail = null) use (&$result): void {
    $result['checks'][$name] = ['ok' => $ok, 'detail' => $detail];
    if (!$ok) $result['ok'] = false;
};

$add('auth_id_method', method_exists(Auth::class, 'id'), method_exists(Auth::class, 'id') ? 'Disponible' : 'Falta Auth::id()');

try {
    $schema = Database::schemaStatus();
    $add('schema', (bool)$schema['ok'], $schema['ok'] ? 'Esquema completo' : json_encode([
        'missing_tables' => $schema['missing_tables'],
        'missing_columns' => $schema['missing_columns'],
    ], JSON_UNESCAPED_UNICODE));
} catch (Throwable $e) {
    $add('schema', false, $e->getMessage());
}

$queries = [
    'notifications_query' => "SELECT n.id,n.user_id,n.campaign_id,n.sede_id,n.title,n.message,n.read_at,n.created_at,c.name AS campaign_name,s.nombre_sede FROM internal_notifications n LEFT JOIN campaigns c ON c.id=n.campaign_id LEFT JOIN sedes s ON s.id=n.sede_id WHERE n.user_id=0 ORDER BY n.created_at DESC LIMIT 1",
    'reminders_query' => "SELECT c.id,c.name,c.end_date,c.status,COUNT(cs.sede_id) AS sedes,COALESCE(SUM(CASE WHEN cs.sede_id IS NOT NULL AND cs.status NOT IN ('cerrado','aprobado') THEN 1 ELSE 0 END),0) AS pendientes FROM campaigns c LEFT JOIN campaign_sedes cs ON cs.campaign_id=c.id GROUP BY c.id,c.name,c.end_date,c.status ORDER BY c.id DESC LIMIT 1",
];

foreach ($queries as $name => $sql) {
    try {
        Database::fetchAll($sql);
        $add($name, true, 'Consulta válida');
    } catch (Throwable $e) {
        $add($name, false, $e->getMessage());
    }
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);
