<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_split_topology_1_0_0_0.php
 * Propósito: Verifica que APP/DB permanezcan separados y parametrizables.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CheckRunner.php';

$root = dirname(__DIR__);
$check = new CheckRunner('split_topology_1.0.0.0', $root);

$app = (string)@file_get_contents($root . '/docker-compose.yml');
$db = (string)@file_get_contents($root . '/docker-compose-db.yml');
$appEnv = (string)@file_get_contents($root . '/config/environment.example');
$dbEnv = (string)@file_get_contents(
    $root . '/config/environment.db-server.example'
);
$views = (string)@file_get_contents($root . '/src/views.php');

$check->add(
    'app_server_parameterized',
    str_contains(
        $app,
        'APP_SERVER_HOSTNAME: ${APP_SERVER_HOSTNAME:?Configure APP_SERVER_HOSTNAME}'
    ),
    'APP_SERVER_HOSTNAME'
);
$check->add(
    'db_server_parameterized',
    str_contains($app, 'DB_HOST: ${DB_HOST:?Configure DB_HOST}')
        && str_contains(
            $db,
            'DB_SERVER_HOSTNAME: ${DB_SERVER_HOSTNAME:?Configure DB_SERVER_HOSTNAME}'
        ),
    'DB_HOST / DB_SERVER_HOSTNAME'
);
$check->add(
    'domain_parameterized',
    str_contains($app, 'APP_URL: ${APP_URL:?Configure APP_URL}')
        && str_contains(
            $app,
            'APP_INTERNAL_DNS: ${APP_INTERNAL_DNS:?Configure APP_INTERNAL_DNS}'
        )
        && str_contains($appEnv, 'APP_URL=https://REEMPLAZAR_DOMINIO_SIVI'),
    'APP_URL / APP_INTERNAL_DNS'
);
$check->add(
    'db_separate',
    !preg_match('/^  db:\s*$/m', $app)
        && preg_match('/^  db:\s*$/m', $db) === 1,
    'Dos stacks Docker'
);
$check->add(
    'db_private_listener_parameterized',
    str_contains($db, '${DB_LISTEN_IP:?Configure DB_LISTEN_IP}')
        && str_contains($dbEnv, 'DB_LISTEN_IP=REEMPLAZAR_IP_PRIVADA_DB'),
    'DB_LISTEN_IP'
);
$check->add(
    'db_tuned_for_8gb',
    str_contains($db, 'DB_INNODB_BUFFER_POOL_SIZE:-3G')
        && str_contains($db, 'DB_MAX_CONNECTIONS:-200'),
    '3G buffer pool / 200 conexiones'
);
$check->add(
    'app_containers',
    str_contains($app, 'APP_CONTAINER_NAME:-sivi-produccion-app')
        && str_contains($app, 'ANTIMALWARE_CONTAINER_NAME:-sivi-produccion-antimalware')
        && str_contains($app, 'NOTIFICATIONS_CONTAINER_NAME:-sivi-produccion-notificaciones')
        && str_contains($app, 'BACKUP_CONTAINER_NAME:-sivi-produccion-respaldos'),
    'Stack APP'
);
$check->add(
    'db_container',
    str_contains($db, 'DB_CONTAINER_NAME:-sivi-produccion-db'),
    'Stack DB'
);
$check->add(
    'onboarding_not_loaded_for_guest',
    substr_count($views, 'sivi-onboarding.js') === 1
        && str_contains(
            $views,
            '($user ? \'<link rel="stylesheet" href="assets/sivi-onboarding.css'
        ),
    'Sin 401 en login'
);

$check->outputAndExit();
