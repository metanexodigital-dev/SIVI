<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/preflight.php
 * Propósito: Verifica entorno, almacenamiento, base, índices y respaldo.
 */
declare(strict_types=1);

$startedAt = microtime(true);
$root = dirname(__DIR__);
$jsonMode = in_array('--json', $argv ?? [], true);
$checks = [];

$add = static function (
    string $name,
    bool $ok,
    string $detail,
    bool $critical = true
) use (&$checks): void {
    $checks[] = compact('name', 'ok', 'detail', 'critical');
};

$bytesFromIni = static function (string $value): int {
    $value = trim($value);
    if ($value === '' || $value === '-1') return PHP_INT_MAX;
    $unit = strtolower(substr($value, -1));
    $number = (float)$value;
    return match ($unit) {
        'g' => (int)($number * 1073741824),
        'm' => (int)($number * 1048576),
        'k' => (int)($number * 1024),
        default => (int)$number,
    };
};

$add('PHP >= 8.2', version_compare(PHP_VERSION, '8.2.0', '>='), PHP_VERSION);

$requiredExtensions = [
    'pdo', 'pdo_mysql', 'mbstring', 'zip', 'xmlreader',
    'simplexml', 'fileinfo', 'openssl', 'json', 'session',
];
foreach ($requiredExtensions as $extension) {
    $loaded = extension_loaded($extension);
    $add(
        'Extensión ' . $extension,
        $loaded,
        $loaded ? 'Disponible' : 'No disponible'
    );
}
$add(
    'Extensión gd',
    extension_loaded('gd'),
    extension_loaded('gd')
        ? 'Compresión WebP disponible'
        : 'Las imágenes se guardarán sin conversión WebP',
    false
);

$memoryLimit = (string)ini_get('memory_limit');
$memoryBytes = $bytesFromIni($memoryLimit);
$add(
    'Memoria PHP recomendada',
    $memoryBytes === PHP_INT_MAX || $memoryBytes >= 268435456,
    $memoryLimit,
    false
);

$requiredFiles = [
    'Dockerfile',
    'docker-compose.yml',
    'config/environment.example',
    'VERSION',
    'RELEASE.json',
    'database/schema.sql',
    'public/index.php',
    'public/health.php',
    'public/ready.php',
    'scripts/install.php',
    'scripts/preflight.php',
    'scripts/build/run.php',
    'scripts/build/checks.json',
    'scripts/lib/CheckRunner.php',
    'scripts/lib/ProcessRunner.php',
    'scripts/ensure_performance_indexes.php',
    'src/bootstrap.php',
    'src/AppVersion.php',
    'src/Database.php',
    'scripts/check_soc_hardening_1_0_0_0.php',
    'src/UploadSecurity.php',
    'src/MalwareScanner.php',
];
foreach ($requiredFiles as $file) {
    $exists = is_file($root . '/' . $file);
    $add(
        'Archivo ' . $file,
        $exists,
        $exists ? 'Presente' : 'Faltante'
    );
}

$storage = $root . '/storage';
if (!is_dir($storage)) {
    @mkdir($storage, 0775, true);
}
foreach (['uploads', 'logs', 'import-previews', 'backups'] as $directory) {
    $path = $storage . '/' . $directory;
    if (!is_dir($path)) {
        @mkdir($path, 0775, true);
    }
    $writable = is_dir($path) && is_writable($path);
    $add(
        'Directorio storage/' . $directory,
        $writable,
        $writable ? 'Escribible' : 'No escribible'
    );
}

$minimumFreeMb = max(
    128,
    (int)(getenv('PREFLIGHT_MIN_FREE_MB') ?: 512)
);
$freeBytes = is_dir($storage) ? @disk_free_space($storage) : false;
$freeMb = is_numeric($freeBytes)
    ? round(((float)$freeBytes) / 1048576, 1)
    : 0.0;
$add(
    'Espacio libre',
    $freeMb >= $minimumFreeMb,
    $freeMb . ' MB disponibles; mínimo ' . $minimumFreeMb . ' MB'
);

require_once $root . '/src/Env.php';
Env::load($root . '/.env');
require_once $root . '/src/AppVersion.php';
require_once $root . '/src/SetupPolicy.php';

$requiredEnv = [
    'APP_VERSION',
    'APP_ENV',
    'APP_BUILD_ID',
    'DB_HOST',
    'DB_PORT',
    'DB_DATABASE',
    'DB_USERNAME',
    'DB_PASSWORD',
];
foreach ($requiredEnv as $key) {
    $value = trim((string)Env::get($key, ''));
    $placeholder = str_contains(strtoupper($value), 'REEMPLAZAR')
        || str_contains(strtoupper($value), 'CAMBIAR');
    $ok = $value !== '' && !$placeholder;
    $add(
        'Variable ' . $key,
        $ok,
        $ok ? 'Configurada' : 'Vacía o conserva valor de ejemplo'
    );
}

foreach (['APP_SETUP_KEY','APP_ENCRYPTION_KEY','DB_PASSWORD'] as $secretKey) {
    $source = Env::source($secretKey);
    $add(
        'Origen seguro ' . $secretKey,
        $source === 'file',
        $source === 'file' ? 'Docker Secret/archivo montado' : $source,
        false
    );
}

$dbTlsMode = strtolower(trim((string)Env::get('DB_TLS_MODE', 'verify_ca')));
$dbTlsCa = trim((string)Env::get('DB_TLS_CA', ''));
$add('TLS MySQL requerido', $dbTlsMode === 'verify_ca', $dbTlsMode);
$add(
    'CA MySQL legible',
    $dbTlsCa !== '' && is_file($dbTlsCa) && is_readable($dbTlsCa),
    $dbTlsCa !== '' ? $dbTlsCa : 'No configurada'
);

$malwareRequired = Env::bool('MALWARE_SCAN_REQUIRED', true);
$malwareHost = trim((string)Env::get('MALWARE_SCAN_HOST', 'clamav'));
$malwarePort = (int)Env::get('MALWARE_SCAN_PORT', '3310');
$malwareReachable = false;
$malwareError = '';
if ($malwareHost !== '' && $malwarePort > 0) {
    $errno = 0; $errstr = '';
    $socket = @fsockopen($malwareHost, $malwarePort, $errno, $errstr, 2.0);
    if (is_resource($socket)) {
        $malwareReachable = true;
        fclose($socket);
    } else {
        $malwareError = trim($errstr . ' (' . $errno . ')');
    }
}
$add(
    'Antimalware disponible',
    !$malwareRequired || $malwareReachable,
    $malwareReachable
        ? $malwareHost . ':' . $malwarePort . ' disponible'
        : ($malwareError !== '' ? $malwareError : 'No disponible')
);

$versionPolicy = AppVersion::policy();
$add(
    'Formato de versión',
    AppVersion::isValid(AppVersion::package()),
    AppVersion::package()
);
$add(
    'VERSION coincide con APP_VERSION',
    AppVersion::package() === AppVersion::configured(),
    'Paquete ' . AppVersion::package()
        . ' / Environment ' . AppVersion::configured()
);
$add(
    'Política de ambiente',
    $versionPolicy['errors'] === [],
    $versionPolicy['errors'] === []
        ? AppVersion::environmentLabel()
        : implode(' ', $versionPolicy['errors'])
);
$add(
    'Commit de despliegue',
    AppVersion::gitCommit() !== null,
    AppVersion::gitCommit() ?? 'Configure APP_GIT_COMMIT',
    false
);

$topology = strtolower(trim((string)Env::get('DEPLOYMENT_TOPOLOGY', 'split')));
$configuredDbHost = trim((string)Env::get('DB_HOST', ''));
$expectedDbServer = trim((string)Env::get('DB_SERVER_HOSTNAME', ''));
$expectedAppServer = trim((string)Env::get('APP_SERVER_HOSTNAME', ''));
$appInternalDns = trim((string)Env::get('APP_INTERNAL_DNS', ''));
$appUrl = trim((string)Env::get('APP_URL', ''));

$runtimeValueOk = static function (string $value): bool {
    if ($value === '') {
        return false;
    }
    $upper = strtoupper($value);
    return !str_contains($upper, 'REEMPLAZAR')
        && !str_contains($upper, 'CAMBIAR')
        && !str_contains($upper, 'EXAMPLE');
};

$add(
    'Topología separada',
    $topology === 'split',
    $topology
);
$add(
    'Hostname del servidor APP configurado',
    $runtimeValueOk($expectedAppServer),
    $expectedAppServer !== '' ? $expectedAppServer : 'No configurado',
    false
);
$add(
    'Hostname del servidor DB configurado',
    $runtimeValueOk($expectedDbServer),
    $expectedDbServer !== '' ? $expectedDbServer : 'No configurado',
    false
);
$add(
    'DB_HOST externo',
    $runtimeValueOk($configuredDbHost)
        && strcasecmp($configuredDbHost, 'db') !== 0,
    $configuredDbHost !== '' ? $configuredDbHost : 'No configurado'
);

$dbHostIsIp = filter_var($configuredDbHost, FILTER_VALIDATE_IP) !== false;
$resolvedDb = (!$dbHostIsIp && $configuredDbHost !== '')
    ? gethostbyname($configuredDbHost)
    : $configuredDbHost;
$dbResolutionOk = $runtimeValueOk($configuredDbHost)
    && ($dbHostIsIp || $resolvedDb !== $configuredDbHost);

$add(
    'Resolución hostname/IP de DB',
    $dbResolutionOk,
    $configuredDbHost === ''
        ? 'DB_HOST vacío'
        : ($dbHostIsIp
            ? 'IP configurada: ' . $configuredDbHost
            : $configuredDbHost . ' -> ' . $resolvedDb)
);

$appUrlHost = (string)(parse_url($appUrl, PHP_URL_HOST) ?? '');
$dnsMatchesUrl = $runtimeValueOk($appInternalDns)
    && $runtimeValueOk($appUrlHost)
    && strcasecmp($appInternalDns, $appUrlHost) === 0;

$add(
    'Dominio de aplicación configurado',
    $dnsMatchesUrl,
    ($appInternalDns !== '' ? $appInternalDns : 'No configurado')
        . ' / APP_URL=' . ($appUrl !== '' ? $appUrl : 'No configurada'),
    false
);

$dbConnected = false;
$schemaOk = false;
$installed = false;
$dbLatencyMs = null;
$performanceIndexesPresent = 0;
$performanceIndexesRequired = 4;

try {
    require_once $root . '/src/Database.php';
    $dbStartedAt = microtime(true);
    Database::connection()->query('SELECT 1');
    $dbLatencyMs = round((microtime(true) - $dbStartedAt) * 1000, 1);
    $dbConnected = true;

    $sslRow = Database::fetchOne(
        "SHOW SESSION STATUS LIKE 'Ssl_version'"
    );
    $sslVersion = trim((string)($sslRow['Value'] ?? $sslRow['value'] ?? ''));
    $add(
        'Sesión MySQL cifrada',
        $sslVersion !== '',
        $sslVersion !== '' ? $sslVersion : 'Sin TLS'
    );

    $add(
        'Conexión MySQL',
        $dbLatencyMs < 3000,
        'Disponible en ' . $dbLatencyMs . ' ms'
    );
    $add(
        'Latencia MySQL recomendada',
        $dbLatencyMs < 750,
        $dbLatencyMs . ' ms',
        false
    );

    $status = Database::schemaStatus();
    $schemaOk = (bool)$status['ok'];
    $detail = $schemaOk
        ? 'Esquema vigente'
        : 'Faltan '
            . (
                count($status['missing_tables'])
                + count($status['missing_columns'])
            )
            . ' objetos o la huella no coincide';
    $add('Esquema de base de datos', $schemaOk, $detail);

    $installed = Database::isInstalled();
    $add(
        'Usuario inicial',
        true,
        $installed
            ? 'Ya existe al menos un usuario'
            : 'Pendiente: complete /index.php?page=setup',
        false
    );

    $databaseRow = Database::fetchOne('SELECT DATABASE() database_name');
    $databaseName = (string)($databaseRow['database_name'] ?? '');
    $requiredIndexes = [
        ['equipment', 'idx_eq_serial_active'],
        ['equipment', 'idx_eq_placa_active'],
        ['additional_equipment', 'idx_ae_serial_review'],
        ['additional_equipment', 'idx_ae_placa_review'],
    ];
    foreach ($requiredIndexes as [$table, $index]) {
        $exists = Database::fetchOne(
            'SELECT 1 found FROM information_schema.statistics '
            . 'WHERE table_schema=? AND table_name=? AND index_name=? LIMIT 1',
            [$databaseName, $table, $index]
        );
        if ($exists) $performanceIndexesPresent++;
    }
    $add(
        'Índices técnicos de rendimiento',
        $performanceIndexesPresent === $performanceIndexesRequired,
        $performanceIndexesPresent . '/' . $performanceIndexesRequired
            . ' presentes; use scripts/ensure_performance_indexes.php',
        false
    );
} catch (Throwable $exception) {
    error_log(
        'SIVI preflight database failure: '
        . get_class($exception)
        . ' code=' . $exception->getCode()
    );
    $add(
        'Conexión MySQL',
        false,
        'No fue posible establecer conexión. Consulte los logs.'
    );
}

$setupKey = trim((string)Env::get('APP_SETUP_KEY', ''));
$setupKeyOk = !SetupPolicy::requiresKey() || $setupKey !== '';
$add(
    'Variable APP_SETUP_KEY',
    $setupKeyOk,
    $setupKeyOk
        ? (SetupPolicy::requiresKey()
            ? 'Configurada'
            : 'Disponible para producción')
        : 'Obligatoria y vacía'
);

$backupPath = (string)Env::get('BACKUP_PATH', '/var/backups/sivi');
$latestPointer = $backupPath . '/latest.txt';
$maxBackupAgeHours = max(
    1,
    (int)(getenv('PREFLIGHT_BACKUP_MAX_AGE_HOURS') ?: 48)
);
$latestBackup = is_file($latestPointer)
    ? trim((string)file_get_contents($latestPointer))
    : '';
$backupAgeHours = null;
if ($latestBackup !== '' && is_file($latestBackup)) {
    $backupAgeHours = round(
        (time() - (int)filemtime($latestBackup)) / 3600,
        1
    );
}
$add(
    'Respaldo reciente',
    $backupAgeHours !== null && $backupAgeHours <= $maxBackupAgeHours,
    $backupAgeHours === null
        ? 'No disponible en este contenedor'
        : $backupAgeHours . ' horas de antigüedad',
    false
);

$logBytes = 0;
$logDirectory = $storage . '/logs';
if (is_dir($logDirectory)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $logDirectory,
            FilesystemIterator::SKIP_DOTS
        )
    );
    foreach ($iterator as $file) {
        if ($file->isFile()) $logBytes += $file->getSize();
    }
}
$logMb = round($logBytes / 1048576, 1);
$add(
    'Tamaño de logs',
    $logMb <= 500,
    $logMb . ' MB',
    false
);

$criticalFailures = array_values(array_filter(
    $checks,
    static fn(array $check): bool =>
        $check['critical'] && !$check['ok']
));
$warnings = array_values(array_filter(
    $checks,
    static fn(array $check): bool =>
        !$check['critical'] && !$check['ok']
));

$result = [
    'ok' => $criticalFailures === [],
    'version' => AppVersion::package(),
    'database_connected' => $dbConnected,
    'database_latency_ms' => $dbLatencyMs,
    'schema_current' => $schemaOk,
    'installed' => $installed,
    'performance_indexes' => [
        'present' => $performanceIndexesPresent,
        'required' => $performanceIndexesRequired,
    ],
    'critical_failures' => count($criticalFailures),
    'warnings' => count($warnings),
    'duration_ms' => (int)round(
        (microtime(true) - $startedAt) * 1000
    ),
    'memory_peak_mb' => round(
        memory_get_peak_usage(true) / 1048576,
        2
    ),
    'checks' => $checks,
];

if ($jsonMode) {
    echo json_encode(
        $result,
        JSON_PRETTY_PRINT
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
} else {
    echo 'SIVI ' . AppVersion::package()
        . " - Verificación del entorno\n";
    echo str_repeat('=', 64) . "\n";
    foreach ($checks as $check) {
        $symbol = $check['ok']
            ? '[OK]'
            : ($check['critical'] ? '[ERROR]' : '[AVISO]');
        echo sprintf(
            "%-7s %-38s %s\n",
            $symbol,
            $check['name'],
            $check['detail']
        );
    }
    echo str_repeat('-', 64) . "\n";
    echo 'Duración: ' . $result['duration_ms']
        . " ms · Memoria pico: "
        . $result['memory_peak_mb'] . " MB\n";
    echo $result['ok']
        ? "Resultado: entorno listo para producción.\n"
        : "Resultado: corrija los errores críticos.\n";
}

exit($result['ok'] ? 0 : 2);
