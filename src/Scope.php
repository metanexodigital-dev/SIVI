<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: src/Scope.php
 * Propósito: Determina el alcance territorial y funcional permitido para cada usuario.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

final class Scope
{
    public static function sedeCondition(string $alias = 's'): array
    {
        $user = Auth::user();
        if (!$user || in_array($user['role'], ['admin_gi','superadmin'], true)) {
            return ['1=1', []];
        }
        if ($user['role'] === 'registrador') {
            return ["{$alias}.id = ?", [(int)$user['sede_id']]];
        }
        $departments = $user['departments'] ?? [];
        if (!$departments) {
            return ['1=0', []];
        }
        $placeholders = implode(',', array_fill(0, count($departments), '?'));
        return ["{$alias}.cod_dd IN ({$placeholders})", $departments];
    }

    public static function equipmentCondition(string $equipmentAlias = 'e', string $sedeAlias = 's'): array
    {
        return self::sedeCondition($sedeAlias);
    }

    public static function canAccessSede(int $sedeId): bool
    {
        [$where, $params] = self::sedeCondition('s');
        $params[] = $sedeId;
        return (bool)Database::fetchOne("SELECT s.id FROM sedes s WHERE {$where} AND s.id=?", $params);
    }

    public static function canAccessEquipment(int $equipmentId): bool
    {
        [$where, $params] = self::sedeCondition('s');
        $params[] = $equipmentId;
        return (bool)Database::fetchOne("SELECT e.id FROM equipment e LEFT JOIN sedes s ON s.id=e.current_sede_id WHERE {$where} AND e.id=?", $params);
    }

    public static function canResetUserPassword(int $targetUserId): bool
    {
        $current = Auth::user();
        if (!$current || $targetUserId < 1 || $targetUserId === (int)$current['id']) {
            return false;
        }

        if (in_array($current['role'], ['admin_gi','superadmin'], true)) {
            return (bool)Database::fetchOne('SELECT id FROM users WHERE id=?', [$targetUserId]);
        }

        if ($current['role'] !== 'formador') {
            return false;
        }

        $departments = array_values(array_filter(array_map('strval', $current['departments'] ?? [])));
        if (!$departments) {
            return false;
        }
        $placeholders = implode(',', array_fill(0, count($departments), '?'));
        $params = array_merge([$targetUserId], $departments);
        return (bool)Database::fetchOne(
            "SELECT u.id FROM users u JOIN sedes s ON s.id=u.sede_id WHERE u.id=? AND u.role='registrador' AND s.cod_dd IN ({$placeholders}) LIMIT 1",
            $params
        );
    }
}
