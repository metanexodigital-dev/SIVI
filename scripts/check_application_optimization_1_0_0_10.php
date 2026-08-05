<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_application_optimization_1_0_0_10.php
 * Propósito: Verifica optimizaciones conservando las funciones existentes.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CheckRunner.php';

$root = dirname(__DIR__);
$check = new CheckRunner('application_optimization_1.0.0.0', $root);

$appSettings = (string)@file_get_contents($root . '/src/AppSettings.php');
$initialization = (string)@file_get_contents($root . '/src/InitializationState.php');
$plate = (string)@file_get_contents($root . '/src/PlatePolicy.php');
$database = (string)@file_get_contents($root . '/src/Database.php');
$index = (string)@file_get_contents($root . '/public/index.php');
$apache = (string)@file_get_contents(
    $root . '/docker/apache-sivi-security-strong.conf'
);
$phpIni = (string)@file_get_contents($root . '/php.ini');
$schema = (string)@file_get_contents($root . '/database/schema.sql');

$check->add(
    'app_settings_request_cache',
    str_contains($appSettings, 'private static ?array $requestCache')
        && str_contains($appSettings, 'SELECT setting_key,setting_value FROM app_settings')
        && str_contains($appSettings, 'clearRequestCache'),
    'Una lectura de configuración por solicitud'
);
$check->add(
    'app_settings_prepared_write',
    str_contains($appSettings, '$statement = $pdo->prepare(')
        && str_contains($appSettings, '$statement->execute([$key, (string)$value])'),
    'Prepared statement reutilizado'
);
$check->add(
    'initialization_request_cache',
    str_contains($initialization, 'private static ?array $requestStatusCache')
        && str_contains($initialization, 'return self::$requestStatusCache'),
    'Estado inicial reutilizado'
);
$check->add(
    'plate_policy_request_cache',
    str_contains($plate, 'private static ?int $requestTotalCharacters')
        && str_contains($plate, 'SELECT setting_value FROM sivi_runtime_settings')
        && str_contains($plate, 'if (!self::$schemaEnsured)'),
    'Lectura directa y contingencia de esquema'
);
$check->add(
    'schema_status_grouped',
    str_contains($database, 'SELECT table_name FROM information_schema.tables')
        && str_contains($database, 'SELECT table_name,column_name')
        && str_contains($database, 'schemaStatusCache'),
    'Consultas agrupadas a information_schema'
);
$check->add(
    'campaign_refresh_once',
    str_contains($index, 'static $refreshed = false;')
        && str_contains($index, 'if ($refreshed) return;'),
    'Actualización de estados una vez por solicitud'
);
$check->add(
    'campaign_access_single_query',
    str_contains($index, 'function accessible_campaign_rows(): array')
        && str_contains($index, '$accessibleCampaigns = accessible_campaign_rows();'),
    'Campañas visibles sin N+1'
);
$check->add(
    'dashboard_quality_no_n_plus_one',
    str_contains($index, 'quality_evidence')
        && str_contains($index, 'quality_notes')
        && str_contains($index, 'quality_returned')
        && str_contains($index, 'site_quality_score_values('),
    'Calidad incluida en agregación del dashboard'
);
$check->add(
    'reopening_scope_in_sql',
    str_contains($index, '[$closedWhere,$closedParams]=Scope::sedeCondition')
        && str_contains($index, "cs.status='cerrado' AND {\$closedWhere}"),
    'Sedes cerradas filtradas directamente en SQL'
);
$check->add(
    'draft_session_release',
    str_contains($index, '$userId=(int)(Auth::id()??0);')
        && str_contains($index, 'if(session_status()===PHP_SESSION_ACTIVE) session_write_close();'),
    'Autosave no retiene bloqueo de sesión'
);
$check->add(
    'catalog_session_release',
    str_contains($index, 'function additional_catalog_page(): void')
        && str_contains($index, "Cache-Control: private, max-age=60"),
    'Catálogo libera sesión y permite caché privada'
);
$check->add(
    'http_compression',
    str_contains($apache, 'AddOutputFilterByType DEFLATE')
        && str_contains($apache, 'ExpiresActive On'),
    'Compresión y expiración de estáticos'
);
$check->add(
    'service_worker_no_cache',
    str_contains($apache, 'sw\\.js|manifest\\.webmanifest')
        && str_contains($apache, 'no-cache, no-store, must-revalidate'),
    'Actualizaciones PWA no quedan congeladas'
);
$check->add(
    'php_runtime',
    str_contains($phpIni, 'session.lazy_write=1')
        && str_contains($phpIni, 'mysqlnd.collect_statistics=0'),
    'Menor escritura de sesión e instrumentación mysqlnd'
);
$check->add(
    'production_baseline_visible',
    str_contains($index, 'Primera producción: <strong>1.0.0.0</strong>'),
    'Línea base oficial preservada'
);
$check->add(
    'schema_unchanged_by_release',
    !str_contains($schema, 'SIVI 1.0.0.0'),
    'La optimización no agrega migraciones'
);

$entrypoint = (string)@file_get_contents($root . '/docker/entrypoint.sh');
$check->add(
    'no_automatic_migrations',
    !str_contains($entrypoint, 'scripts/migrate.php'),
    'AUTO_MIGRATE=false'
);

$check->outputAndExit();
