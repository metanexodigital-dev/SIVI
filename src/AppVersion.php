<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: src/AppVersion.php
 * Propósito: Controla la versión real del código y registra los despliegues.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

/**
 * Fuente única de verdad y controles de versionamiento de SIVI.
 *
 * La versión del paquete se obtiene del archivo VERSION. APP_VERSION se usa
 * únicamente como valor de verificación del despliegue; no puede cambiar la
 * versión real del código instalado.
 */
final class AppVersion
{
    private const VERSION_PATTERN = '/^\d+\.\d+\.\d+\.\d+$/';
    private static ?string $packageVersion = null;
    private static bool $schemaChecksumLoaded = false;
    private static ?string $schemaChecksumCache = null;

    private static function truncate(string $value, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }

    public static function package(): string
    {
        if (self::$packageVersion !== null) {
            return self::$packageVersion;
        }
        $path = dirname(__DIR__) . '/VERSION';
        $version = is_file($path) ? trim((string)file_get_contents($path)) : '';
        self::$packageVersion = $version !== '' ? $version : '0.0.0.0';
        return self::$packageVersion;
    }

    public static function configured(): string
    {
        return trim((string)Env::get('APP_VERSION', self::package()));
    }

    public static function configuredEnvironment(): string
    {
        return strtolower(trim((string)Env::get('APP_ENV', 'production')));
    }

    public static function environment(): string
    {
        $environment = self::configuredEnvironment();
        return in_array($environment, ['development', 'testing', 'staging', 'production'], true)
            ? $environment
            : 'production';
    }

    public static function environmentLabel(): string
    {
        return match (self::environment()) {
            'development' => 'Desarrollo',
            'staging' => 'Preproducción',
            'production' => 'Producción',
            default => 'Pruebas',
        };
    }

    public static function buildId(): string
    {
        $build = trim((string)Env::get('APP_BUILD_ID', ''));
        return $build !== '' ? self::truncate($build, 120) : 'dokploy';
    }

    public static function gitCommit(): ?string
    {
        foreach (['APP_GIT_COMMIT', 'GIT_COMMIT', 'SOURCE_COMMIT'] as $key) {
            $value = trim((string)Env::get($key, ''));
            if ($value !== '') {
                return self::truncate($value, 64);
            }
        }
        return null;
    }

    public static function schemaChecksum(): ?string
    {
        if (self::$schemaChecksumLoaded) {
            return self::$schemaChecksumCache;
        }

        $path = dirname(__DIR__) . '/database/schema.sql';
        self::$schemaChecksumCache = is_file($path)
            ? (hash_file('sha256', $path) ?: null)
            : null;
        self::$schemaChecksumLoaded = true;
        return self::$schemaChecksumCache;
    }

    public static function isValid(string $version): bool
    {
        return preg_match(self::VERSION_PATTERN, trim($version)) === 1;
    }

    /**
     * @return array{level:string,errors:array<int,string>,warnings:array<int,string>}
     */
    public static function policy(): array
    {
        $errors = [];
        $warnings = [];
        $package = self::package();
        $configured = self::configured();
        $environment = self::environment();
        $configuredEnvironment = self::configuredEnvironment();

        if (!self::isValid($package)) {
            $errors[] = 'El archivo VERSION no contiene cuatro bloques numéricos.';
        }
        if (!self::isValid($configured)) {
            $errors[] = 'APP_VERSION no tiene el formato N.N.N.N.';
        } elseif ($configured !== $package) {
            $errors[] = 'APP_VERSION no coincide con el archivo VERSION del paquete.';
        }

        if (!in_array($configuredEnvironment, ['development', 'testing', 'staging', 'production'], true)) {
            $errors[] = 'APP_ENV no contiene un ambiente permitido.';
        }

        $major = self::isValid($package) ? (int)explode('.', $package)[0] : 0;
        if ($environment === 'production' && $major < 1) {
            $errors[] = 'Una versión 0.x no puede declararse como producción.';
        }
        if ($environment !== 'production' && $major >= 1) {
            $warnings[] = 'La versión es 1.x o superior, pero APP_ENV no está en producción.';
        }
        if (self::gitCommit() === null) {
            $warnings[] = 'No se configuró APP_GIT_COMMIT; el despliegue no queda ligado a un commit.';
        }

        return [
            'level' => $errors !== [] ? 'error' : ($warnings !== [] ? 'warning' : 'ok'),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    public static function releaseKey(): string
    {
        return hash('sha256', implode('|', [
            self::package(),
            self::environment(),
            self::buildId(),
            self::gitCommit() ?? 'sin-commit',
        ]));
    }

    /**
     * Registra el paquete desplegado sin permitir cambiar la versión desde la UI.
     */
    public static function registerDeployment(?int $userId = null, ?string $notes = null): array
    {
        $policy = self::policy();
        if ($policy['errors'] !== []) {
            throw new RuntimeException(implode(' ', $policy['errors']));
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            Database::execute(
                'UPDATE app_release_history SET is_current=0 WHERE environment=?',
                [self::environment()]
            );
            Database::execute(
                'INSERT INTO app_release_history '
                . '(release_key,version,environment,build_id,git_commit,schema_checksum,is_current,release_notes,registered_by,installed_at,last_seen_at) '
                . 'VALUES(?,?,?,?,?,?,1,?,?,NOW(),NOW()) '
                . 'ON DUPLICATE KEY UPDATE schema_checksum=VALUES(schema_checksum),is_current=1,'
                . 'release_notes=COALESCE(VALUES(release_notes),release_notes),registered_by=COALESCE(VALUES(registered_by),registered_by),last_seen_at=NOW()',
                [
                    self::releaseKey(), self::package(), self::environment(), self::buildId(),
                    self::gitCommit(), self::schemaChecksum(),
                    ($notes !== null && trim($notes) !== '') ? trim($notes) : null,
                    $userId,
                ]
            );
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return self::databaseCurrent() ?? [];
    }

    public static function databaseCurrent(): ?array
    {
        try {
            return Database::fetchOne(
                'SELECT r.*,u.name registered_by_name FROM app_release_history r '
                . 'LEFT JOIN users u ON u.id=r.registered_by '
                . 'WHERE r.environment=? AND r.is_current=1 ORDER BY r.id DESC LIMIT 1',
                [self::environment()]
            );
        } catch (Throwable) {
            return null;
        }
    }

    public static function history(int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        try {
            return Database::fetchAll(
                'SELECT r.*,u.name registered_by_name FROM app_release_history r '
                . 'LEFT JOIN users u ON u.id=r.registered_by ORDER BY r.id DESC LIMIT ' . $limit
            );
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string,mixed>
     */
    public static function status(): array
    {
        $policy = self::policy();
        $database = self::databaseCurrent();
        $databaseMatches = $database !== null
            && (string)$database['version'] === self::package()
            && (string)$database['environment'] === self::environment()
            && (string)$database['release_key'] === self::releaseKey();

        $schemaStatus = null;
        try {
            $schemaStatus = Database::schemaStatus();
        } catch (Throwable) {
            $schemaStatus = null;
        }

        $errors = $policy['errors'];
        $warnings = $policy['warnings'];
        if ($database === null) {
            $warnings[] = 'La versión actual aún no está registrada en la base de datos.';
        } elseif (!$databaseMatches) {
            $errors[] = 'La versión registrada en la base de datos no coincide con el paquete desplegado.';
        }
        if ($schemaStatus !== null && !$schemaStatus['ok']) {
            $errors[] = 'El esquema de base de datos no está sincronizado con el paquete.';
        }

        return [
            'ok' => $errors === [],
            'level' => $errors !== [] ? 'error' : ($warnings !== [] ? 'warning' : 'ok'),
            'package_version' => self::package(),
            'configured_version' => self::configured(),
            'environment' => self::environment(),
            'environment_label' => self::environmentLabel(),
            'build_id' => self::buildId(),
            'git_commit' => self::gitCommit(),
            'schema_checksum' => self::schemaChecksum(),
            'database_release' => $database,
            'database_matches' => $databaseMatches,
            'schema' => $schemaStatus,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    public static function nextPatch(): string
    {
        $parts = self::isValid(self::package()) ? array_map('intval', explode('.', self::package())) : [0,0,0,0];
        $parts[3]++;
        return implode('.', $parts);
    }

    public static function nextFeature(): string
    {
        $parts = self::isValid(self::package()) ? array_map('intval', explode('.', self::package())) : [0,0,0,0];
        $parts[2]++;
        $parts[3] = 0;
        return implode('.', $parts);
    }
}
