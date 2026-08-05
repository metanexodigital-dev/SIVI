<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: src/bootstrap.php
 * Propósito: Carga configuración, dependencias, sesión, seguridad y servicios comunes de la aplicación.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

require_once __DIR__ . '/Env.php';
Env::load(dirname(__DIR__) . '/.env');
date_default_timezone_set((string)Env::get('APP_TIMEZONE', 'America/Bogota'));

/**
 * Determina si una IP pertenece a un host o CIDR permitido para proxy.
 */
function sivi_ip_in_cidr(string $ip, string $cidr): bool
{
    $cidr = trim($cidr);
    if ($cidr === '') return false;

    if (!str_contains($cidr, '/')) {
        $cidr .= str_contains($cidr, ':') ? '/128' : '/32';
    }
    [$network, $prefixRaw] = array_pad(explode('/', $cidr, 2), 2, '');
    $ipBinary = @inet_pton(trim($ip));
    $networkBinary = @inet_pton(trim($network));
    if ($ipBinary === false || $networkBinary === false) return false;
    if (strlen($ipBinary) !== strlen($networkBinary)) return false;

    $maximumBits = strlen($ipBinary) * 8;
    if (!ctype_digit($prefixRaw)) return false;
    $prefix = (int)$prefixRaw;
    if ($prefix < 0 || $prefix > $maximumBits) return false;

    $wholeBytes = intdiv($prefix, 8);
    $remainingBits = $prefix % 8;
    if ($wholeBytes > 0 && substr($ipBinary, 0, $wholeBytes) !== substr($networkBinary, 0, $wholeBytes)) {
        return false;
    }
    if ($remainingBits === 0) return true;

    $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
    return (ord($ipBinary[$wholeBytes]) & $mask)
        === (ord($networkBinary[$wholeBytes]) & $mask);
}

/**
 * Los encabezados X-Forwarded-* solo son confiables si REMOTE_ADDR pertenece
 * a la lista explícita APP_TRUSTED_PROXY_IPS.
 */
function sivi_request_from_trusted_proxy(): bool
{
    if (!Env::bool('APP_TRUST_PROXY_HEADERS', false)) return false;

    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if (filter_var($remote, FILTER_VALIDATE_IP) === false) return false;

    $configured = trim((string)Env::get('APP_TRUSTED_PROXY_IPS', ''));
    if ($configured === '') return false;

    foreach (preg_split('/[\s,;]+/', $configured) ?: [] as $cidr) {
        if ($cidr !== '' && sivi_ip_in_cidr($remote, $cidr)) return true;
    }
    return false;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name((string)Env::get('APP_SESSION_NAME', 'sivi_session'));
    $trustProxy = sivi_request_from_trusted_proxy();
    $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));
    $detectedHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($trustProxy && $forwardedProto === 'https');
    $sameSite = ucfirst(strtolower((string)Env::get('COOKIE_SAMESITE', 'Lax')));
    if (!in_array($sameSite, ['Lax','Strict','None'], true)) $sameSite = 'Lax';
    $secureCookie = Env::get('COOKIE_SECURE') === null ? $detectedHttps : Env::bool('COOKIE_SECURE', $detectedHttps);
    if ($sameSite === 'None') $secureCookie = true;
    $idleMinutes = max(5, min(240, (int)(Env::get('SESSION_IDLE_TIMEOUT_MINUTES', '30') ?? '30')));
    ini_set('session.gc_maxlifetime', (string)(max($idleMinutes + 5, 35) * 60));
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => Env::bool('COOKIE_HTTPONLY', true),
        'samesite' => $sameSite,
        'secure' => $secureCookie,
    ]);
    session_start();
}

require_once __DIR__ . '/AppVersion.php';
require_once __DIR__ . '/SetupPolicy.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/SecretVault.php';
require_once __DIR__ . '/AppSettings.php';
require_once __DIR__ . '/InitializationState.php';
require_once __DIR__ . '/ImportQuality.php';
require_once __DIR__ . '/PlatePolicy.php';
require_once __DIR__ . '/OnboardingService.php';
require_once __DIR__ . '/SiteQualityGate.php';
require_once __DIR__ . '/GlpiControlledSync.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Scope.php';
require_once __DIR__ . '/XlsxReader.php';
require_once __DIR__ . '/XlsxWriter.php';
require_once __DIR__ . '/DirectoryImporter.php';
require_once __DIR__ . '/SedeAssociator.php';
require_once __DIR__ . '/UserImporter.php';
require_once __DIR__ . '/WarehouseSedeAssociator.php';
require_once __DIR__ . '/MalwareScanner.php';
require_once __DIR__ . '/UploadSecurity.php';
require_once __DIR__ . '/SerialIntegrity.php';
require_once __DIR__ . '/AdditionalEquipmentIntegrity.php';
require_once __DIR__ . '/MobileScanBridge.php';
require_once __DIR__ . '/OperationalExperience.php';
require_once __DIR__ . '/ReportsCenter.php';
require_once __DIR__ . '/SystemHealth.php';
require_once __DIR__ . '/MicrosoftGraphClient.php';
require_once __DIR__ . '/NotificationTemplate.php';
require_once __DIR__ . '/NotificationQueue.php';
require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/WarehouseImporter.php';
require_once __DIR__ . '/Importer.php';
require_once __DIR__ . '/Mailer.php';
require_once __DIR__ . '/views.php';
