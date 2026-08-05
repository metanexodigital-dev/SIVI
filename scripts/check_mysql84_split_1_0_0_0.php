<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/CheckRunner.php';
$root=dirname(__DIR__); $c=new CheckRunner('mysql84_split_1.0.0.0',$root);
$db=(string)@file_get_contents($root.'/docker-compose-db.yml');
$df=(string)@file_get_contents($root.'/docker/db/Dockerfile');
$schema=(string)@file_get_contents($root.'/database/schema.mysql84.sql');
$lp=(string)@file_get_contents($root.'/docker/db/98-sivi-least-privilege.sh');
$app=(string)@file_get_contents($root.'/docker-compose.yml');
$c->add('mysql_8_4_pinned', str_contains($df,'FROM mysql:8.4.10'),'mysql:8.4.10');
$c->add('separate_stacks', !preg_match('/^  db:\s*$/m',$app) && preg_match('/^  db:\s*$/m',$db)===1,'APP y DB separados');
$c->add('mysql_env_files', str_contains($db,'MYSQL_ROOT_PASSWORD_FILE') && str_contains($db,'MYSQL_PASSWORD_FILE'),'Secretos MySQL');
$c->add('tls_required', str_contains($db,'--require-secure-transport=ON') && str_contains($db,'TLSv1.2,TLSv1.3'),'TLS obligatorio');
$c->add('mysql84_schema_helpers', str_contains($schema,'sivi_add_column_if_missing') && !preg_match('/ADD\s+COLUMN\s+IF\s+NOT\s+EXISTS/i',$schema) && !preg_match('/CREATE\s+INDEX\s+IF\s+NOT\s+EXISTS/i',$schema),'DDL compatible');
$c->add('least_privilege', str_contains($lp,'GRANT SELECT, INSERT, UPDATE, DELETE') && str_contains($lp,'REQUIRE SSL'),'CRUD + TLS');
$c->outputAndExit();
