<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: src/Database.php
 * Propósito: Administra la conexión con MySQL, consultas y transacciones de SIVI.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

final class Database
{
    private static ?PDO $pdo = null;
    /** @var array<string,mixed>|null */
    private static ?array $schemaStatusCache = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host = Env::get('DB_HOST', 'db');
        $port = Env::get('DB_PORT', '3306');
        $name = Env::get('DB_DATABASE', 'sivi_rnec');
        $user = Env::get('DB_USERNAME', 'sivi_user');
        $pass = Env::get('DB_PASSWORD', '');
        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => max(
                2,
                min(30, (int)(Env::get('DB_CONNECT_TIMEOUT_SECONDS', '8') ?? '8'))
            ),
        ];

        $tlsMode = strtolower(trim((string)Env::get('DB_TLS_MODE', 'verify_ca')));
        if (!in_array($tlsMode, ['disabled', 'preferred', 'required', 'verify_ca'], true)) {
            $tlsMode = 'verify_ca';
        }

        if ($tlsMode !== 'disabled') {
            $caFile = trim((string)Env::get('DB_TLS_CA', ''));
            if ($caFile !== '') {
                if (!is_file($caFile) || !is_readable($caFile)) {
                    throw new RuntimeException(
                        'El certificado CA de MySQL no está disponible.'
                    );
                }
                $options[PDO::MYSQL_ATTR_SSL_CA] = $caFile;
                if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                    $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
                }
            } elseif ($tlsMode === 'verify_ca') {
                throw new RuntimeException(
                    'DB_TLS_CA es obligatorio cuando DB_TLS_MODE=verify_ca.'
                );
            } else {
                if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                    $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
                }
            }
        }

        self::$pdo = new PDO($dsn, $user, $pass, $options);
        self::$pdo->exec("SET time_zone = '-05:00'");

        if (in_array($tlsMode, ['required', 'verify_ca'], true)) {
            $row = self::$pdo->query(
                "SHOW SESSION STATUS LIKE 'Ssl_version'"
            )->fetch(PDO::FETCH_ASSOC);
            $sslVersion = trim((string)($row['Value'] ?? $row['value'] ?? ''));
            if ($sslVersion === '') {
                self::$pdo = null;
                throw new RuntimeException(
                    'La conexión con MySQL no está cifrada mediante TLS.'
                );
            }
        }

        return self::$pdo;
    }

    public static function isInstalled(): bool
    {
        try {
            $tableExists = (int)self::connection()->query(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users'"
            )->fetchColumn();

            if ($tableExists === 0) {
                return false;
            }

            $userCount = (int)self::connection()->query('SELECT COUNT(*) FROM users')->fetchColumn();
            return $userCount > 0;
        } catch (Throwable) {
            return false;
        }
    }

    public static function schemaStatus(): array
    {
        if (self::$schemaStatusCache !== null) {
            return self::$schemaStatusCache;
        }

        $requiredTables = [
            'campaigns', 'campaign_sedes', 'directory_imports', 'directory_changes',
            'data_homologations', 'equipment_transfers', 'validation_corrections',
            'internal_notifications', 'reopening_requests', 'evidence_files',
            'backup_history', 'departments', 'municipalities', 'sede_types', 'import_errors',
            'app_release_history', 'import_validations', 'data_quality_snapshots',
            'campaign_equipment', 'validation_drafts', 'warehouse_imports', 'warehouse_assets',
            'mobile_scan_sessions', 'report_exports', 'notification_templates', 'notification_queue',
            'glpi_sync_runs', 'glpi_sync_items', 'glpi_asset_links', 'glpi_location_mappings',
            'site_quality_runs', 'site_quality_findings', 'ops_backup_runs',
            'ops_deployment_history', 'ops_security_events',
            'sivi_runtime_settings', 'sivi_user_onboarding',
        ];
        $requiredColumns = [
            'sedes.email_institucional', 'sedes.directorio_sincronizado_en',
            'sedes.horario_atencion', 'sedes.directorio_clave',
            'campaign_sedes.notification_status', 'campaign_sedes.closed_at',
            'campaign_sedes.acceptance_text', 'campaign_sedes.sede_id',
            'campaign_sedes.responsible_name', 'campaign_sedes.responsible_email',
            'campaign_sedes.contact_confirmed_at',
            'campaign_sedes.site_confirmation_status',
            'campaign_sedes.site_confirmed_address',
            'campaign_sedes.site_confirmed_at',
            'internal_notifications.user_id', 'internal_notifications.title',
            'internal_notifications.message', 'internal_notifications.read_at',
            'internal_notifications.created_at', 'app_release_history.version',
            'app_release_history.release_key', 'app_release_history.is_current',
            'equipment.ownership_type', 'equipment.inventory_status',
            'equipment.association_confidence', 'equipment.association_evidence',
            'equipment.association_review_required', 'imports.source_kind',
            'imports.association_summary_json', 'imports.review_required_equipment',
            'equipment.source_key', 'equipment.asset_category',
            'equipment.category_source', 'equipment.source_origin',
            'equipment.serial_source_original', 'equipment.serial_review_required',
            'equipment.serial_review_reason', 'equipment.serial_verified_at',
            'equipment.serial_verified_by',
            'equipment_validations.physical_condition',
            'equipment_validations.ownership_type',
            'equipment_validations.disposal_date',
            'equipment_validations.disposal_document',
            'equipment_validations.serial_status',
            'import_errors.source_row',
            'import_validations.traffic_light',
            'import_validations.critical_count',
            'import_validations.error_report_path',
            'import_validations.applied_at',
            'campaigns.scope_json', 'campaigns.asset_categories_json',
            'campaigns.requires_evidence', 'campaigns.allow_overlap',
            'campaigns.published_at',
            'campaign_equipment.equipment_id', 'campaign_equipment.sede_id',
            'mobile_scan_sessions.token_hash', 'mobile_scan_sessions.pairing_code',
            'mobile_scan_sessions.scan_sequence', 'mobile_scan_sessions.ack_sequence',
            'mobile_scan_sessions.last_request_id',
            'mobile_scan_sessions.last_acknowledged_at',
            'mobile_scan_sessions.mobile_last_seen_at',
            'mobile_scan_sessions.renewed_at',
            'mobile_scan_sessions.expires_at',
            'validation_drafts.payload_json',
            'warehouse_assets.associated_sede_id',
            'warehouse_assets.association_rule',
            'warehouse_assets.association_confidence',
            'warehouse_assets.association_evidence',
            'warehouse_assets.association_review_required',
            'warehouse_imports.warehouse_exact_assigned',
            'warehouse_imports.warehouse_department_assigned',
            'warehouse_imports.warehouse_unassigned',
            'warehouse_imports.warehouse_glpi_enhanced',
            'notifications.event_key', 'notifications.queue_id',
            'notifications.provider_request_id',
            'notification_templates.template_key',
            'notification_templates.subject_template',
            'notification_queue.notification_id',
            'notification_queue.status',
            'notification_queue.next_attempt_at',
        ];

        /*
         * Antes se consultaba information_schema una vez por cada tabla y
         * columna. Se conserva exactamente la misma comprobación con consultas
         * agrupadas.
         */
        $tablePlaceholders = implode(
            ',',
            array_fill(0, count($requiredTables), '?')
        );
        $tableRows = self::fetchAll(
            "SELECT table_name FROM information_schema.tables "
            . "WHERE table_schema=DATABASE() "
            . "AND table_name IN ({$tablePlaceholders})",
            $requiredTables
        );
        $existingTables = array_fill_keys(
            array_map(
                static fn(array $row): string =>
                    (string)$row['table_name'],
                $tableRows
            ),
            true
        );
        $missingTables = array_values(array_filter(
            $requiredTables,
            static fn(string $table): bool =>
                !isset($existingTables[$table])
        ));

        $columnTables = [];
        foreach ($requiredColumns as $qualified) {
            [$table] = explode('.', $qualified, 2);
            $columnTables[$table] = true;
        }
        $columnTableNames = array_keys($columnTables);
        $columnPlaceholders = implode(
            ',',
            array_fill(0, count($columnTableNames), '?')
        );
        $columnRows = self::fetchAll(
            "SELECT table_name,column_name "
            . "FROM information_schema.columns "
            . "WHERE table_schema=DATABASE() "
            . "AND table_name IN ({$columnPlaceholders})",
            $columnTableNames
        );
        $existingColumns = [];
        foreach ($columnRows as $row) {
            $existingColumns[
                (string)$row['table_name']
                . '.'
                . (string)$row['column_name']
            ] = true;
        }
        $missingColumns = array_values(array_filter(
            $requiredColumns,
            static fn(string $qualified): bool =>
                !isset($existingColumns[$qualified])
        ));

        $schemaPath = dirname(__DIR__) . '/database/schema.sql';
        $expected = is_file($schemaPath)
            ? hash_file('sha256', $schemaPath)
            : false;
        $currentChecksum = false;
        try {
            $row = self::fetchOne(
                'SELECT checksum FROM schema_migrations '
                . 'WHERE migration_key=?',
                ['schema.sql']
            );
            $currentChecksum = $expected !== false
                && $row
                && hash_equals(
                    (string)$row['checksum'],
                    (string)$expected
                );
        } catch (Throwable) {
            $currentChecksum = false;
        }

        self::$schemaStatusCache = [
            'ok' => $missingTables === []
                && $missingColumns === []
                && $currentChecksum,
            'missing_tables' => $missingTables,
            'missing_columns' => $missingColumns,
            'current_checksum' => $currentChecksum,
        ];
        return self::$schemaStatusCache;
    }

    public static function createSuperAdmin(string $name, string $email, string $temporaryPassword): void
    {
        $pdo = self::connection();
        $name = trim($name);
        $email = strtolower(trim($email));

        if ($name === '') {
            throw new RuntimeException('El nombre del Superadministrador es obligatorio.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('El correo electrónico del Superadministrador no es válido.');
        }
        if (strlen($temporaryPassword) < 10) {
            throw new RuntimeException('La contraseña temporal debe tener mínimo 10 caracteres.');
        }
        if (!preg_match('/[A-Z]/', $temporaryPassword)
            || !preg_match('/[a-z]/', $temporaryPassword)
            || !preg_match('/[0-9]/', $temporaryPassword)
            || !preg_match('/[^A-Za-z0-9]/', $temporaryPassword)) {
            throw new RuntimeException('La contraseña temporal debe incluir mayúscula, minúscula, número y carácter especial.');
        }

        $existing = self::fetchOne("SELECT id FROM users WHERE role='superadmin' ORDER BY id LIMIT 1");
        if ($existing) {
            throw new RuntimeException('Ya existe un usuario Superadministrador.');
        }

        $hash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO users (name,email,password_hash,default_password_hash,role,must_change_password,active) VALUES (?,?,?,?,?,1,1)');
        $stmt->execute([$name, $email, $hash, $hash, 'superadmin']);
    }

    /**
     * Instalación por consola. Solo se conserva para despliegues automatizados
     * que configuren expresamente las variables SUPERADMIN_*.
     */
    public static function seedAdmin(): void
    {
        $email = trim((string)Env::get('SUPERADMIN_EMAIL', ''));
        $name = trim((string)Env::get('SUPERADMIN_NAME', ''));
        $password = (string)Env::get('SUPERADMIN_PASSWORD', '');
        if ($email === '' || $name === '' || $password === '') {
            throw new RuntimeException('Para instalar por consola configure SUPERADMIN_NAME, SUPERADMIN_EMAIL y SUPERADMIN_PASSWORD. La instalación web no requiere estas variables.');
        }
        self::createSuperAdmin($name, $email, $password);
    }

    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function execute(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
}
