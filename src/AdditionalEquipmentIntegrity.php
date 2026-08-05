<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: src/AdditionalEquipmentIntegrity.php
 * Propósito: Valida la identidad y consistencia de los equipos adicionales.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

/**
 * Evita que un elemento ya registrado se vuelva a crear como equipo adicional.
 *
 * La comparación de serial ignora mayúsculas, espacios y separadores. La placa
 * se compara en su forma institucional normalizada. Se consultan tanto el
 * inventario activo como los equipos adicionales pendientes o aprobados.
 */
final class AdditionalEquipmentIntegrity
{
    /** @return array{has_conflicts:bool,identity_split:bool,selected_category:string,selected_category_label:string,conflicts:array<int,array<string,mixed>>} */
    /**
     * Crea un nombre de bloqueo de MySQL para serial y placa.
     *
     * El bloqueo evita que dos solicitudes simultáneas superen la validación y
     * creen el mismo equipo al mismo tiempo.
     */
    public static function lockName(string $serial, ?string $plate): string
    {
        $serialKey = SerialIntegrity::normalize($serial);
        $plateKey = self::normalizePlate($plate);
        return 'sivi-additional-' . substr(hash('sha256', $serialKey . '|' . $plateKey), 0, 40);
    }

    public static function check(string $serial, ?string $plate, string $selectedCategory): array
    {
        /*
         * La mayoría de los registros se resuelve primero mediante índices.
         * El barrido normalizado se conserva únicamente como contingencia para
         * valores históricos con espacios o separadores distintos.
         */
        $fastResult = self::checkFast($serial, $plate, $selectedCategory);
        if (!empty($fastResult['has_conflicts'])) {
            return $fastResult;
        }

        $serialKey = SerialIntegrity::normalize($serial);
        $plateKey = self::normalizePlate($plate);
        $selectedCategory = array_key_exists(
            $selectedCategory,
            asset_category_labels(true)
        ) ? $selectedCategory : 'otro';

        if ($serialKey === '' && $plateKey === '') {
            return self::result([], $selectedCategory);
        }

        $matches = [];
        foreach (self::inventoryMatches($serialKey, $plateKey) as $row) {
            self::mergeMatch(
                $matches,
                'equipment:' . (int)$row['id'],
                self::mapInventory(
                    $row,
                    $serialKey,
                    $plateKey,
                    $selectedCategory
                )
            );
        }
        foreach (self::additionalMatches($serialKey, $plateKey) as $row) {
            self::mergeMatch(
                $matches,
                'additional:' . (int)$row['id'],
                self::mapAdditional(
                    $row,
                    $serialKey,
                    $plateKey,
                    $selectedCategory
                )
            );
        }

        return self::result(array_values($matches), $selectedCategory);
    }

    /**
     * Comprobación rápida para la interfaz.
     *
     * Usa comparaciones directas que pueden aprovechar los índices existentes.
     * La validación completa continúa ejecutándose en el servidor al guardar.
     *
     * @return array{has_conflicts:bool,identity_split:bool,selected_category:string,selected_category_label:string,conflicts:array<int,array<string,mixed>>}
     */
    public static function checkFast(string $serial, ?string $plate, string $selectedCategory): array
    {
        $serialKey = SerialIntegrity::normalize($serial);
        $plateKey = self::normalizePlate($plate);
        $selectedCategory = array_key_exists(
            $selectedCategory,
            asset_category_labels(true)
        ) ? $selectedCategory : 'otro';

        if ($serialKey === '' && $plateKey === '') {
            return self::result([], $selectedCategory);
        }

        $matches = [];
        foreach (self::inventoryMatchesExact($serial, $serialKey, $plate, $plateKey) as $row) {
            self::mergeMatch(
                $matches,
                'equipment:' . (int)$row['id'],
                self::mapInventory($row, $serialKey, $plateKey, $selectedCategory)
            );
        }
        foreach (self::additionalMatchesExact($serial, $serialKey, $plate, $plateKey) as $row) {
            self::mergeMatch(
                $matches,
                'additional:' . (int)$row['id'],
                self::mapAdditional($row, $serialKey, $plateKey, $selectedCategory)
            );
        }

        return self::result(array_values($matches), $selectedCategory);
    }

    /** @return array<int,string> */
    private static function serialCandidates(string $serial, string $serialKey): array
    {
        $values = [
            trim($serial),
            $serialKey,
        ];
        return array_values(array_unique(array_filter(
            $values,
            static fn(string $value): bool => $value !== ''
        )));
    }

    /** @return array<int,string> */
    private static function plateCandidates(?string $plate, string $plateKey): array
    {
        $values = [
            trim((string)$plate),
            $plateKey,
        ];
        if (preg_match('/^000[0-9]{5}$/', $plateKey) === 1) {
            $values[] = substr($plateKey, 0, 3) . '-' . substr($plateKey, 3);
        }
        return array_values(array_unique(array_filter(
            $values,
            static fn(string $value): bool => $value !== ''
        )));
    }

    /** @return array<int,array<string,mixed>> */
    private static function mergeRowsById(array &$rows, array $incoming): void
    {
        foreach ($incoming as $row) {
            $rows[(int)$row['id']] = $row;
        }
    }

    /** @return array<int,array<string,mixed>> */
    private static function inventoryMatchesExact(
        string $serial,
        string $serialKey,
        ?string $plate,
        string $plateKey
    ): array {
        $rows = [];
        $serialValues = self::serialCandidates($serial, $serialKey);
        $plateValues = self::plateCandidates($plate, $plateKey);

        if ($serialValues !== []) {
            self::mergeRowsById(
                $rows,
                Database::fetchAll(
                    "SELECT e.id,e.name,e.serial_number,e.placa_rnec,"
                    . "e.asset_category,e.equipment_type,e.inventory_status,"
                    . "e.current_sede_id,s.identificador,s.nombre_sede,"
                    . "s.municipio,s.departamento "
                    . "FROM equipment e "
                    . "LEFT JOIN sedes s ON s.id=e.current_sede_id "
                    . "WHERE e.active=1 AND e.serial_number IN ("
                    . implode(',', array_fill(0, count($serialValues), '?'))
                    . ") ORDER BY e.id LIMIT 10",
                    $serialValues
                )
            );
        }

        if ($plateValues !== []) {
            self::mergeRowsById(
                $rows,
                Database::fetchAll(
                    "SELECT e.id,e.name,e.serial_number,e.placa_rnec,"
                    . "e.asset_category,e.equipment_type,e.inventory_status,"
                    . "e.current_sede_id,s.identificador,s.nombre_sede,"
                    . "s.municipio,s.departamento "
                    . "FROM equipment e "
                    . "LEFT JOIN sedes s ON s.id=e.current_sede_id "
                    . "WHERE e.active=1 AND e.placa_rnec IN ("
                    . implode(',', array_fill(0, count($plateValues), '?'))
                    . ") ORDER BY e.id LIMIT 10",
                    $plateValues
                )
            );
        }

        ksort($rows);
        return array_values($rows);
    }

    /** @return array<int,array<string,mixed>> */
    private static function additionalMatchesExact(
        string $serial,
        string $serialKey,
        ?string $plate,
        string $plateKey
    ): array {
        $rows = [];
        $serialValues = self::serialCandidates($serial, $serialKey);
        $plateValues = self::plateCandidates($plate, $plateKey);

        if ($serialValues !== []) {
            self::mergeRowsById(
                $rows,
                Database::fetchAll(
                    "SELECT ae.id,ae.campaign_id,ae.name,ae.serial_number,"
                    . "ae.placa_rnec,ae.asset_category,ae.equipment_type,"
                    . "ae.review_status,ae.sede_id,s.identificador,"
                    . "s.nombre_sede,s.municipio,s.departamento,"
                    . "c.name campaign_name "
                    . "FROM additional_equipment ae "
                    . "JOIN sedes s ON s.id=ae.sede_id "
                    . "JOIN campaigns c ON c.id=ae.campaign_id "
                    . "WHERE ae.review_status<>'rechazado' "
                    . "AND ae.serial_number IN ("
                    . implode(',', array_fill(0, count($serialValues), '?'))
                    . ") ORDER BY ae.id DESC LIMIT 10",
                    $serialValues
                )
            );
        }

        if ($plateValues !== []) {
            self::mergeRowsById(
                $rows,
                Database::fetchAll(
                    "SELECT ae.id,ae.campaign_id,ae.name,ae.serial_number,"
                    . "ae.placa_rnec,ae.asset_category,ae.equipment_type,"
                    . "ae.review_status,ae.sede_id,s.identificador,"
                    . "s.nombre_sede,s.municipio,s.departamento,"
                    . "c.name campaign_name "
                    . "FROM additional_equipment ae "
                    . "JOIN sedes s ON s.id=ae.sede_id "
                    . "JOIN campaigns c ON c.id=ae.campaign_id "
                    . "WHERE ae.review_status<>'rechazado' "
                    . "AND ae.placa_rnec IN ("
                    . implode(',', array_fill(0, count($plateValues), '?'))
                    . ") ORDER BY ae.id DESC LIMIT 10",
                    $plateValues
                )
            );
        }

        krsort($rows);
        return array_values($rows);
    }

    /** @param array<string,array<string,mixed>> $matches */
    private static function mergeMatch(array &$matches, string $key, array $match): void
    {
        if (!isset($matches[$key])) {
            $matches[$key] = $match;
            return;
        }
        $matches[$key]['match_serial'] = !empty($matches[$key]['match_serial']) || !empty($match['match_serial']);
        $matches[$key]['match_plate'] = !empty($matches[$key]['match_plate']) || !empty($match['match_plate']);
        $matches[$key]['matched_by'] = self::matchedBy((bool)$matches[$key]['match_serial'], (bool)$matches[$key]['match_plate']);
    }

    /** @param array<int,array<string,mixed>> $conflicts */
    private static function result(array $conflicts, string $selectedCategory): array
    {
        $serialRecords = [];
        $plateRecords = [];
        foreach ($conflicts as $conflict) {
            $key = (string)$conflict['source'] . ':' . (int)$conflict['id'];
            if (!empty($conflict['match_serial'])) $serialRecords[$key] = true;
            if (!empty($conflict['match_plate'])) $plateRecords[$key] = true;
        }
        $serialKeys = array_keys($serialRecords);
        $plateKeys = array_keys($plateRecords);
        sort($serialKeys);
        sort($plateKeys);
        $identitySplit = $serialKeys !== [] && $plateKeys !== [] && $serialKeys !== $plateKeys;
        return [
            'has_conflicts' => $conflicts !== [],
            'identity_split' => $identitySplit,
            'selected_category' => $selectedCategory,
            'selected_category_label' => asset_category_label($selectedCategory),
            'conflicts' => $conflicts,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private static function inventoryMatches(string $serialKey, string $plateKey): array
    {
        $where = [];
        $params = [];
        if ($serialKey !== '') {
            $where[] = "REGEXP_REPLACE(UPPER(COALESCE(e.serial_number,'')), '[^A-Z0-9]', '')=?";
            $params[] = $serialKey;
        }
        if ($plateKey !== '') {
            $where[] = "REGEXP_REPLACE(UPPER(COALESCE(e.placa_rnec,'')), '[^A-Z0-9]', '')=?";
            $params[] = $plateKey;
        }
        if ($where === []) return [];

        try {
            return Database::fetchAll(
                "SELECT e.id,e.name,e.serial_number,e.placa_rnec,e.asset_category,e.equipment_type,e.inventory_status,e.current_sede_id,
                        s.identificador,s.nombre_sede,s.municipio,s.departamento
                 FROM equipment e
                 LEFT JOIN sedes s ON s.id=e.current_sede_id
                 WHERE e.active=1 AND (" . implode(' OR ', $where) . ")
                 ORDER BY e.id LIMIT 25",
                $params
            );
        } catch (Throwable) {
            // Compatibilidad defensiva para motores sin REGEXP_REPLACE.
            $rows = Database::fetchAll(
                "SELECT e.id,e.name,e.serial_number,e.placa_rnec,e.asset_category,e.equipment_type,e.inventory_status,e.current_sede_id,
                        s.identificador,s.nombre_sede,s.municipio,s.departamento
                 FROM equipment e
                 LEFT JOIN sedes s ON s.id=e.current_sede_id
                 WHERE e.active=1 AND (NULLIF(TRIM(e.serial_number),'') IS NOT NULL OR NULLIF(TRIM(e.placa_rnec),'') IS NOT NULL)
                 ORDER BY e.id"
            );
            return array_values(array_filter($rows, static function(array $row) use ($serialKey, $plateKey): bool {
                return ($serialKey !== '' && SerialIntegrity::normalize((string)($row['serial_number'] ?? '')) === $serialKey)
                    || ($plateKey !== '' && self::normalizePlate((string)($row['placa_rnec'] ?? '')) === $plateKey);
            }));
        }
    }

    /** @return array<int,array<string,mixed>> */
    private static function additionalMatches(string $serialKey, string $plateKey): array
    {
        $where = [];
        $params = [];
        if ($serialKey !== '') {
            $where[] = "REGEXP_REPLACE(UPPER(COALESCE(ae.serial_number,'')), '[^A-Z0-9]', '')=?";
            $params[] = $serialKey;
        }
        if ($plateKey !== '') {
            $where[] = "REGEXP_REPLACE(UPPER(COALESCE(ae.placa_rnec,'')), '[^A-Z0-9]', '')=?";
            $params[] = $plateKey;
        }
        if ($where === []) return [];

        try {
            return Database::fetchAll(
                "SELECT ae.id,ae.campaign_id,ae.name,ae.serial_number,ae.placa_rnec,ae.asset_category,ae.equipment_type,ae.review_status,ae.sede_id,
                        s.identificador,s.nombre_sede,s.municipio,s.departamento,c.name campaign_name
                 FROM additional_equipment ae
                 JOIN sedes s ON s.id=ae.sede_id
                 JOIN campaigns c ON c.id=ae.campaign_id
                 WHERE ae.review_status<>'rechazado' AND (" . implode(' OR ', $where) . ")
                 ORDER BY ae.id DESC LIMIT 25",
                $params
            );
        } catch (Throwable) {
            $rows = Database::fetchAll(
                "SELECT ae.id,ae.campaign_id,ae.name,ae.serial_number,ae.placa_rnec,ae.asset_category,ae.equipment_type,ae.review_status,ae.sede_id,
                        s.identificador,s.nombre_sede,s.municipio,s.departamento,c.name campaign_name
                 FROM additional_equipment ae
                 JOIN sedes s ON s.id=ae.sede_id
                 JOIN campaigns c ON c.id=ae.campaign_id
                 WHERE ae.review_status<>'rechazado'
                   AND (NULLIF(TRIM(ae.serial_number),'') IS NOT NULL OR NULLIF(TRIM(ae.placa_rnec),'') IS NOT NULL)
                 ORDER BY ae.id DESC"
            );
            return array_values(array_filter($rows, static function(array $row) use ($serialKey, $plateKey): bool {
                return ($serialKey !== '' && SerialIntegrity::normalize((string)($row['serial_number'] ?? '')) === $serialKey)
                    || ($plateKey !== '' && self::normalizePlate((string)($row['placa_rnec'] ?? '')) === $plateKey);
            }));
        }
    }

    /** @return array<string,mixed> */
    private static function mapInventory(array $row, string $serialKey, string $plateKey, string $selectedCategory): array
    {
        $category = (string)($row['asset_category'] ?? 'otro');
        $matchSerial = $serialKey !== '' && SerialIntegrity::normalize((string)($row['serial_number'] ?? '')) === $serialKey;
        $matchPlate = $plateKey !== '' && self::normalizePlate((string)($row['placa_rnec'] ?? '')) === $plateKey;
        $sedeId = (int)($row['current_sede_id'] ?? 0);
        return [
            'source' => 'inventario',
            'source_label' => 'Inventario activo',
            'id' => (int)$row['id'],
            'name' => trim((string)($row['name'] ?? '')) ?: 'Elemento sin hostname',
            'serial_number' => (string)($row['serial_number'] ?? ''),
            'placa_rnec' => (string)($row['placa_rnec'] ?? ''),
            'category' => $category,
            'category_label' => asset_category_label($category),
            'equipment_type' => (string)($row['equipment_type'] ?? ''),
            'status' => (string)($row['inventory_status'] ?? 'activo'),
            'sede_id' => $sedeId,
            'sede_identificador' => (string)($row['identificador'] ?? ''),
            'sede_nombre' => (string)($row['nombre_sede'] ?? ''),
            'municipio' => (string)($row['municipio'] ?? ''),
            'departamento' => (string)($row['departamento'] ?? ''),
            'campaign_name' => null,
            'match_serial' => $matchSerial,
            'match_plate' => $matchPlate,
            'matched_by' => self::matchedBy($matchSerial, $matchPlate),
            'category_mismatch' => $category !== $selectedCategory,
            'view_url' => $sedeId > 0 && Scope::canAccessSede($sedeId) ? route_url('historial_equipo', ['id'=>(int)$row['id']]) : null,
        ];
    }

    /** @return array<string,mixed> */
    private static function mapAdditional(array $row, string $serialKey, string $plateKey, string $selectedCategory): array
    {
        $category = (string)($row['asset_category'] ?? 'otro');
        $matchSerial = $serialKey !== '' && SerialIntegrity::normalize((string)($row['serial_number'] ?? '')) === $serialKey;
        $matchPlate = $plateKey !== '' && self::normalizePlate((string)($row['placa_rnec'] ?? '')) === $plateKey;
        return [
            'source' => 'adicional',
            'source_label' => 'Equipo adicional ya reportado',
            'id' => (int)$row['id'],
            'name' => trim((string)($row['name'] ?? '')) ?: 'Elemento adicional sin hostname',
            'serial_number' => (string)($row['serial_number'] ?? ''),
            'placa_rnec' => (string)($row['placa_rnec'] ?? ''),
            'category' => $category,
            'category_label' => asset_category_label($category),
            'equipment_type' => (string)($row['equipment_type'] ?? ''),
            'status' => (string)($row['review_status'] ?? 'pendiente'),
            'sede_id' => (int)($row['sede_id'] ?? 0),
            'sede_identificador' => (string)($row['identificador'] ?? ''),
            'sede_nombre' => (string)($row['nombre_sede'] ?? ''),
            'municipio' => (string)($row['municipio'] ?? ''),
            'departamento' => (string)($row['departamento'] ?? ''),
            'campaign_name' => (string)($row['campaign_name'] ?? ''),
            'match_serial' => $matchSerial,
            'match_plate' => $matchPlate,
            'matched_by' => self::matchedBy($matchSerial, $matchPlate),
            'category_mismatch' => $category !== $selectedCategory,
            'view_url' => Scope::canAccessSede((int)($row['sede_id'] ?? 0))
                ? route_url('adicionales', [
                    'campaign_id'=>(int)($row['campaign_id'] ?? 0),
                    'sede_id'=>(int)($row['sede_id'] ?? 0),
                ])
                : null,
        ];
    }

    private static function matchedBy(bool $serial, bool $plate): string
    {
        if ($serial && $plate) return 'serial y placa';
        return $serial ? 'serial' : 'placa';
    }

    private static function normalizePlate(?string $plate): string
    {
        $text = function_exists('mb_strtoupper') ? mb_strtoupper(trim((string)$plate)) : strtoupper(trim((string)$plate));
        return preg_replace('/[^A-Z0-9]/u', '', $text) ?: '';
    }
}
