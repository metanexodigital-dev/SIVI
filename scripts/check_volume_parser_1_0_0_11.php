<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo conservado por compatibilidad del motor de controles.
 * Verifica que el esquema dividido conserve los volúmenes persistentes.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CheckRunner.php';

$root = dirname(__DIR__);
$check = new CheckRunner('split_volume_parser', $root);

$app = (string)@file_get_contents($root . '/docker-compose.yml');
$db = (string)@file_get_contents($root . '/docker-compose-db.yml');

$check->add(
    'app_storage',
    str_contains($app, 'app_storage:/var/www/html/storage')
        && str_contains($app, "\n  app_storage:"),
    'Servidor APP'
);
$check->add(
    'sivi_backups',
    str_contains($app, 'sivi_backups:/backups')
        && str_contains($app, "\n  sivi_backups:"),
    'Servidor APP'
);
$check->add(
    'db_data',
    str_contains($db, 'db_data:/var/lib/mysql')
        && str_contains($db, "\n  db_data:"),
    'Servidor DB'
);
$check->add(
    'separate_compose',
    !preg_match('/^  db:\s*$/m', $app)
        && preg_match('/^  db:\s*$/m', $db) === 1,
    'Base separada'
);

$check->outputAndExit();
