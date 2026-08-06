<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/check_runtime_parameterization_1_0_0_0.php
 * Propósito: Impide que hostnames, dominio o IP del entorno de pruebas/producción
 * queden codificados dentro de la aplicación.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CheckRunner.php';

$root = dirname(__DIR__);
$check = new CheckRunner('runtime_parameterization_1.0.0.0', $root);

$app = (string)@file_get_contents($root . '/docker-compose.yml');
$db = (string)@file_get_contents($root . '/docker-compose-db.yml');
$preflight = (string)@file_get_contents($root . '/scripts/preflight.php');
$appEnv = (string)@file_get_contents($root . '/config/environment.example');
$dbEnv = (string)@file_get_contents(
    $root . '/config/environment.db-server.example'
);
$dastPath = $root . '/.github/workflows/sivi-dast.yml';
$dastExists = is_file($dastPath);
$dast = $dastExists ? (string)file_get_contents($dastPath) : '';

$check->add(
    'app_host_required',
    str_contains(
        $app,
        '${APP_SERVER_HOSTNAME:?Configure APP_SERVER_HOSTNAME}'
    ),
    'APP_SERVER_HOSTNAME'
);
$check->add(
    'db_host_required',
    str_contains($app, '${DB_HOST:?Configure DB_HOST}')
        && str_contains(
            $db,
            '${DB_SERVER_HOSTNAME:?Configure DB_SERVER_HOSTNAME}'
        ),
    'DB_HOST / DB_SERVER_HOSTNAME'
);
$check->add(
    'app_url_required',
    str_contains($app, '${APP_URL:?Configure APP_URL}')
        && str_contains(
            $app,
            '${APP_INTERNAL_DNS:?Configure APP_INTERNAL_DNS}'
        ),
    'APP_URL / APP_INTERNAL_DNS'
);
$check->add(
    'db_listen_ip_required',
    str_contains($db, '${DB_LISTEN_IP:?Configure DB_LISTEN_IP}'),
    'DB_LISTEN_IP'
);
$check->add(
    'examples_are_placeholders',
    str_contains($appEnv, 'REEMPLAZAR_HOST_APP')
        && str_contains($appEnv, 'REEMPLAZAR_HOST_O_IP_DB')
        && str_contains($appEnv, 'REEMPLAZAR_DOMINIO_SIVI')
        && str_contains($dbEnv, 'REEMPLAZAR_IP_PRIVADA_DB'),
    'Sin infraestructura fija'
);
$check->add(
    'preflight_accepts_hostname_or_ip',
    str_contains($preflight, 'FILTER_VALIDATE_IP')
        && str_contains($preflight, 'Resolución hostname/IP de DB'),
    'Hostname o IP'
);
$check->add(
    'dast_target_is_runtime_input',
    !$dastExists || (
        str_contains($dast, 'workflow_dispatch:')
        && str_contains($dast, 'inputs:')
        && str_contains($dast, 'target_url:')
        && str_contains($dast, '${{ inputs.target_url }}')
        && !str_contains($dast, 'sivi.registraduria.gov.co')
    ),
    $dastExists
        ? 'URL ingresada al ejecutar workflow'
        : 'Workflow excluido deliberadamente de la imagen por .dockerignore'
);

$fixedValues = [
    'LOPGVIAPP01',
    'LOPGVIDB01',
    'sivi.registraduria.gov.co',
];

$operationalFiles = [
    $app,
    $db,
    $preflight,
    (string)@file_get_contents($root . '/scripts/check_split_topology_1_0_0_0.php'),
    (string)@file_get_contents($root . '/scripts/check_container_names.php'),
    (string)@file_get_contents($root . '/scripts/check_production_baseline.php'),
    (string)@file_get_contents($root . '/scripts/check_clean_relaunch_1_0_0_0.php'),
];

$hardcoded = false;
foreach ($fixedValues as $fixed) {
    foreach ($operationalFiles as $content) {
        if (str_contains($content, $fixed)) {
            $hardcoded = true;
            break 2;
        }
    }
}
$check->add(
    'previous_infrastructure_not_hardcoded',
    !$hardcoded,
    'Host/domain anteriores retirados de controles operativos'
);

$check->outputAndExit();
