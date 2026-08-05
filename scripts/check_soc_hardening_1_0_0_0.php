<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_soc_hardening_1_0_0_0.php
 * Propósito: Verifica controles técnicos previos a revisión SOC.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CheckRunner.php';
$root = dirname(__DIR__);
$check = new CheckRunner('soc_hardening_1.0.0.0', $root);

$database = (string)@file_get_contents($root . '/src/Database.php');
$env = (string)@file_get_contents($root . '/src/Env.php');
$helpers = (string)@file_get_contents($root . '/src/helpers.php');
$upload = (string)@file_get_contents($root . '/src/UploadSecurity.php');
$malware = (string)@file_get_contents($root . '/src/MalwareScanner.php');
$index = (string)@file_get_contents($root . '/public/index.php');
$mobile = (string)@file_get_contents($root . '/src/MobileScanBridge.php');
$appCompose = (string)@file_get_contents($root . '/docker-compose.yml');
$dbCompose = (string)@file_get_contents($root . '/docker-compose-db.yml');
$apache = (string)@file_get_contents($root . '/docker/apache-sivi-security-strong.conf');
$htaccess = (string)@file_get_contents($root . '/public/.htaccess');
$leastPrivilege = (string)@file_get_contents($root . '/docker/db/98-sivi-least-privilege.sh');
$dbRegister = (string)@file_get_contents($root . '/docker/db/99-sivi-register-release.sh');
$secretPrep = (string)@file_get_contents($root . '/scripts/prepare_soc_secret_files.sh');
$backup = (string)@file_get_contents($root . '/scripts/backup_dokploy.sh');
$restore = (string)@file_get_contents($root . '/scripts/restore_dokploy_backup.sh');
$health = (string)@file_get_contents($root . '/public/health.php');
$ready = (string)@file_get_contents($root . '/public/ready.php');
$bootstrap = (string)@file_get_contents($root . '/src/bootstrap.php');
$install = (string)@file_get_contents($root . '/scripts/install.php');
$publicIndex = $index;

$check->add(
    'docker_secrets',
    str_contains($env, "return 'file';")
        && str_contains($appCompose, 'APP_ENCRYPTION_KEY_FILE: /run/secrets/app_encryption_key')
        && str_contains($appCompose, 'DB_PASSWORD_FILE: /run/secrets/db_password'),
    'Secretos leídos desde archivos'
);
$check->add(
    'database_tls_client',
    str_contains($database, 'PDO::MYSQL_ATTR_SSL_CA')
        && str_contains($database, 'MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')
        && str_contains($database, "SHOW SESSION STATUS LIKE 'Ssl_version'"),
    'TLS verificado desde PHP'
);
$check->add(
    'database_tls_server',
    str_contains($dbCompose, '--require-secure-transport=ON')
        && str_contains($dbCompose, '--tls-version=TLSv1.2,TLSv1.3')
        && str_contains($dbCompose, '--ssl-cert=/etc/mysql/sivi-tls/server-cert.pem'),
    'MySQL 8.4 exige TLS'
);
$check->add(
    'least_privilege_database',
    str_contains($leastPrivilege, 'GRANT SELECT, INSERT, UPDATE, DELETE')
        && str_contains($leastPrivilege, 'sivi_backup')
        && !str_contains($leastPrivilege, 'GRANT ALL'),
    'APP CRUD y backup de solo lectura'
);
$check->add(
    'malware_scanning',
    str_contains($malware, 'INSTREAM')
        && str_contains($upload, 'MalwareScanner::scanOrFail')
        && str_contains($mobile, 'MalwareScanner::scanOrFail')
        && str_contains($appCompose, 'clamav/clamav:1.4.5'),
    'Carga de archivos pasa por antimalware'
);
$check->add(
    'xlsx_structure_validation',
    str_contains($upload, '[Content_Types].xml')
        && str_contains($upload, 'xl/workbook.xml')
        && str_contains($upload, '10000')
        && str_contains($upload, '$maximumExpandedBytes')
        && str_contains($upload, '$maximumCompressionRatio'),
    'XLSX validado como ZIP Office y protegido contra expansión anómala'
);
$cspExpected = "style-src 'self'; script-src 'self'";
$check->add(
    'strict_csp',
    str_contains($apache, $cspExpected)
        && str_contains($htaccess, $cspExpected)
        && !str_contains($apache, "'unsafe-inline'")
        && !str_contains($htaccess, "'unsafe-inline'"),
    'CSP sin unsafe-inline'
);
$check->add(
    'browser_headers',
    str_contains($apache, 'X-Frame-Options "DENY"')
        && str_contains($apache, 'Strict-Transport-Security')
        && str_contains($apache, 'Cross-Origin-Opener-Policy')
        && str_contains($apache, 'Cross-Origin-Resource-Policy'),
    'Cabeceras de navegador endurecidas'
);
$check->add(
    'structured_security_events',
    str_contains($helpers, 'SIVI_SECURITY_EVENT')
        && str_contains($helpers, "'request_path'"),
    'Eventos para SIEM sin query string'
);
$check->add(
    'encrypted_backups_only',
    str_contains($backup, 'BACKUP_ENCRYPTION_ENABLED')
        && str_contains($backup, '600000')
        && str_contains($index, "glob(\$dir . '/SIVI-*.tar.gz.enc')")
        && !str_contains($index, '.json.gz'),
    'Respaldo operativo cifrado'
);
$check->add(
    'controlled_restore_credentials',
    str_contains($restore, 'RESTORE_DB_USERNAME')
        && str_contains($restore, 'RESTORE_DB_PASSWORD')
        && str_contains($restore, 'RESTORE_CONFIRM=SIVI-RESTORE'),
    'Restauración requiere credencial elevada explícita'
);
$check->add(
    'container_hardening',
    substr_count($appCompose, 'cap_drop:') >= 3
        && substr_count($appCompose, 'no-new-privileges:true') >= 4
        && str_contains($appCompose, 'pids_limit: 256'),
    'Capacidades y PIDs limitados'
);
$runtimeSources = '';
foreach (glob($root . '/src/*.php') ?: [] as $file) {
    if (basename($file) === 'Database.php') continue;
    $runtimeSources .= (string)@file_get_contents($file) . "\n";
}
$check->add(
    'no_runtime_ddl',
    preg_match('/CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE/i', $runtimeSources) !== 1,
    'Sin DDL en módulos operativos'
);
$check->add(
    'security_ci',
    is_file($root . '/.github/workflows/sivi-soc-security.yml')
        && is_file($root . '/.github/workflows/sivi-dast.yml'),
    'SAST, CVE, secretos, SBOM y DAST definidos'
);


$check->add(
    'minimal_public_health',
    !str_contains($health, "'build_id'")
        && !str_contains($health, "'latency_ms'")
        && !str_contains($ready, "'build_id'")
        && !str_contains($ready, "'checks' => \$checks"),
    'Health/readiness no enumeran versión, esquema ni componentes'
);
$check->add(
    'setup_without_runtime_ddl',
    !str_contains($publicIndex, 'Database::migrate();')
        && !str_contains($install, 'Database::migrate();')
        && str_contains($publicIndex, 'Database::schemaStatus()'),
    'Setup verifica esquema preinicializado y solo crea el Superadministrador'
);
$check->add(
    'legacy_schema_tools_removed',
    !is_file($root . '/scripts/migrate.php')
        && !is_file($root . '/scripts/apply_secure_schema.php')
        && !is_file($root . '/scripts/install_guided_ux_patch.php')
        && !is_dir($root . '/database/migrations')
        && !str_contains($database, 'public static function migrate'),
    'La línea base no distribuye herramientas ni archivos históricos de cambio de esquema'
);


$check->add(
    'trusted_proxy_allowlist',
    str_contains($bootstrap, 'APP_TRUSTED_PROXY_IPS')
        && str_contains($bootstrap, 'sivi_request_from_trusted_proxy')
        && str_contains($helpers, 'sivi_request_from_trusted_proxy'),
    'X-Forwarded-* solo se acepta desde proxies autorizados'
);


$check->add(
    'db_init_secret_hygiene',
    str_contains($leastPrivilege, '--defaults-extra-file="$CLIENT_CNF"')
        && str_contains($dbRegister, '--defaults-extra-file="$CLIENT_CNF"')
        && !str_contains($dbCompose, '-p"$$(cat /run/secrets/db_password)"'),
    'Contraseñas DB no se incluyen en argumentos de procesos de inicialización/health'
);
$check->add(
    'role_aware_secret_preparation',
    str_contains($secretPrep, 'app|db|all')
        && str_contains($secretPrep, 'db_password, db_backup_password y mysql_root_password'),
    'Generación de secretos separada por servidor'
);

$check->outputAndExit();
