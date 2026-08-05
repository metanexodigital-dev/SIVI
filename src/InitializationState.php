<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: src/InitializationState.php
 * Propósito: Determina si las cargas iniciales requeridas se encuentran completas.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

/**
 * Controla la inicialización obligatoria de SIVI.
 *
 * Orden requerido:
 * 1. Maestro de Sedes.
 * 2. Inventario GLPI de computadores.
 * 3. Inventario de Almacén y conciliación patrimonial.
 *
 * Las marcas se almacenan en app_settings para no modificar la estructura
 * principal ni eliminar información existente durante una actualización.
 */
final class InitializationState
{
    private const PREFIX = 'initialization_';
    /** @var array<string,mixed>|null */
    private static ?array $requestStatusCache = null;

    /**
     * @return array{
     *   ready:bool,
     *   progress:int,
     *   next_step:int,
     *   next_label:string,
     *   sedes:array<string,mixed>,
     *   glpi:array<string,mixed>,
     *   warehouse:array<string,mixed>
     * }
     */
    public static function status(): array
    {
        if (self::$requestStatusCache !== null) {
            return self::$requestStatusCache;
        }

        $settings = self::settings();

        $sedeRows = self::countSafe('SELECT COUNT(*) total FROM sedes');
        $glpiRows = self::countSafe(
            "SELECT COUNT(*) total FROM equipment WHERE active=1 AND source_origin='glpi' AND asset_category IN ('cpu','portatil','pc_todo_en_uno')"
        );
        $warehouseRows = self::countSafe('SELECT COUNT(*) total FROM warehouse_assets');

        $sedeImportId = self::intSetting($settings, 'sedes_import_id');
        $glpiImportId = self::intSetting($settings, 'glpi_import_id');
        $warehouseImportId = self::intSetting($settings, 'warehouse_import_id');

        $sedeImport = $sedeImportId > 0
            ? self::fetchSafe('SELECT id,original_name,rows_processed,rows_created,rows_updated,status,completed_at FROM directory_imports WHERE id=?', [$sedeImportId])
            : null;
        $glpiImport = $glpiImportId > 0
            ? self::fetchSafe('SELECT id,original_name,rows_equipment,assigned_equipment,unassigned_equipment,status,completed_at FROM imports WHERE id=?', [$glpiImportId])
            : null;
        $warehouseImport = $warehouseImportId > 0
            ? self::fetchSafe('SELECT id,original_name,rows_assets,matched_equipment,ambiguous_equipment,unmatched_equipment,status,completed_at FROM warehouse_imports WHERE id=?', [$warehouseImportId])
            : null;

        $sedesComplete = self::boolSetting($settings, 'sedes_complete')
            && $sedeRows > 0
            && is_array($sedeImport)
            && ($sedeImport['status'] ?? '') === 'completado';

        $glpiComplete = $sedesComplete
            && self::boolSetting($settings, 'glpi_complete')
            && $glpiRows > 0
            && is_array($glpiImport)
            && ($glpiImport['status'] ?? '') === 'completado';

        $warehouseComplete = $glpiComplete
            && self::boolSetting($settings, 'warehouse_complete')
            && $warehouseRows > 0
            && is_array($warehouseImport)
            && ($warehouseImport['status'] ?? '') === 'completado';

        $ready = $sedesComplete && $glpiComplete && $warehouseComplete;
        $progress = $ready ? 100 : ($glpiComplete ? 67 : ($sedesComplete ? 33 : 0));
        $nextStep = $ready ? 4 : ($glpiComplete ? 3 : ($sedesComplete ? 2 : 1));
        $nextLabel = match ($nextStep) {
            1 => 'Importar Maestro de Sedes',
            2 => 'Importar computadores desde GLPI',
            3 => 'Importar Inventario de Almacén',
            default => 'Inicialización completada',
        };

        self::$requestStatusCache = [
            'ready' => $ready,
            'progress' => $progress,
            'next_step' => $nextStep,
            'next_label' => $nextLabel,
            'sedes' => [
                'complete' => $sedesComplete,
                'rows' => $sedeRows,
                'import_id' => $sedeImportId,
                'file' => (string)($sedeImport['original_name'] ?? self::stringSetting($settings, 'sedes_file')),
                'completed_at' => (string)($sedeImport['completed_at'] ?? self::stringSetting($settings, 'sedes_completed_at')),
                'details' => $sedeImport,
            ],
            'glpi' => [
                'complete' => $glpiComplete,
                'rows' => $glpiRows,
                'import_id' => $glpiImportId,
                'file' => (string)($glpiImport['original_name'] ?? self::stringSetting($settings, 'glpi_file')),
                'completed_at' => (string)($glpiImport['completed_at'] ?? self::stringSetting($settings, 'glpi_completed_at')),
                'details' => $glpiImport,
            ],
            'warehouse' => [
                'complete' => $warehouseComplete,
                'rows' => $warehouseRows,
                'import_id' => $warehouseImportId,
                'file' => (string)($warehouseImport['original_name'] ?? self::stringSetting($settings, 'warehouse_file')),
                'completed_at' => (string)($warehouseImport['completed_at'] ?? self::stringSetting($settings, 'warehouse_completed_at')),
                'details' => $warehouseImport,
            ],
        ];

        return self::$requestStatusCache;
    }

    public static function clearRequestCache(): void
    {
        self::$requestStatusCache = null;
    }

    public static function isReady(): bool
    {
        return self::status()['ready'];
    }

    public static function markSedesComplete(int $importId, int $rows, string $originalName, ?string $fileHash = null): void
    {
        if ($importId <= 0 || $rows <= 0) {
            throw new RuntimeException('La importación de sedes no produjo registros válidos.');
        }
        $now = date('Y-m-d H:i:s');
        self::setMany([
            'sedes_complete' => '1',
            'sedes_import_id' => (string)$importId,
            'sedes_rows' => (string)$rows,
            'sedes_file' => $originalName,
            'sedes_hash' => (string)($fileHash ?? ''),
            'sedes_completed_at' => $now,
            // Una nueva base territorial obliga a repetir las etapas dependientes.
            'glpi_complete' => '0',
            'glpi_import_id' => '',
            'glpi_rows' => '0',
            'glpi_file' => '',
            'glpi_hash' => '',
            'glpi_completed_at' => '',
            'warehouse_complete' => '0',
            'warehouse_import_id' => '',
            'warehouse_rows' => '0',
            'warehouse_file' => '',
            'warehouse_hash' => '',
            'warehouse_completed_at' => '',
            'ready' => '0',
            'updated_at' => $now,
        ]);
    }

    public static function markGlpiComplete(int $importId, int $rows, string $originalName, ?string $fileHash = null): void
    {
        $status = self::status();
        if (!$status['sedes']['complete']) {
            throw new RuntimeException('Primero debe completar la importación del Maestro de Sedes.');
        }
        if ($importId <= 0 || $rows <= 0) {
            throw new RuntimeException('La importación GLPI no produjo computadores válidos.');
        }
        $now = date('Y-m-d H:i:s');
        self::setMany([
            'glpi_complete' => '1',
            'glpi_import_id' => (string)$importId,
            'glpi_rows' => (string)$rows,
            'glpi_file' => $originalName,
            'glpi_hash' => (string)($fileHash ?? ''),
            'glpi_completed_at' => $now,
            // Una nueva fotografía GLPI exige una conciliación patrimonial nueva.
            'warehouse_complete' => '0',
            'warehouse_import_id' => '',
            'warehouse_rows' => '0',
            'warehouse_file' => '',
            'warehouse_hash' => '',
            'warehouse_completed_at' => '',
            'ready' => '0',
            'updated_at' => $now,
        ]);
    }

    public static function markWarehouseComplete(int $importId, int $rows, string $originalName, ?string $fileHash = null): void
    {
        $status = self::status();
        if (!$status['sedes']['complete']) {
            throw new RuntimeException('Primero debe completar la importación del Maestro de Sedes.');
        }
        if (!$status['glpi']['complete']) {
            throw new RuntimeException('Primero debe completar la importación de computadores desde GLPI.');
        }
        if ($importId <= 0 || $rows <= 0) {
            throw new RuntimeException('La importación de Almacén no produjo activos válidos.');
        }
        $now = date('Y-m-d H:i:s');
        self::setMany([
            'warehouse_complete' => '1',
            'warehouse_import_id' => (string)$importId,
            'warehouse_rows' => (string)$rows,
            'warehouse_file' => $originalName,
            'warehouse_hash' => (string)($fileHash ?? ''),
            'warehouse_completed_at' => $now,
            'ready' => '1',
            'completed_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public static function assertSedesComplete(): void
    {
        if (!self::status()['sedes']['complete']) {
            throw new RuntimeException('Debe importar primero el Maestro de Sedes RNEC.');
        }
    }

    public static function assertGlpiComplete(): void
    {
        self::assertSedesComplete();
        if (!self::status()['glpi']['complete']) {
            throw new RuntimeException('Debe importar después el inventario GLPI de computadores.');
        }
    }

    public static function operationalPages(): array
    {
        return [
            'sedes','sede_editar','campania_sede_contacto','equipos','equipo_validar','validation_draft','equipo_asignar',
            'campanias','campania_crear','campania_accion','directorio','directorio_accion',
            'calidad','homologaciones','traslados','traslado_accion','acta_sede',
            'notificaciones','reaperturas','reapertura_accion','correcciones','correccion_accion',
            'recordatorios','recordatorio_accion','reporte_ejecutivo','respaldos','versionamiento',
            'adicionales','seguimiento','seguimiento_accion','inconsistencias','novedades',
            'novedad_accion','historial_equipo','usuarios','usuarios_plantilla','usuarios_importar',
            'usuario_estado','usuario_clave','auditoria','exportar',
        ];
    }

    private static function settings(): array
    {
        $rows = Database::fetchAll(
            'SELECT setting_key,setting_value FROM app_settings WHERE setting_key LIKE ?',
            [self::PREFIX . '%']
        );
        $settings = [];
        foreach ($rows as $row) {
            $key = (string)$row['setting_key'];
            if (!str_starts_with($key, self::PREFIX)) continue;
            $settings[substr($key, strlen(self::PREFIX))] = (string)($row['setting_value'] ?? '');
        }
        return $settings;
    }

    private static function setMany(array $values): void
    {
        self::clearRequestCache();
        $pdo = Database::connection();
        $started = !$pdo->inTransaction();
        if ($started) $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO app_settings(setting_key,setting_value,updated_at) VALUES(?,?,NOW()) '
                . 'ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=NOW()'
            );
            foreach ($values as $key => $value) {
                $stmt->execute([self::PREFIX . $key, (string)$value]);
            }
            if ($started) $pdo->commit();
            self::clearRequestCache();
        } catch (Throwable $e) {
            self::clearRequestCache();
            if ($started && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private static function boolSetting(array $settings, string $key): bool
    {
        return in_array(strtolower(trim((string)($settings[$key] ?? ''))), ['1','true','yes','si'], true);
    }

    private static function intSetting(array $settings, string $key): int
    {
        return max(0, (int)($settings[$key] ?? 0));
    }

    private static function stringSetting(array $settings, string $key): string
    {
        return trim((string)($settings[$key] ?? ''));
    }

    private static function countSafe(string $sql): int
    {
        try {
            return (int)(Database::fetchOne($sql)['total'] ?? 0);
        } catch (Throwable) {
            return 0;
        }
    }

    private static function fetchSafe(string $sql, array $params = []): ?array
    {
        try {
            return Database::fetchOne($sql, $params);
        } catch (Throwable) {
            return null;
        }
    }
}
