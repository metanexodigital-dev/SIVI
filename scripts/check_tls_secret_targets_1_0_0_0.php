<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/CheckRunner.php';
$root = dirname(__DIR__);
$c = new CheckRunner('tls_secret_targets_1.0.0.0', $root);
$app = (string) @file_get_contents($root . '/docker-compose.yml');
$db = (string) @file_get_contents($root . '/docker-compose-db.yml');
$wrapper = (string) @file_get_contents($root . '/docker/db/mysql-entrypoint-wrapper.sh');

$c->add('db_ca_target', str_contains($db, "source: db_ca\n        target: db_ca.pem"), 'DB CA -> db_ca.pem');
$c->add('db_cert_target', str_contains($db, "source: db_server_cert\n        target: db_server_cert.pem"), 'DB cert -> db_server_cert.pem');
$c->add('db_key_target', str_contains($db, "source: db_server_key\n        target: db_server_key.pem"), 'DB key -> db_server_key.pem');
$c->add('app_ca_targets', substr_count($app, "source: db_ca\n        target: db_ca.pem") >= 3, 'APP/notifications/backup CA');
$c->add('db_wrapper_alignment', str_contains($wrapper, '/run/secrets/db_ca.pem') && str_contains($wrapper, '/run/secrets/db_server_cert.pem') && str_contains($wrapper, '/run/secrets/db_server_key.pem'), 'Wrapper alineado con targets');
$c->outputAndExit();
