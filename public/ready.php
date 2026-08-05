<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: public/ready.php
 * Propósito: Comprueba DB TLS, tablas requeridas y almacenamiento sin exponer secretos.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

$root = dirname(__DIR__);
require_once $root . '/src/Env.php';
Env::load($root . '/.env');
require_once $root . '/src/Database.php';

$checks = [
    'database' => false,
    'database_tls' => false,
    'storage_exists' => false,
    'storage_writable' => false,
    'free_space' => false,
    'required_tables' => false,
];

$version = (string)Env::get('APP_VERSION', 'unknown');
$buildId = (string)Env::get('APP_BUILD_ID', 'unknown');
$storagePath = (string)Env::get(
    'READINESS_STORAGE_PATH',
    $root . '/storage'
);
$minimumFreeMb = max(
    1,
    (int)(Env::get('READINESS_MIN_FREE_MB', '256') ?? '256')
);
$requiredTablesRaw = trim((string)Env::get(
    'READINESS_REQUIRED_TABLES',
    'users,campaigns,equipment'
));
$requiredTables = array_values(array_filter(array_map(
    'trim',
    explode(',', $requiredTablesRaw)
)));

try {
    $checks['storage_exists'] = is_dir($storagePath);
    $checks['storage_writable'] = $checks['storage_exists']
        && is_writable($storagePath);
    $freeBytes = $checks['storage_exists']
        ? @disk_free_space($storagePath)
        : false;
    $checks['free_space'] = is_float($freeBytes) || is_int($freeBytes)
        ? $freeBytes >= ($minimumFreeMb * 1024 * 1024)
        : false;

    $pdo = Database::connection();
    $pdo->query('SELECT 1')->fetchColumn();
    $checks['database'] = true;

    $sslStatement = $pdo->query("SHOW SESSION STATUS LIKE 'Ssl_version'");
    $sslRow = $sslStatement !== false
        ? $sslStatement->fetch(PDO::FETCH_ASSOC)
        : false;
    $sslVersion = is_array($sslRow)
        ? trim((string)($sslRow['Value'] ?? $sslRow['value'] ?? ''))
        : '';
    $tlsMode = strtolower(trim((string)Env::get('DB_TLS_MODE', 'verify_ca')));
    $checks['database_tls'] = in_array(
        $tlsMode,
        ['required', 'verify_ca'],
        true
    ) ? $sslVersion !== '' : true;

    $database = (string)Env::get('DB_DATABASE', '');
    if ($requiredTables === []) {
        $checks['required_tables'] = true;
    } elseif ($database !== '') {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables '
            . 'WHERE table_schema = :schema AND table_name = :table'
        );
        $allPresent = true;
        foreach ($requiredTables as $table) {
            if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
                $allPresent = false;
                break;
            }
            $statement->execute([
                'schema' => $database,
                'table' => $table,
            ]);
            if ((int)$statement->fetchColumn() !== 1) {
                $allPresent = false;
                break;
            }
        }
        $checks['required_tables'] = $allPresent;
    }
} catch (Throwable) {
    // No se exponen mensajes, rutas, consultas, certificados ni credenciales.
}

$ready = !in_array(false, $checks, true);
http_response_code($ready ? 200 : 503);

/*
 * Los detalles de readiness se conservan únicamente en memoria. Para diagnóstico
 * administrativo se utiliza preflight/SystemHealth; el endpoint público evita
 * enumerar tablas, versión, build o componentes internos.
 */
echo json_encode([
    'status' => $ready ? 'ready' : 'not_ready',
    'application' => 'SIVI',
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
