<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: src/WarehouseImporter.php
 * Propósito: Procesa el inventario proveniente de Almacén y prepara su asociación territorial.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

final class WarehouseImporter
{
    private const CATEGORIES = ['cpu','portatil','pc_todo_en_uno','monitor','impresora','escaner','ups'];

    public static function import(string $path, string $originalName, int $userId): array
    {
        $pdo = Database::connection();
        $hash = hash_file('sha256', $path) ?: null;
        Database::execute(
            'INSERT INTO warehouse_imports (filename,original_name,file_hash,status,created_by) VALUES (?,?,?,?,?)',
            [basename($path), $originalName, $hash, 'procesando', $userId]
        );
        $importId = (int)$pdo->lastInsertId();

        try {
            $reader = new XlsxReader($path);
            $sheet = self::detectSheet($reader->sheetNames());
            if ($sheet === null) {
                throw new RuntimeException('No se encontró la hoja "INVENTARIO PC\'S" del reporte de Almacén.');
            }

            $pdo->beginTransaction();
            $rows = 0;
            $withInternalSerial = 0;
            $invalidPlates = 0;
            $selectedCategories = 0;
            $categoryCounts = array_fill_keys(self::CATEGORIES, 0);
            $warehouseKeys = [];

            $stmt = $pdo->prepare(
                'INSERT INTO warehouse_assets
                (import_id,warehouse_key,placa_raw,placa_rnec,description,product_name,asset_category,warehouse_serial,serial_internal,serial_internal_normalized,brand,reference,reference_normalized,state_code,current_state,branch,cost_center,responsible,holder,associated_sede_id,association_rule,association_confidence,association_evidence,association_review_required,updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NULL,NULL,"sin_asignar",NULL,1,NOW())
                ON DUPLICATE KEY UPDATE import_id=VALUES(import_id),placa_raw=VALUES(placa_raw),placa_rnec=VALUES(placa_rnec),description=VALUES(description),product_name=VALUES(product_name),asset_category=VALUES(asset_category),warehouse_serial=VALUES(warehouse_serial),serial_internal=VALUES(serial_internal),serial_internal_normalized=VALUES(serial_internal_normalized),brand=VALUES(brand),reference=VALUES(reference),reference_normalized=VALUES(reference_normalized),state_code=VALUES(state_code),current_state=VALUES(current_state),branch=VALUES(branch),cost_center=VALUES(cost_center),responsible=VALUES(responsible),holder=VALUES(holder),associated_sede_id=NULL,association_rule=NULL,association_confidence="sin_asignar",association_evidence=NULL,association_review_required=1,updated_at=NOW()'
            );

            foreach ($reader->rows($sheet, 1) as $row) {
                $plateRaw = trim((string)self::value($row, ['Placa / Activo', 'Placa', 'Activo']));
                if ($plateRaw === '') continue;
                $plate = normalize_placa_rnec($plateRaw);
                if ($plate === null) $invalidPlates++;
                $warehouseKey = self::warehouseKey($plateRaw);
                $warehouseKeys[$warehouseKey] = true;

                $description = trim((string)self::value($row, ['Descripción Placa / Activo']));
                $productName = trim((string)self::value($row, ['Nombre Producto']));
                $category = self::categoryFromWarehouseProduct($productName);
                if ($category !== null) {
                    $selectedCategories++;
                    $categoryCounts[$category]++;
                }

                $internalSerial = trim((string)self::value($row, ['Numero Serie Interno', 'Número Serie Interno']));
                $warehouseSerial = trim((string)self::value($row, ['Número de Serie', 'Numero de Serie']));
                $reference = trim((string)self::value($row, ['Referencia']));

                $internalNormalized = self::normalizeSerial($internalSerial);
                $warehouseNormalized = self::normalizeSerial($warehouseSerial);
                $referenceNormalized = self::normalizeSerial($reference);
                $effectiveSerialNormalized = $internalNormalized !== ''
                    ? $internalNormalized
                    : ($warehouseNormalized !== '' ? $warehouseNormalized : $referenceNormalized);

                if ($internalNormalized !== '') $withInternalSerial++;

                $stmt->execute([
                    $importId,
                    $warehouseKey,
                    $plateRaw,
                    $plate,
                    $description,
                    $productName,
                    $category,
                    $warehouseSerial,
                    $internalSerial,
                    $effectiveSerialNormalized,
                    trim((string)self::value($row, ['Nombre Marca Activos'])),
                    $reference,
                    $referenceNormalized,
                    trim((string)self::value($row, ['Estado'])),
                    trim((string)self::value($row, ['Estado Actual', 'Nombre Estado de Activo'])),
                    trim((string)self::value($row, ['Nombre Sucursal'])),
                    trim((string)self::value($row, ['Nombre Centro de Costo'])),
                    trim((string)self::value($row, ['Nombre Tercero Responsable'])),
                    trim((string)self::value($row, ['Nombre Tercero a Cargo'])),
                ]);
                $rows++;
            }

            /*
             * La persistencia del archivo base se confirma antes de iniciar la
             * conciliación. Esto evita mantener una transacción extensa durante
             * decenas de miles de cruces y actualizaciones posteriores.
             */
            $expectedStoredAssets = count($warehouseKeys);
            $storedRow = Database::fetchOne(
                'SELECT COUNT(*) total FROM warehouse_assets WHERE import_id=?',
                [$importId]
            );
            $storedAssets = (int)($storedRow['total'] ?? 0);

            if ($storedAssets !== $expectedStoredAssets) {
                throw new RuntimeException(
                    'La carga de Almacén quedó incompleta: se esperaban '
                    . $expectedStoredAssets . ' activos únicos y se almacenaron '
                    . $storedAssets . '.'
                );
            }

            if ($pdo->inTransaction()) {
                $pdo->commit();
            } else {
                /*
                 * MySQL puede reportar una confirmación implícita en ciertas
                 * condiciones operativas. Como la cantidad almacenada ya fue
                 * verificada, la importación puede continuar sin lanzar un
                 * falso error de "There is no active transaction".
                 */
                error_log(
                    'SIVI warehouse import: la transacción inicial ya no estaba '
                    . 'activa; se verificaron ' . $storedAssets
                    . ' activos persistidos para import_id=' . $importId
                );
            }

            $sedes = Database::fetchAll('SELECT id,identificador,cod_dd,departamento,municipio,tipo_sede,nombre_sede FROM sedes ORDER BY departamento,municipio,nombre_sede');
            $preparedSedes = WarehouseSedeAssociator::prepare($sedes);

            // Primero se cruza Almacén con GLPI. Nombre Sucursal y Centro de Costo
            // solo complementan la sede cuando GLPI no la resolvió claramente.
            $matchStats = self::reconcileAll($importId, $preparedSedes);
            // Después se incorporan todos los activos patrimoniales que no tuvieron
            // cruce con GLPI, usando la información territorial de Almacén.
            $warehouseOnlyStats = self::syncWarehouseUnmatchedAssets($importId, $preparedSedes);
            // Regla institucional: todo serial repetido en el inventario activo se
            // elimina de los registros implicados y queda pendiente de verificación física.
            $serialCleanup = SerialIntegrity::clearActiveDuplicates();

            Database::execute(
                'UPDATE warehouse_imports SET rows_assets=?,rows_with_internal_serial=?,rows_invalid_plates=?,rows_selected_categories=?,warehouse_only_equipment=?,warehouse_only_assigned=?,warehouse_exact_assigned=?,warehouse_department_assigned=?,warehouse_unassigned=?,warehouse_glpi_enhanced=?,matched_equipment=?,ambiguous_equipment=?,unmatched_equipment=?,status="completado",completed_at=NOW() WHERE id=?',
                [
                    $rows,
                    $withInternalSerial,
                    $invalidPlates,
                    $selectedCategories,
                    $warehouseOnlyStats['warehouseOnly'],
                    $warehouseOnlyStats['warehouseAssigned'],
                    $warehouseOnlyStats['warehouseExactAssigned'],
                    $warehouseOnlyStats['warehouseDepartmentAssigned'],
                    $warehouseOnlyStats['warehouseUnassigned'],
                    $matchStats['warehouseEnhancedGlpi'],
                    $matchStats['matched'],
                    $matchStats['ambiguous'],
                    $matchStats['unmatched'],
                    $importId,
                ]
            );
            $duplicateSerialGroupsCleared = (int)$serialCleanup['duplicate_groups'];
            $duplicateEquipmentSerialsCleared = (int)$serialCleanup['cleared_equipment'];
            $result = array_merge(
                compact('importId', 'rows', 'storedAssets', 'withInternalSerial', 'invalidPlates', 'selectedCategories', 'categoryCounts', 'duplicateSerialGroupsCleared', 'duplicateEquipmentSerialsCleared'),
                $warehouseOnlyStats,
                $matchStats
            );
            audit('import_warehouse_inventory', 'warehouse_import', $importId, null, $result);
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $reference = log_exception_reference($e, 'warehouse_import_internal');
            Database::execute('UPDATE warehouse_imports SET status="error",error_message=?,completed_at=NOW() WHERE id=?', [safe_error_message('Error interno de importación', $reference), $importId]);
            throw $e;
        }
    }

    /**
     * Limpia cualquier conciliación patrimonial anterior de los computadores GLPI.
     * Se usa al completar la etapa 2 para garantizar que la etapa 3 sea obligatoria.
     */
    public static function resetComputerReconciliation(): void
    {
        Database::execute(
            "UPDATE equipment SET placa_almacen=NULL,warehouse_asset_id=NULL,warehouse_match_status='pendiente',warehouse_match_count=0,warehouse_matched_at=NULL,category_source='glpi' WHERE active=1 AND source_origin='glpi' AND asset_category IN ('cpu','portatil','pc_todo_en_uno')"
        );
    }

    /**
     * Conciliación de equipos GLPI contra el inventario actual de Almacén.
     * Si GLPI no resolvió la sede o solo tenía una asignación provisional, se
     * permite que Sucursal/Centro de Costo complete la asociación territorial.
     *
     * @param array<string,mixed> $preparedSedes
     */
    public static function reconcileAll(?int $importId = null, ?array $preparedSedes = null): array
    {
        if ($importId === null) {
            $latest = Database::fetchOne("SELECT id FROM warehouse_imports WHERE status IN ('procesando','completado') ORDER BY id DESC LIMIT 1");
            if (!$latest) {
                return [
                    'matched'=>0,
                    'ambiguous'=>0,
                    'unmatched'=>0,
                    'withoutSerial'=>0,
                    'categorizedByWarehouse'=>0,
                    'warehouseEnhancedGlpi'=>0,
                ];
            }
            $importId = (int)$latest['id'];
        }
        if ($preparedSedes === null) {
            $sedes = Database::fetchAll('SELECT id,identificador,cod_dd,departamento,municipio,tipo_sede,nombre_sede FROM sedes ORDER BY departamento,municipio,nombre_sede');
            $preparedSedes = WarehouseSedeAssociator::prepare($sedes);
        }
        $equipment = Database::fetchAll(
            "SELECT id,serial_number,placa_rnec,asset_category,current_sede_id,association_method,association_confidence,association_evidence,association_review_required FROM equipment WHERE active=1 AND source_origin<>'almacen'"
        );
        $matched = 0;
        $ambiguous = 0;
        $unmatched = 0;
        $withoutSerial = 0;
        $categorizedByWarehouse = 0;
        $warehouseEnhancedGlpi = 0;

        foreach ($equipment as $item) {
            $serial = self::normalizeSerial((string)$item['serial_number']);
            if ($serial === '') {
                Database::execute("UPDATE equipment SET placa_almacen=NULL,warehouse_asset_id=NULL,warehouse_match_status='sin_serial',warehouse_match_count=0,warehouse_matched_at=NOW() WHERE id=?", [(int)$item['id']]);
                $withoutSerial++;
                continue;
            }

            $matches = Database::fetchAll(
                "SELECT *, CASE WHEN serial_internal_normalized=? THEN 'serial_interno' ELSE 'referencia' END match_source
                 FROM warehouse_assets
                 WHERE import_id=? AND (serial_internal_normalized=? OR reference_normalized=?)
                 ORDER BY CASE WHEN serial_internal_normalized=? THEN 0 ELSE 1 END, id",
                [$serial, $importId, $serial, $serial, $serial]
            );
            $uniqueAssets = [];
            foreach ($matches as $match) $uniqueAssets[(string)$match['warehouse_key']] = $match;

            if (count($uniqueAssets) === 1) {
                $asset = array_values($uniqueAssets)[0];
                $status = $asset['match_source'] === 'serial_interno' ? 'coincidencia_serial' : 'coincidencia_referencia';
                $warehouseCategory = in_array((string)$asset['asset_category'], self::CATEGORIES, true)
                    ? (string)$asset['asset_category']
                    : null;
                $association = WarehouseSedeAssociator::associate((string)$asset['branch'], (string)$asset['cost_center'], $preparedSedes);

                $finalSedeId = !empty($item['current_sede_id']) ? (int)$item['current_sede_id'] : null;
                $finalMethod = (string)$item['association_method'];
                $finalConfidence = (string)$item['association_confidence'];
                $finalEvidence = $item['association_evidence'];
                $finalReview = (int)$item['association_review_required'];

                if (self::canWarehouseImproveGlpi($item, $association)) {
                    $finalSedeId = (int)$association['sede_id'];
                    $finalMethod = 'warehouse';
                    $finalConfidence = (string)$association['confidence'];
                    $finalEvidence = self::evidenceJson(array_merge((array)$association['evidence'], [
                        'source' => 'almacen_complementa_glpi',
                        'glpi_association_before' => [
                            'sede_id' => $item['current_sede_id'],
                            'method' => $item['association_method'],
                            'confidence' => $item['association_confidence'],
                            'review_required' => (bool)$item['association_review_required'],
                        ],
                    ]));
                    $finalReview = (int)(bool)$association['review_required'];
                    $warehouseEnhancedGlpi++;
                }

                $categorySql = '';
                $params = [
                    $asset['placa_rnec'],
                    (int)$asset['id'],
                    $status,
                    count($matches),
                ];
                if ($warehouseCategory !== null) {
                    $categorySql = ',asset_category=?,category_source="almacen"';
                    $params[] = $warehouseCategory;
                    $categorizedByWarehouse++;
                }
                $params = array_merge($params, [
                    $finalSedeId,
                    $finalSedeId,
                    $finalMethod,
                    $finalConfidence,
                    $finalEvidence,
                    $finalReview,
                    (int)$item['id'],
                ]);
                Database::execute(
                    'UPDATE equipment SET placa_almacen=?,warehouse_asset_id=?,warehouse_match_status=?,warehouse_match_count=?,warehouse_matched_at=NOW()' . $categorySql . ',original_sede_id=?,current_sede_id=?,association_method=?,association_confidence=?,association_evidence=?,association_review_required=? WHERE id=?',
                    $params
                );

                self::updateWarehouseAssociation(
                    (int)$asset['id'],
                    $finalSedeId,
                    'cruce_glpi_' . $status,
                    $finalSedeId !== null ? $finalConfidence : 'sin_asignar',
                    self::evidenceJson([
                        'rule' => 'cruce_glpi_' . $status,
                        'equipment_id' => (int)$item['id'],
                        'serial_glpi' => (string)$item['serial_number'],
                        'nombre_sucursal' => (string)$asset['branch'],
                        'nombre_centro_costo' => (string)$asset['cost_center'],
                        'association_source' => $finalMethod,
                    ]),
                    $finalSedeId === null ? 1 : $finalReview
                );
                $matched++;
            } elseif (count($uniqueAssets) > 1) {
                Database::execute("UPDATE equipment SET placa_almacen=NULL,warehouse_asset_id=NULL,warehouse_match_status='ambigua',warehouse_match_count=?,warehouse_matched_at=NOW() WHERE id=?", [count($uniqueAssets), (int)$item['id']]);
                $ambiguous++;
            } else {
                Database::execute("UPDATE equipment SET placa_almacen=NULL,warehouse_asset_id=NULL,warehouse_match_status='no_encontrada',warehouse_match_count=0,warehouse_matched_at=NOW() WHERE id=?", [(int)$item['id']]);
                $unmatched++;
            }
        }
        return compact('matched', 'ambiguous', 'unmatched', 'withoutSerial', 'categorizedByWarehouse', 'warehouseEnhancedGlpi');
    }

    /**
     * Incorpora todos los activos patrimoniales seleccionados que no pudieron
     * cruzarse de forma única con GLPI. Nombre Sucursal y Nombre Centro de Costo
     * se utilizan para determinar la sede exacta o, como contingencia, el nivel
     * territorial más probable.
     *
     * @param array<string,mixed> $preparedSedes
     */
    private static function syncWarehouseUnmatchedAssets(int $importId, array $preparedSedes): array
    {
        Database::execute("UPDATE equipment SET active=0 WHERE source_origin='almacen'");

        $linked = Database::fetchAll("SELECT warehouse_asset_id FROM equipment WHERE active=1 AND source_origin<>'almacen' AND warehouse_asset_id IS NOT NULL");
        $linkedIds = [];
        foreach ($linked as $row) $linkedIds[(int)$row['warehouse_asset_id']] = true;

        $assets = Database::fetchAll(
            "SELECT * FROM warehouse_assets WHERE import_id=? AND asset_category IN ('cpu','portatil','pc_todo_en_uno','monitor','impresora','escaner','ups') ORDER BY id",
            [$importId]
        );
        $warehouseOnly = 0;
        $warehouseAssigned = 0;
        $warehouseExactAssigned = 0;
        $warehouseDepartmentAssigned = 0;
        $warehouseUnassigned = 0;

        foreach ($assets as $asset) {
            $assetId = (int)$asset['id'];
            if (isset($linkedIds[$assetId])) continue;

            $association = WarehouseSedeAssociator::associate(
                (string)$asset['branch'],
                (string)$asset['cost_center'],
                $preparedSedes
            );
            $sedeId = $association['sede_id'] !== null ? (int)$association['sede_id'] : null;
            $rule = (string)($association['evidence']['rule'] ?? 'almacen_sin_coincidencia_territorial');
            $confidence = (string)$association['confidence'];
            $reviewRequired = (int)(bool)$association['review_required'];
            $associationEvidence = self::evidenceJson((array)$association['evidence']);

            self::updateWarehouseAssociation($assetId, $sedeId, $rule, $confidence, $associationEvidence, $reviewRequired);

            if ($sedeId !== null) {
                $warehouseAssigned++;
                if (str_contains($rule, 'contingencia')) $warehouseDepartmentAssigned++;
                else $warehouseExactAssigned++;
            } else {
                $warehouseUnassigned++;
            }

            $serial = trim((string)$asset['serial_internal']);
            if (self::normalizeSerial($serial) === '') $serial = trim((string)$asset['warehouse_serial']);
            if (self::normalizeSerial($serial) === '') $serial = trim((string)$asset['reference']);

            $category = (string)$asset['asset_category'];
            $name = trim((string)$asset['description']);
            if ($name === '') $name = asset_category_label($category) . ' ' . (string)($asset['placa_rnec'] ?: $asset['placa_raw']);
            $sourceKey = hash('sha256', 'almacen|activo|' . (string)$asset['warehouse_key']);
            $location = trim(implode(' / ', array_filter([(string)$asset['branch'], (string)$asset['cost_center']])));
            $assignedUser = trim((string)($asset['holder'] ?: $asset['responsible']));
            $inventoryStatus = self::inventoryStatus((string)$asset['current_state'], (string)$asset['state_code'], (string)$asset['branch']);
            $existing = Database::fetchOne('SELECT id,current_sede_id,serial_number,serial_verified_at,association_method,association_confidence,association_evidence,association_review_required FROM equipment WHERE source_key=? ORDER BY id LIMIT 1', [$sourceKey]);

            $finalSedeId = $sedeId;
            $finalMethod = $sedeId !== null ? 'warehouse' : 'unassigned';
            $finalConfidence = $confidence;
            $finalEvidence = $associationEvidence;
            $finalReview = $reviewRequired;

            if ($existing && ($existing['association_method'] ?? '') === 'manual' && !empty($existing['current_sede_id'])) {
                $finalSedeId = (int)$existing['current_sede_id'];
                $finalMethod = 'manual';
                $finalConfidence = (string)($existing['association_confidence'] ?: 'alta');
                $finalEvidence = $existing['association_evidence'] ?: self::evidenceJson(['rule'=>'asignacion_manual_previa']);
                $finalReview = (int)($existing['association_review_required'] ?? 0);
            }

            $values = [
                $sourceKey,
                $name,
                $assignedUser,
                (string)$asset['current_state'],
                (string)$asset['brand'],
                $serial,
                $serial !== '' ? $serial : null,
                asset_category_label($category),
                $category,
                'almacen',
                'almacen',
                (string)$asset['reference'],
                $location,
                (string)$asset['placa_rnec'],
                $assetId,
                'origen_almacen',
                1,
                $finalSedeId,
                $finalSedeId,
                $finalMethod,
                $finalConfidence,
                $finalEvidence,
                $finalReview,
                $inventoryStatus,
            ];

            if ($existing) {
                $values[] = (int)$existing['id'];
                Database::execute(
                    "UPDATE equipment SET import_id=NULL,source_key=?,name=?,alternate_user=?,source_state=?,manufacturer=?,serial_number=IF(serial_verified_at IS NOT NULL AND NULLIF(TRIM(serial_number),'') IS NOT NULL,serial_number,?),serial_source_original=?,serial_review_required=IF(serial_verified_at IS NOT NULL AND NULLIF(TRIM(serial_number),'') IS NOT NULL,0,serial_review_required),serial_review_reason=IF(serial_verified_at IS NOT NULL AND NULLIF(TRIM(serial_number),'') IS NOT NULL,NULL,serial_review_reason),equipment_type=?,asset_category=?,category_source=?,source_origin=?,model=?,source_location=?,placa_almacen=?,warehouse_asset_id=?,warehouse_match_status=?,warehouse_match_count=?,warehouse_matched_at=NOW(),original_sede_id=?,current_sede_id=?,association_method=?,association_confidence=?,association_evidence=?,association_review_required=?,inventory_status=?,active=1,updated_at=NOW() WHERE id=?", 
                    $values
                );
            } else {
                Database::execute(
                    'INSERT INTO equipment (import_id,source_key,name,alternate_user,source_state,manufacturer,serial_number,serial_source_original,equipment_type,asset_category,category_source,source_origin,model,source_location,placa_almacen,warehouse_asset_id,warehouse_match_status,warehouse_match_count,warehouse_matched_at,original_sede_id,current_sede_id,association_method,association_confidence,association_evidence,association_review_required,inventory_status,active) VALUES (NULL,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?,?,?,?,?,?,?,1)',
                    $values
                );
            }
            $warehouseOnly++;
        }
        return compact('warehouseOnly', 'warehouseAssigned', 'warehouseExactAssigned', 'warehouseDepartmentAssigned', 'warehouseUnassigned');
    }

    private static function canWarehouseImproveGlpi(array $item, array $association): bool
    {
        if ($association['sede_id'] === null) return false;
        if (($item['association_method'] ?? '') === 'manual') return false;
        if (empty($item['current_sede_id']) || ($item['association_method'] ?? '') === 'unassigned') return true;

        $provisional = in_array((string)($item['association_method'] ?? ''), ['fallback_distrital','fallback_delegacion'], true)
            || (int)($item['association_review_required'] ?? 0) === 1
            || (string)($item['association_confidence'] ?? '') === 'baja';
        return $provisional && in_array((string)$association['confidence'], ['alta','media'], true) && !$association['review_required'];
    }

    private static function updateWarehouseAssociation(
        int $assetId,
        ?int $sedeId,
        string $rule,
        string $confidence,
        ?string $evidence,
        int $reviewRequired
    ): void {
        Database::execute(
            'UPDATE warehouse_assets SET associated_sede_id=?,association_rule=?,association_confidence=?,association_evidence=?,association_review_required=?,updated_at=NOW() WHERE id=?',
            [$sedeId, $rule, $confidence, $evidence, $reviewRequired, $assetId]
        );
    }

    private static function evidenceJson(array $evidence): ?string
    {
        $json = json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? null : $json;
    }

    /** El campo Nombre Producto del inventario de Almacén es la fuente oficial. */
    public static function categoryFromWarehouseProduct(string $productName): ?string
    {
        $value = self::normalizeText($productName);
        return match (true) {
            $value === 'cpu' => 'cpu',
            $value === 'computador portatil' => 'portatil',
            $value === 'computador todo en uno' => 'pc_todo_en_uno',
            str_starts_with($value, 'monitor') => 'monitor',
            $value === 'impresora' => 'impresora',
            $value === 'scanner' => 'escaner',
            $value === 'ups' => 'ups',
            default => null,
        };
    }

    private static function warehouseKey(string $plateRaw): string
    {
        $normalized = strtoupper(trim($plateRaw));
        $normalized = preg_replace('/[^A-Z0-9]+/', '', $normalized) ?? '';
        return hash('sha256', 'placa-raw|' . $normalized);
    }

    private static function detectSheet(array $names): ?string
    {
        foreach ($names as $name) {
            $n = self::normalizeText($name);
            if (str_contains($n, 'inventario pc')) return $name;
        }
        return null;
    }

    private static function value(array $row, array $keys): mixed
    {
        foreach ($keys as $key) if (array_key_exists($key, $row)) return $row[$key];
        $normalized = [];
        foreach ($row as $key => $value) $normalized[self::normalizeText((string)$key)] = $value;
        foreach ($keys as $key) {
            $n = self::normalizeText($key);
            if (array_key_exists($n, $normalized)) return $normalized[$n];
        }
        return '';
    }

    public static function normalizeSerial(string $value): string
    {
        $value = strtoupper(trim($value));
        $value = preg_replace('/[^A-Z0-9]+/', '', $value) ?? '';
        return in_array($value, ['', '0', '00000000', 'NA', 'NULO', 'SINSERIAL', 'SINNUMERO', 'NOAPLICA', 'SN', 'UNKNOWN', 'DESCONOCIDO'], true) ? '' : $value;
    }

    private static function inventoryStatus(string $currentState, string $stateCode, string $branch): string
    {
        $value = self::normalizeText($currentState . ' ' . $stateCode . ' ' . $branch);
        return match (true) {
            str_contains($value, 'baja') || str_contains($value, 'retirado') => 'dado_baja',
            str_contains($value, 'almacen') || str_contains($value, 'bienes no explotados') => 'en_almacen',
            str_contains($value, 'inactiv') => 'inactivo',
            str_contains($value, 'servicio') || str_contains($value, 'bueno') || str_contains($value, ' ok ') || $value === 'ok' => 'activo',
            default => 'desconocido',
        };
    }

    private static function normalizeText(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace(['á','é','í','ó','ú','ü','ñ'], ['a','e','i','o','u','u','n'], $value);
        return trim((string)(preg_replace('/[^a-z0-9]+/', ' ', $value) ?? ''));
    }
}
