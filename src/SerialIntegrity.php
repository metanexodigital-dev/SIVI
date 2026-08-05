<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: src/SerialIntegrity.php
 * Propósito: Detecta y controla duplicados o inconsistencias en números de serie.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

/**
 * Mantiene la integridad del número de serie en el inventario activo.
 *
 * Regla institucional: un serial repetido no puede considerarse confiable para
 * identificar un elemento. Todos los registros del grupo duplicado quedan sin
 * serial visible y deben ser verificados físicamente por el usuario.
 */
final class SerialIntegrity
{
    /** @return array{duplicate_groups:int,cleared_equipment:int,duplicates:array<int,array{serial:string,equipment_ids:array<int,int>}>} */
    public static function clearActiveDuplicates(): array
    {
        $rows = Database::fetchAll(
            "SELECT id,serial_number,serial_source_original,serial_verified_at
             FROM equipment
             WHERE active=1 AND NULLIF(TRIM(serial_number),'') IS NOT NULL
             ORDER BY id"
        );

        /** @var array<string,array{serial:string,ids:array<int,int>}> $groups */
        $groups = [];
        foreach ($rows as $row) {
            $serial = trim((string)($row['serial_number'] ?? ''));
            $key = self::normalize($serial);
            if ($key === '') continue;
            if (!isset($groups[$key])) {
                $groups[$key] = ['serial'=>$serial,'ids'=>[]];
            }
            $groups[$key]['ids'][] = (int)$row['id'];
        }

        $duplicateGroups = 0;
        $cleared = 0;
        $duplicates = [];
        foreach ($groups as $key=>$group) {
            if (count($group['ids']) < 2) continue;
            $duplicateGroups++;
            $duplicates[] = ['serial'=>$group['serial'],'equipment_ids'=>$group['ids']];
            foreach ($group['ids'] as $equipmentId) {
                Database::execute(
                    "UPDATE equipment
                     SET serial_source_original=COALESCE(NULLIF(serial_source_original,''),serial_number),
                         serial_number=NULL,
                         serial_review_required=1,
                         serial_review_reason='duplicado',
                         serial_verified_at=NULL,
                         serial_verified_by=NULL,
                         warehouse_match_status=CASE
                            WHEN source_origin='glpi' THEN 'sin_serial'
                            ELSE warehouse_match_status
                         END,
                         updated_at=NOW()
                     WHERE id=?",
                    [$equipmentId]
                );
                $cleared++;
            }
        }

        return [
            'duplicate_groups'=>$duplicateGroups,
            'cleared_equipment'=>$cleared,
            'duplicates'=>$duplicates,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public static function activeMatches(string $serial, ?int $excludeEquipmentId = null): array
    {
        $key = self::normalize($serial);
        if ($key === '') return [];
        $rows = Database::fetchAll(
            "SELECT e.id,e.name,e.serial_number,e.placa_rnec,e.asset_category,e.current_sede_id,
                    s.identificador,s.nombre_sede,s.municipio,s.departamento
             FROM equipment e
             LEFT JOIN sedes s ON s.id=e.current_sede_id
             WHERE e.active=1 AND NULLIF(TRIM(e.serial_number),'') IS NOT NULL
             ORDER BY e.id"
        );
        return array_values(array_filter($rows, static function(array $row) use ($key,$excludeEquipmentId): bool {
            if ($excludeEquipmentId !== null && (int)$row['id'] === $excludeEquipmentId) return false;
            return self::normalize((string)$row['serial_number']) === $key;
        }));
    }

    /** @return array<int,array<string,mixed>> */
    public static function activePlateMatches(string $plate, ?int $excludeEquipmentId = null): array
    {
        $key = self::normalize($plate);
        if ($key === '') return [];
        $rows = Database::fetchAll(
            "SELECT e.id,e.name,e.serial_number,e.placa_rnec,e.asset_category,e.current_sede_id,
                    s.identificador,s.nombre_sede,s.municipio,s.departamento
             FROM equipment e
             LEFT JOIN sedes s ON s.id=e.current_sede_id
             WHERE e.active=1 AND NULLIF(TRIM(e.placa_rnec),'') IS NOT NULL
             ORDER BY e.id"
        );
        return array_values(array_filter($rows, static function(array $row) use ($key,$excludeEquipmentId): bool {
            if ($excludeEquipmentId !== null && (int)$row['id'] === $excludeEquipmentId) return false;
            return self::normalize((string)$row['placa_rnec']) === $key;
        }));
    }

    public static function isDuplicateInFrequency(string $serial, array $frequencies, string $category): bool
    {
        $key = self::normalize($serial);
        if ($key === '') return false;
        $categoryKey = is_computer_category($category) ? 'computador' : $category;
        return (int)($frequencies[$categoryKey.'|'.$key] ?? 0) > 1;
    }

    public static function normalize(string $serial): string
    {
        $serial = function_exists('mb_strtoupper') ? mb_strtoupper(trim($serial)) : strtoupper(trim($serial));
        return preg_replace('/[^A-Z0-9]/u', '', $serial) ?: '';
    }
}
