<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: src/SystemHealth.php
 * Propósito: Reúne comprobaciones de disponibilidad, almacenamiento y estado de servicios.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

/**
 * Diagnóstico operativo seguro de SIVI.
 * No devuelve credenciales, tokens ni valores sensibles de Environment.
 */
final class SystemHealth
{
    /** @return array<string,mixed> */
    public static function snapshot(): array
    {
        $checks = [];
        $add = static function (string $key, string $label, bool $ok, string $detail, bool $critical = true) use (&$checks): void {
            $checks[] = [
                'key' => $key,
                'label' => $label,
                'ok' => $ok,
                'critical' => $critical,
                'detail' => $detail,
            ];
        };

        $policy = AppVersion::policy();
        $add(
            'version',
            'Versión del paquete',
            $policy['errors'] === [],
            AppVersion::package() . ' · ' . AppVersion::environmentLabel() . ' · ' . AppVersion::buildId()
        );
        $add(
            'git_commit',
            'Commit del despliegue',
            AppVersion::gitCommit() !== null,
            AppVersion::gitCommit() ?? 'Configure APP_GIT_COMMIT para relacionar el despliegue con GitHub.',
            false
        );

        $appUrl = rtrim(trim((string)Env::get('APP_URL', '')), '/');
        $validUrl = filter_var($appUrl, FILTER_VALIDATE_URL) !== false;
        $https = $validUrl && strtolower((string)parse_url($appUrl, PHP_URL_SCHEME)) === 'https';
        $add('app_url', 'Dominio público HTTPS', $https, $appUrl !== '' ? $appUrl : 'APP_URL no está configurada.');
        $add(
            'proxy_headers',
            'Encabezados del proxy',
            Env::bool('APP_TRUST_PROXY_HEADERS', false),
            Env::bool('APP_TRUST_PROXY_HEADERS', false) ? 'Habilitados para Dokploy/Traefik.' : 'Active APP_TRUST_PROXY_HEADERS=true.'
        );
        $add(
            'secure_cookie',
            'Cookie segura',
            Env::bool('COOKIE_SECURE', false),
            Env::bool('COOKIE_SECURE', false) ? 'Las sesiones requieren HTTPS.' : 'Active COOKIE_SECURE=true.'
        );

        $dbConnected = false;
        $dbLatency = null;
        try {
            $started = microtime(true);
            Database::connection()->query('SELECT 1');
            $dbLatency = round((microtime(true) - $started) * 1000, 1);
            $dbConnected = true;
            $add('database', 'MySQL', true, 'Conectada · ' . $dbLatency . ' ms');
        } catch (Throwable $e) {
            $add('database', 'MySQL', false, 'No fue posible consultar la base de datos.');
        }

        $schema = null;
        if ($dbConnected) {
            try {
                $schema = Database::schemaStatus();
                $missing = count($schema['missing_tables'] ?? []) + count($schema['missing_columns'] ?? []);
                $add('schema', 'Esquema de base de datos', (bool)($schema['ok'] ?? false), ($schema['ok'] ?? false) ? 'Vigente.' : 'Objetos pendientes: ' . $missing . '.');
            } catch (Throwable) {
                $add('schema', 'Esquema de base de datos', false, 'No fue posible verificar el esquema.');
            }
        }

        if ($dbConnected) {
            try {
                $mailConfig = AppSettings::notificationConfig();
                $provider = (string)$mailConfig['provider'];
                $mailReady = !$mailConfig['enabled'] || ($provider === 'microsoft_graph' && (bool)$mailConfig['graph_configured']) || in_array($provider, ['log','mail','smtp'], true);
                $mailDetail = !$mailConfig['enabled']
                    ? 'Notificaciones externas deshabilitadas.'
                    : ($provider === 'microsoft_graph'
                        ? ((bool)$mailConfig['graph_configured'] ? 'Microsoft Graph configurado · ' . (string)$mailConfig['sender_address'] : 'Microsoft Graph seleccionado, pero la configuración está incompleta.')
                        : 'Proveedor activo: ' . $provider . '.');
                $add('notifications', 'Notificaciones externas', $mailReady, $mailDetail, (bool)$mailConfig['enabled']);
                if ((bool)$mailConfig['enabled'] && $provider === 'microsoft_graph') {
                    $add('encryption_key', 'Cifrado de credenciales', (bool)$mailConfig['encryption_key_configured'], (bool)$mailConfig['encryption_key_configured'] ? 'APP_ENCRYPTION_KEY disponible.' : 'Configure APP_ENCRYPTION_KEY.', true);
                }
                $queueStats = NotificationQueue::stats();
                $add('notification_queue', 'Cola de notificaciones', (int)$queueStats['errores'] === 0, 'Pendientes: ' . (int)$queueStats['pendientes'] . ' · Procesando: ' . (int)$queueStats['procesando'] . ' · Errores: ' . (int)$queueStats['errores'] . '.', false);
            } catch (Throwable) {
                $add('notifications', 'Notificaciones externas', false, 'No fue posible verificar la configuración o la cola.', false);
            }
        }

        $storage = dirname(__DIR__) . '/storage';
        $writable = is_dir($storage) && is_writable($storage);
        $free = is_dir($storage) ? @disk_free_space($storage) : false;
        $total = is_dir($storage) ? @disk_total_space($storage) : false;
        $freePercent = ($free !== false && $total) ? round(($free / $total) * 100, 1) : null;
        $add('storage', 'Almacenamiento', $writable, $writable ? 'Escribible.' : 'El volumen app_storage no es escribible.');
        $diskOk = $freePercent === null || $freePercent >= 10;
        $add('disk', 'Espacio disponible', $diskOk, $freePercent === null ? 'No disponible.' : $freePercent . ' % libre.', $freePercent !== null && $freePercent < 5);

        foreach (['pdo_mysql','mbstring','zip','xmlreader','simplexml','gd','openssl','curl'] as $extension) {
            $add('ext_' . $extension, 'PHP · ' . $extension, extension_loaded($extension), extension_loaded($extension) ? 'Disponible.' : 'Extensión faltante.');
        }

        foreach (['qrencode','zbarimg','tesseract'] as $binary) {
            $path = self::binaryPath($binary);
            $add('bin_' . $binary, 'Herramienta · ' . $binary, $path !== null, $path ?? 'No instalada.', $binary !== 'tesseract');
        }

        $root = dirname(__DIR__);
        foreach ([
            'public/manifest.webmanifest' => 'Manifiesto PWA',
            'public/sw.js' => 'Service Worker',
            'public/offline.html' => 'Página sin conexión',
            'Dockerfile' => 'Dockerfile',
            'docker-compose.yml' => 'Docker Compose',
        ] as $relative => $label) {
            $add('file_' . str_replace(['/', '.'], '_', $relative), $label, is_file($root . '/' . $relative) && filesize($root . '/' . $relative) > 0, $relative);
        }

        $conflicts = self::deploymentConflictFiles();
        $add('git_conflicts', 'Conflictos de Git', $conflicts === [], $conflicts === [] ? 'No se encontraron marcadores.' : implode(', ', $conflicts));

        $lastBackup = null;
        if ($dbConnected) {
            try {
                $lastBackup = Database::fetchOne('SELECT file_name,file_size,sha256,created_at FROM backup_history ORDER BY id DESC LIMIT 1');
            } catch (Throwable) {
                $lastBackup = null;
            }
        }
        if ($lastBackup) {
            $backupPath = $storage . '/backups/' . basename((string)$lastBackup['file_name']);
            $exists = is_file($backupPath) && filesize($backupPath) > 0;
            $add('backup', 'Último respaldo de aplicación', $exists, (string)$lastBackup['created_at'] . ' · ' . (string)$lastBackup['file_name'], false);
        } else {
            $add('backup', 'Último respaldo de aplicación', false, 'Todavía no existe un respaldo registrado.', false);
        }

        $criticalFailures = array_values(array_filter($checks, static fn(array $check): bool => $check['critical'] && !$check['ok']));
        $warnings = array_values(array_filter($checks, static fn(array $check): bool => !$check['critical'] && !$check['ok']));

        return [
            'ok' => $criticalFailures === [],
            'status' => $criticalFailures === [] ? ($warnings === [] ? 'ok' : 'warning') : 'error',
            'generated_at' => date(DATE_ATOM),
            'version' => AppVersion::package(),
            'environment' => AppVersion::environment(),
            'database_latency_ms' => $dbLatency,
            'storage_free_percent' => $freePercent,
            'critical_failures' => count($criticalFailures),
            'warnings' => count($warnings),
            'checks' => $checks,
        ];
    }

    private static function binaryPath(string $binary): ?string
    {
        $output = [];
        $status = 1;
        @exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null', $output, $status);
        $path = trim((string)($output[0] ?? ''));
        return $status === 0 && $path !== '' ? $path : null;
    }

    /** @return array<int,string> */
    private static function deploymentConflictFiles(): array
    {
        $root = dirname(__DIR__);
        $files = ['Dockerfile','docker-compose.yml','.env.example','config/environment.example','README.md'];
        $found = [];
        foreach ($files as $relative) {
            $path = $root . '/' . $relative;
            if (!is_file($path)) continue;
            $contents = (string)file_get_contents($path);
            if (preg_match('/^(<<<<<<< .*|=======|>>>>>>> .*)$/m', $contents) === 1) {
                $found[] = $relative;
            }
        }
        return $found;
    }
}
