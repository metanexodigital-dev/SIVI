<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: src/Importer.php
 * Propósito: Procesa las importaciones principales de inventario y aplica las reglas de validación.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

final class Importer
{
    /**
     * Importación principal: archivo que contiene inventario GLPI y maestro de sedes.
     * También reconoce monitores e impresoras cuando vienen mezclados en la hoja.
     */
    public static function import(string $path, string $originalName, int $userId): array
    {
        $pdo = Database::connection();
        $importId = self::createImport($path, $originalName, $userId, 'base');

        try {
            $reader = new XlsxReader($path);
            [$inventorySheet, $sedesSheet] = self::detectSheets($reader->sheetNames());
            if (!$inventorySheet) {
                throw new RuntimeException('No se encontró una hoja de inventario utilizable. Renombre la hoja para incluir Inventario, Equipos, Computadores, Monitores o Impresoras.');
            }

            $pdo->beginTransaction();
            $sedeCount = 0;
            if ($sedesSheet) {
                $sedeCount = self::importSedes($reader, $sedesSheet);
                if ($sedeCount === 0) {
                    throw new RuntimeException('La hoja de sedes fue encontrada, pero no contiene encabezados reconocidos. Debe incluir Id Sede, Departamento, Municipio, Tipo de Sede y Nombre de Sede.');
                }
            } elseif ((int)(Database::fetchOne('SELECT COUNT(*) total FROM sedes')['total'] ?? 0) === 0) {
                $sedeCount = self::importBundledSedeMaster();
                if ($sedeCount === 0) {
                    throw new RuntimeException('El archivo no contiene una hoja de sedes y la aplicación aún no tiene un maestro territorial cargado.');
                }
            }
            $sedeMap = self::sedeMap();
            [$equipmentCount, $assigned, $categoryCounts, $serialStats, $associationStats, $reviewRequired] = self::importEquipment(
                $reader,
                $inventorySheet,
                $importId,
                $sedeMap,
                null
            );
            $serialCleanup = SerialIntegrity::clearActiveDuplicates();
            $serialStats['active_duplicate_groups_cleared'] = (int)$serialCleanup['duplicate_groups'];
            $serialStats['active_equipment_serials_cleared'] = (int)$serialCleanup['cleared_equipment'];
            if ($equipmentCount === 0) {
                throw new RuntimeException('No se identificaron activos en la hoja de inventario. Verifique que la fila de encabezados incluya Nombre o Hostname y Número de serie.');
            }
            $unassigned = $equipmentCount - $assigned;
            WarehouseImporter::reconcileAll();
            self::completeImport($importId, $sedeCount, $equipmentCount, $assigned, $unassigned, $associationStats, $reviewRequired);
            $pdo->commit();
            audit('import_inventory', 'import', $importId, null, compact('sedeCount', 'equipmentCount', 'assigned', 'unassigned', 'categoryCounts', 'serialStats', 'associationStats', 'reviewRequired'));
            return compact('importId', 'sedeCount', 'equipmentCount', 'assigned', 'unassigned', 'categoryCounts', 'serialStats', 'associationStats', 'reviewRequired');
        } catch (Throwable $e) {
            self::failImport($pdo, $importId, $e, 'glpi_import_internal');
            throw $e;
        }
    }

    /**
     * Importa un reporte GLPI separado de computadores, monitores o impresoras.
     * El reporte no necesita incluir el maestro de sedes.
     */
    public static function importAssetReport(string $path, string $originalName, int $userId, string $category, bool $reconcileWarehouse = true): array
    {
        $category = self::validateRequestedCategory($category);
        $sourceKind = match ($category) {
            'computador' => 'computadores',
            'monitor' => 'monitores',
            'impresora' => 'impresoras',
            default => 'mixto',
        };
        $pdo = Database::connection();
        $importId = self::createImport($path, $originalName, $userId, $sourceKind);

        try {
            $reader = new XlsxReader($path);
            $sheet = self::detectAssetSheet($reader->sheetNames(), $category);
            if (!$sheet) {
                throw new RuntimeException('No se encontró una hoja de inventario utilizable en el reporte GLPI.');
            }

            $pdo->beginTransaction();
            // El reporte complementario se trata como una fotografía completa de la categoría.
            // Se conservan los registros históricos, pero quedan inactivos hasta que vuelvan a aparecer.
            if ($category === 'computador') {
                Database::execute("UPDATE equipment SET active=0 WHERE source_origin='glpi' AND asset_category IN ('cpu','portatil','pc_todo_en_uno')");
            } else {
                Database::execute("UPDATE equipment SET active=0 WHERE source_origin='glpi' AND asset_category=?", [$category]);
            }
            $sedeMap = self::sedeMap();
            [$equipmentCount, $assigned, $categoryCounts, $serialStats, $associationStats, $reviewRequired] = self::importEquipment(
                $reader,
                $sheet,
                $importId,
                $sedeMap,
                $category
            );
            $serialCleanup = SerialIntegrity::clearActiveDuplicates();
            $serialStats['active_duplicate_groups_cleared'] = (int)$serialCleanup['duplicate_groups'];
            $serialStats['active_equipment_serials_cleared'] = (int)$serialCleanup['cleared_equipment'];
            if ($equipmentCount === 0) {
                throw new RuntimeException('No se identificaron registros de activos en la hoja seleccionada.');
            }
            $unassigned = $equipmentCount - $assigned;
            if ($reconcileWarehouse) {
                WarehouseImporter::reconcileAll();
            } elseif ($category === 'computador') {
                WarehouseImporter::resetComputerReconciliation();
            }
            self::completeImport($importId, 0, $equipmentCount, $assigned, $unassigned, $associationStats, $reviewRequired);
            $pdo->commit();
            audit('import_glpi_asset_report', 'import', $importId, null, compact('category', 'sheet', 'equipmentCount', 'assigned', 'unassigned', 'categoryCounts', 'serialStats', 'associationStats', 'reviewRequired'));
            return compact('importId', 'category', 'sheet', 'equipmentCount', 'assigned', 'unassigned', 'categoryCounts', 'serialStats', 'associationStats', 'reviewRequired');
        } catch (Throwable $e) {
            self::failImport($pdo, $importId, $e, 'glpi_asset_report_internal');
            throw $e;
        }
    }

    private static function createImport(string $path, string $originalName, int $userId, string $sourceKind): int
    {
        $storedName = basename($path);
        $hash = hash_file('sha256', $path) ?: null;
        Database::execute(
            'INSERT INTO imports (filename,original_name,file_hash,source_kind,status,created_by) VALUES (?,?,?,?,?,?)',
            [$storedName, $originalName, $hash, $sourceKind, 'procesando', $userId]
        );
        return (int)Database::connection()->lastInsertId();
    }

    private static function completeImport(
        int $importId,
        int $sedeCount,
        int $equipmentCount,
        int $assigned,
        int $unassigned,
        array $associationStats,
        int $reviewRequired
    ): void {
        Database::execute(
            'UPDATE imports SET rows_sedes=?,rows_equipment=?,assigned_equipment=?,unassigned_equipment=?,association_summary_json=?,review_required_equipment=?,status="completado",completed_at=NOW() WHERE id=?',
            [
                $sedeCount,
                $equipmentCount,
                $assigned,
                $unassigned,
                json_encode($associationStats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $reviewRequired,
                $importId,
            ]
        );
    }

    private static function failImport(PDO $pdo, int $importId, Throwable $e, string $context): void
    {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $reference = log_exception_reference($e, $context);
        Database::execute(
            'UPDATE imports SET status="error",error_message=?,completed_at=NOW() WHERE id=?',
            [safe_error_message('Error interno de importación', $reference), $importId]
        );
    }

    private static function detectSheets(array $names): array
    {
        $inventory = null;
        $sedes = null;
        $fallbackInventory = null;
        foreach ($names as $name) {
            $normalized = self::normalize($name);
            if (str_contains($normalized, 'sede')) {
                $sedes = $name;
                continue;
            }
            $excluded = str_contains($normalized, 'instruccion')
                || str_contains($normalized, 'catalogo')
                || str_contains($normalized, 'control')
                || str_contains($normalized, 'correccion')
                || str_contains($normalized, 'resumen');
            if (!$excluded && $fallbackInventory === null) {
                $fallbackInventory = $name;
            }
            if ($inventory === null && (
                str_contains($normalized, 'inventario')
                || str_contains($normalized, 'equipo')
                || str_contains($normalized, 'computador')
                || str_contains($normalized, 'monitor')
                || str_contains($normalized, 'impresora')
                || $normalized === 'glpi'
            )) {
                $inventory = $name;
            }
        }
        return [$inventory ?: $fallbackInventory, $sedes];
    }

    private static function importBundledSedeMaster(): int
    {
        $masterPath = dirname(__DIR__) . '/docs/Sedes-RNEC-MAESTRO.xlsx';
        if (!is_file($masterPath)) return 0;
        $reader = new XlsxReader($masterPath);
        [, $sheet] = self::detectSheets($reader->sheetNames());
        if (!$sheet) return 0;
        return self::importSedes($reader, $sheet);
    }

    private static function detectAssetSheet(array $names, string $category): ?string
    {
        $tokens = match ($category) {
            'monitor' => ['monitor', 'pantalla'],
            'impresora' => ['impresora', 'printer', 'multifuncional', 'plotter'],
            'computador' => ['computador', 'equipo', 'pc', 'inventario'],
            default => ['inventario', 'equipo'],
        };
        foreach ($names as $name) {
            $normalized = self::normalize($name);
            foreach ($tokens as $token) {
                if (str_contains($normalized, $token)) return $name;
            }
        }
        foreach ($names as $name) {
            $normalized = self::normalize($name);
            if (!str_contains($normalized, 'sede') && !str_contains($normalized, 'instruccion') && !str_contains($normalized, 'catalogo')) {
                return $name;
            }
        }
        return null;
    }

    private static function importSedes(XlsxReader $reader, string $sheet): int
    {
        $count = 0;
        $sql = 'INSERT INTO sedes (identificador,cod_dd,departamento,cod_mm,municipio,tipo_sede,nombre_sede,direccion_original,direccion_actual)
                VALUES (?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE cod_dd=VALUES(cod_dd),departamento=VALUES(departamento),cod_mm=VALUES(cod_mm),municipio=VALUES(municipio),tipo_sede=VALUES(tipo_sede),nombre_sede=VALUES(nombre_sede),direccion_original=VALUES(direccion_original),direccion_actual=COALESCE(NULLIF(direccion_actual,""),VALUES(direccion_actual))';
        $stmt = Database::connection()->prepare($sql);
        foreach ($reader->rows($sheet) as $row) {
            $id = trim((string)self::value($row, ['db_Identificador_Sede', 'Identificador', 'Identificador Sede', 'Id Sede', 'ID Sede', 'Id_Sede']));
            if ($id === '') continue;
            $address = trim((string)self::value($row, ['db_Direccion', 'Dirección', 'Direccion', 'Dirección Sede', 'Direccion Sede']));
            $stmt->execute([
                $id,
                trim((string)self::value($row, ['db_Cod_DD', 'Cod_DD', 'Cod Departamento', 'Código Departamento', 'Codigo Departamento'])),
                trim((string)self::value($row, ['db_DEPARTAMENTO', 'Departamento', 'Nombre Departamento'])),
                trim((string)self::value($row, ['db_Cod_MM', 'Cod_MM', 'Cod Municipio', 'Código Municipio', 'Codigo Municipio'])),
                trim((string)self::value($row, ['db_MUNICIPIO', 'Municipio', 'Nombre Municipio'])),
                trim((string)self::value($row, ['db_TIPO_SEDE', 'Tipo Sede', 'Tipo de Sede'])),
                trim((string)self::value($row, ['db_Nombre_Sede', 'Nombre Sede', 'Nombre de Sede'])),
                $address,
                $address,
            ]);
            $count++;
        }
        return $count;
    }

    private static function importEquipment(XlsxReader $reader, string $sheet, int $importId, array $sedeMap, ?string $categoryOverride): array
    {
        $count = 0;
        $assigned = 0;
        $categoryCounts = array_fill_keys(['cpu','portatil','pc_todo_en_uno','monitor','impresora','escaner','ups','otro'], 0);
        $associationStats = array_fill_keys(['hostname','usuario','location','fallback_distrital','fallback_delegacion','unassigned'], 0);
        $reviewRequired = 0;
        $fingerprintOccurrences = [];
        [$serialFrequencies, $serialStats] = self::scanSerials($reader, $sheet, $categoryOverride);
        // El catálogo territorial se normaliza una sola vez por importación.
        // Esto evita repetir el procesamiento de las 1.260 sedes por cada activo GLPI.
        $preparedSedes = SedeAssociator::prepare($sedeMap);

        foreach ($reader->rows($sheet) as $row) {
            $name = trim((string)self::value($row, [
                'Nombre', 'Hostname', 'Equipo', 'Nombre del dispositivo', 'Nombre del equipo',
                'Nombre de impresora', 'Nombre de monitor'
            ]));
            [$serial] = self::extractSerial($row);
            if ($name === '' && $serial === '') continue;

            $rawType = trim((string)self::value($row, [
                'Tipo', 'Tipo de equipo', 'Tipo de monitor', 'Tipo de impresora', 'Tipos'
            ]));
            $model = trim((string)self::value($row, ['Modelo', 'Modelos']));
            if ($categoryOverride === 'computador' && !self::isComputerInventoryRow($rawType, $name, $model, $serial)) {
                continue;
            }
            $category = $categoryOverride === 'computador'
                ? self::categoryFromType($rawType . ' ' . $name . ' ' . $model, $sheet)
                : ($categoryOverride ?: self::categoryFromType($rawType . ' ' . $name . ' ' . $model, $sheet));
            if ($categoryOverride === 'computador' && !is_computer_category($category)) {
                continue;
            }
            $categoryCounts[$category]++;
            if ($rawType === '') $rawType = self::categoryLabel($category);

            $alternateUser = trim((string)self::value($row, [
                'Nombre de usuario alternativo', 'Usuario', 'Usuarios', 'Usuario asignado', 'Responsable'
            ]));
            $location = trim((string)self::value($row, [
                'Localizaciones', 'Localización', 'Localizacion', 'Ubicación', 'Ubicacion'
            ]));
            $association = SedeAssociator::associatePrepared($name, $alternateUser, $location, $preparedSedes);
            $sedeId = $association['sede_id'];
            $method = (string)$association['method'];
            $associationConfidence = (string)$association['confidence'];
            $associationEvidence = json_encode($association['evidence'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $associationReviewRequired = !empty($association['review_required']) ? 1 : 0;
            $sourceState = trim((string)self::value($row, ['Estado (Activo, Baja, En Almacen)', 'Estado', 'Estatus']));
            $inventoryStatus = self::inventoryStatus($sourceState);
            $manufacturer = trim((string)self::value($row, ['Fabricantes', 'Fabricante', 'Manufacturer', 'Marca']));
            if ($manufacturer === '') $manufacturer = self::inferManufacturer($name);
            if ($model === '' && in_array($category, ['monitor', 'impresora'], true)) $model = $name;

            $serialIdentity = self::serialIdentityKey($serial);
            $frequencyKey = self::sourceKeyCategory($category) . '|' . $serialIdentity;
            $serialIsUnique = $serialIdentity !== '' && (($serialFrequencies[$frequencyKey] ?? 0) === 1);
            $sourceKey = self::sourceKey(self::sourceKeyCategory($category), $serial, $serialIdentity, $serialIsUnique, $name, $alternateUser, $location, $fingerprintOccurrences);
            $serialIsDuplicate = $serialIdentity !== '' && !$serialIsUnique;
            $serialForInventory = $serialIsDuplicate ? '' : $serial;
            $serialSourceOriginal = $serial !== '' ? $serial : null;
            $serialReviewRequired = $serialIsDuplicate ? 1 : 0;
            $serialReviewReason = $serialIsDuplicate ? 'duplicado' : null;
            $existing = Database::fetchOne(
                'SELECT id,current_sede_id,placa_rnec,serial_number,serial_verified_at,association_method,association_confidence,association_evidence,association_review_required FROM equipment WHERE source_key=? ORDER BY id LIMIT 1',
                [$sourceKey]
            );
            // Compatibilidad con registros previos: solo se usa el serial como llave cuando es confiable y único.
            // Esto evita sobrescribir impresoras distintas cuando GLPI repite un serial o lo exporta en notación científica.
            if (!$existing && $serialIsUnique) {
                if (is_computer_category($category)) {
                    $existing = Database::fetchOne(
                        "SELECT id,current_sede_id,placa_rnec,serial_number,serial_verified_at,association_method,association_confidence,association_evidence,association_review_required FROM equipment WHERE asset_category IN ('cpu','portatil','pc_todo_en_uno') AND serial_number=? ORDER BY id LIMIT 1",
                        [$serial]
                    );
                } else {
                    $existing = Database::fetchOne(
                        'SELECT id,current_sede_id,placa_rnec,serial_number,serial_verified_at,association_method,association_confidence,association_evidence,association_review_required FROM equipment WHERE asset_category=? AND serial_number=? ORDER BY id LIMIT 1',
                        [$category, $serial]
                    );
                }
            }

            $values = [
                $importId,
                $sourceKey,
                $name,
                $alternateUser,
                self::excelDate(self::value($row, ['Agentes - Último contacto', 'Último contacto', 'Ultimo contacto'])),
                $sourceState,
                $manufacturer,
                $serialForInventory,
                $serialSourceOriginal,
                $serialReviewRequired,
                $serialReviewReason,
                $rawType,
                $category,
                'glpi',
                'glpi',
                $model,
                trim((string)self::value($row, ['Tamaño', 'Tamano', 'Diagonal', 'Tamaño de pantalla', 'Tamano de pantalla'])),
                trim((string)self::value($row, ['Conexión', 'Conexion', 'Interfaz', 'Puerto', 'Tipo de conexión', 'Tipo de conexion'])),
                trim((string)self::value($row, ['Tecnología', 'Tecnologia', 'Tecnología de impresión', 'Tecnologia de impresion', 'Tipo de impresión', 'Tipo de impresion'])),
                trim((string)self::value($row, ['Sistema operativo - Versión'])),
                trim((string)self::value($row, ['Sistema operativo - Arquitecturas'])),
                trim((string)self::value($row, ['Sistema operativo - Nombre'])),
                trim((string)self::value($row, ['Componentes - Procesadores'])),
                self::excelDate(self::value($row, ['Última actualización', 'Ultima actualización', 'Ultima actualizacion'])),
                trim((string)self::value($row, ['Componentes - Memoria'])),
                strip_tags(str_ireplace(['<br>', '<br/>', '<br />'], "\n", (string)self::value($row, ['Volumes - Tamaño global', 'Volumes - Tamano global']))),
                $location,
                trim((string)self::value($row, ['Agentes - Public contact address', 'IP', 'Dirección IP', 'Direccion IP', 'Redes - IP'])),
                $sedeId,
                $sedeId,
                $method,
                $associationConfidence,
                $associationEvidence === false ? null : $associationEvidence,
                $associationReviewRequired,
                $inventoryStatus,
            ];

            if ($existing) {
                $values[29] = $existing['current_sede_id'] ?: $sedeId;
                // Una asociación manual o un traslado aprobado prevalecen sobre
                // una nueva deducción automática durante importaciones posteriores.
                if (($existing['association_method'] ?? '') === 'manual' && !empty($existing['current_sede_id'])) {
                    $values[30] = 'manual';
                    $values[31] = (string)($existing['association_confidence'] ?: 'alta');
                    $values[32] = $existing['association_evidence'] ?: json_encode(['rule' => 'asignacion_manual_previa'], JSON_UNESCAPED_UNICODE);
                    $values[33] = (int)($existing['association_review_required'] ?? 0);
                }
                if (!empty($values[29])) $assigned++;
                $finalMethod = (string)$values[30];
                if (!array_key_exists($finalMethod, $associationStats)) $associationStats[$finalMethod] = 0;
                $associationStats[$finalMethod]++;
                if ((int)$values[33] === 1) $reviewRequired++;
                $values[] = (int)$existing['id'];
                Database::execute(
                    "UPDATE equipment SET import_id=?,source_key=?,name=?,alternate_user=?,last_contact=?,source_state=?,manufacturer=?,serial_number=IF(serial_verified_at IS NOT NULL AND NULLIF(TRIM(serial_number),'') IS NOT NULL,serial_number,?),serial_source_original=?,serial_review_required=IF(serial_verified_at IS NOT NULL AND NULLIF(TRIM(serial_number),'') IS NOT NULL,0,?),serial_review_reason=IF(serial_verified_at IS NOT NULL AND NULLIF(TRIM(serial_number),'') IS NOT NULL,NULL,?),equipment_type=?,asset_category=?,category_source=?,source_origin=?,model=?,screen_size=?,connection_type=?,print_technology=?,os_version=?,architecture=?,os_name=?,processor=?,last_update=?,memory=?,storage=?,source_location=?,ip_address=?,original_sede_id=?,current_sede_id=?,association_method=?,association_confidence=?,association_evidence=?,association_review_required=?,inventory_status=?,active=1,updated_at=NOW() WHERE id=?",
                    $values
                );
            } else {
                if (!empty($values[29])) $assigned++;
                $finalMethod = (string)$values[30];
                if (!array_key_exists($finalMethod, $associationStats)) $associationStats[$finalMethod] = 0;
                $associationStats[$finalMethod]++;
                if ((int)$values[33] === 1) $reviewRequired++;
                Database::execute(
                    'INSERT INTO equipment (import_id,source_key,name,alternate_user,last_contact,source_state,manufacturer,serial_number,serial_source_original,serial_review_required,serial_review_reason,equipment_type,asset_category,category_source,source_origin,model,screen_size,connection_type,print_technology,os_version,architecture,os_name,processor,last_update,memory,storage,source_location,ip_address,original_sede_id,current_sede_id,association_method,association_confidence,association_evidence,association_review_required,inventory_status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    $values
                );
            }
            $count++;
        }
        return [$count, $assigned, $categoryCounts, $serialStats, $associationStats, $reviewRequired];
    }

    /**
     * Recorre la hoja antes de importar para detectar seriales duplicados o no confiables.
     * Los duplicados no se usan como llave única, de modo que ninguna impresora se sobrescriba.
     */
    private static function scanSerials(XlsxReader $reader, string $sheet, ?string $categoryOverride): array
    {
        $frequencies = [];
        $stats = [
            'rows' => 0,
            'with_serial' => 0,
            'usable_for_identity' => 0,
            'scientific_notation' => 0,
            'generic_serial_flag_ignored' => 0,
            'duplicate_values' => 0,
            'duplicate_rows' => 0,
            'duplicate_records_cleared' => 0,
            'skipped_non_computer' => 0,
        ];

        foreach ($reader->rows($sheet) as $row) {
            $name = trim((string)self::value($row, [
                'Nombre', 'Hostname', 'Equipo', 'Nombre del dispositivo', 'Nombre del equipo',
                'Nombre de impresora', 'Nombre de monitor'
            ]));
            [$serial, $ignoredGenericFlag] = self::extractSerial($row);
            if ($name === '' && $serial === '') continue;
            $rawType = trim((string)self::value($row, [
                'Tipo', 'Tipo de equipo', 'Tipo de monitor', 'Tipo de impresora', 'Tipos'
            ]));
            $model = trim((string)self::value($row, ['Modelo', 'Modelos']));
            if ($categoryOverride === 'computador' && !self::isComputerInventoryRow($rawType, $name, $model, $serial)) {
                $stats['skipped_non_computer']++;
                continue;
            }
            $stats['rows']++;
            if ($serial !== '') $stats['with_serial']++;
            if ($ignoredGenericFlag) $stats['generic_serial_flag_ignored']++;
            if (self::isScientificSerial($serial)) $stats['scientific_notation']++;

            $category = $categoryOverride === 'computador'
                ? self::categoryFromType($rawType . ' ' . $name . ' ' . $model, $sheet)
                : ($categoryOverride ?: self::categoryFromType($rawType . ' ' . $name . ' ' . $model, $sheet));
            if ($categoryOverride === 'computador' && !is_computer_category($category)) {
                continue;
            }
            $identity = self::serialIdentityKey($serial);
            if ($identity === '') continue;
            $stats['usable_for_identity']++;
            $key = self::sourceKeyCategory($category) . '|' . $identity;
            $frequencies[$key] = ($frequencies[$key] ?? 0) + 1;
        }

        foreach ($frequencies as $frequency) {
            if ($frequency > 1) {
                $stats['duplicate_values']++;
                $stats['duplicate_rows'] += $frequency - 1;
                $stats['duplicate_records_cleared'] += $frequency;
            }
        }
        return [$frequencies, $stats];
    }

    /**
     * Prioriza la columna "Número de serie". La columna genérica "Serial" de algunos
     * reportes GLPI es un indicador Sí/No y no corresponde al serial físico.
     */
    private static function extractSerial(array $row): array
    {
        $primary = trim((string)self::value($row, [
            'Número de serie', 'Numero de serie', 'Número de Serie', 'Numero de Serie',
            'Serial number', 'Nro. de serie', 'Nro de serie'
        ]));
        $generic = trim((string)self::value($row, ['Serial']));
        $genericMarker = self::isBooleanMarker($generic);

        if ($primary !== '') return [$primary, $genericMarker];
        if ($generic === '' || $genericMarker) return ['', $genericMarker];
        return [$generic, false];
    }

    private static function isBooleanMarker(string $value): bool
    {
        return in_array(self::normalize($value), ['si', 'no', 'yes', 'true', 'false'], true);
    }

    private static function isScientificSerial(string $serial): bool
    {
        return preg_match('/^[+-]?\d+(?:[.,]\d+)?e[+-]?\d+$/i', trim($serial)) === 1;
    }

    private static function serialIdentityKey(string $serial): string
    {
        $serial = trim($serial);
        if ($serial === '' || self::isBooleanMarker($serial) || self::isScientificSerial($serial)) return '';
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($serial)) ?: '';
    }

    private static function validateRequestedCategory(string $category): string
    {
        $category = strtolower(trim($category));
        if (!in_array($category, ['computador', 'monitor', 'impresora'], true)) {
            throw new InvalidArgumentException('Seleccione un reporte GLPI válido: computadores, monitores o impresoras.');
        }
        return $category;
    }

    /**
     * Evita convertir accesorios, servidores virtuales o registros de software
     * del reporte GLPI en computadores físicos de SIVI.
     */
    private static function isComputerInventoryRow(string $rawType, string $name, string $model, string $serial): bool
    {
        $type = self::normalize($rawType);
        $probe = self::normalize($rawType . ' ' . $name . ' ' . $model);
        foreach (['hyper v','virtual machine','maquina virtual','docking station','dock station','rack mount','servidor','server','switch','router','monitor','pantalla','impresora','printer','scanner','scaner','escaner','ups'] as $excluded) {
            if (str_contains($probe, $excluded)) return false;
        }

        foreach (['desktop','low profile desktop','mini tower','notebook','all in one','mini pc','tower','convertible','space saving','laptop','portatil','computador','computer','workstation','aio'] as $accepted) {
            if (str_contains($type, $accepted)) return true;
        }

        // GLPI puede dejar el tipo vacío o como Unknown. Solo se admite en ese
        // caso cuando existe un serial físico utilizable y un nombre de equipo.
        return in_array($type, ['', 'unknown', 'desconocido'], true)
            && trim($name) !== ''
            && self::serialIdentityKey($serial) !== '';
    }

    private static function categoryFromType(string $type, string $sheet = ''): string
    {
        $value = self::normalize($type . ' ' . $sheet);
        // El orden es importante: Todo en Uno y Portátil deben evaluarse antes de CPU.
        if (str_contains($value, 'todo en uno') || str_contains($value, 'all in one') || str_contains($value, 'all in one') || preg_match('/(^| )aio( |$)/', $value) === 1 || preg_match('/(^| )imac( |$)/', $value) === 1) return 'pc_todo_en_uno';
        if (str_contains($value, 'portatil') || str_contains($value, 'laptop') || str_contains($value, 'notebook')) return 'portatil';
        if (str_contains($value, 'monitor') || str_contains($value, 'pantalla')) return 'monitor';
        if (str_contains($value, 'impresora') || str_contains($value, 'printer') || str_contains($value, 'multifuncional') || str_contains($value, 'plotter')) return 'impresora';
        if (str_contains($value, 'scanner') || str_contains($value, 'scaner') || str_contains($value, 'escaner')) return 'escaner';
        if (preg_match('/(^| )ups( |$)/', $value) === 1 || str_contains($value, 'uninterruptible')) return 'ups';
        return 'cpu';
    }

    private static function categoryLabel(string $category): string
    {
        return asset_category_label($category);
    }

    private static function sourceKeyCategory(string $category): string
    {
        return is_computer_category($category) ? 'computador' : $category;
    }

    private static function sedeMap(): array
    {
        return Database::fetchAll('SELECT id,identificador,cod_dd,departamento,municipio,tipo_sede,nombre_sede FROM sedes ORDER BY CHAR_LENGTH(identificador) DESC');
    }

    private static function sourceKey(string $category, string $serial, string $serialIdentity, bool $serialIsUnique, string $name, string $alternateUser, string $location, array &$occurrences): string
    {
        if ($serialIsUnique && $serialIdentity !== '') {
            return hash('sha256', $category . '|serial|' . $serialIdentity);
        }
        $serialPart = $serialIdentity !== '' ? 'serial_repetido|' . $serialIdentity : 'serial_no_confiable|' . self::normalize($serial);
        $base = $serialPart . '|' . self::normalize($name) . '|' . self::normalize($alternateUser) . '|' . self::normalize($location);
        $occurrences[$base] = ($occurrences[$base] ?? 0) + 1;
        return hash('sha256', $category . '|' . $base . '|ocurrencia|' . $occurrences[$base]);
    }

    private static function inferManufacturer(string $name): string
    {
        $value = self::normalize($name);
        $brands = [
            'brother' => 'Brother', 'hewlett packard' => 'Hewlett-Packard', 'hp ' => 'HP',
            'epson' => 'Epson', 'canon' => 'Canon', 'ricoh' => 'Ricoh', 'kyocera' => 'Kyocera',
            'lexmark' => 'Lexmark', 'xerox' => 'Xerox', 'samsung' => 'Samsung', 'zebra' => 'Zebra',
            'dell' => 'Dell', 'lenovo' => 'Lenovo', 'lg' => 'LG', 'acer' => 'Acer', 'viewsonic' => 'ViewSonic',
        ];
        foreach ($brands as $needle => $label) {
            if (str_contains(' ' . $value . ' ', ' ' . trim($needle) . ' ')) return $label;
        }
        return '';
    }

    private static function inventoryStatus(string $state): string
    {
        $value = self::normalize($state);
        return match (true) {
            str_contains($value, 'baja') => 'dado_baja',
            str_contains($value, 'almacen') => 'en_almacen',
            str_contains($value, 'inactiv') => 'inactivo',
            str_contains($value, 'activ') => 'activo',
            default => 'desconocido',
        };
    }

    private static function excelDate(mixed $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') return null;
        if (is_numeric($value) && (float)$value > 25000) {
            $seconds = ((float)$value - 25569) * 86400;
            return gmdate('Y-m-d H:i:s', (int)$seconds);
        }
        $timestamp = strtotime($value);
        return $timestamp ? date('Y-m-d H:i:s', $timestamp) : null;
    }

    private static function value(array $row, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) return $row[$key];
        }
        $normalized = [];
        foreach ($row as $key => $value) $normalized[self::normalize((string)$key)] = $value;
        foreach ($keys as $key) {
            $n = self::normalize($key);
            if (array_key_exists($n, $normalized)) return $normalized[$n];
        }
        return '';
    }

    private static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace(['á','é','í','ó','ú','ü','ñ'], ['a','e','i','o','u','u','n'], $value);
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $value);
        return $normalized ? trim($normalized) : '';
    }
}
