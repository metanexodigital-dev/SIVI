#!/usr/bin/env bash
set -Eeuo pipefail
CONTAINER="${DB_CONTAINER_NAME:-sivi-pruebas-db}"

echo '=== Docker ==='
docker --version
docker compose version

echo '=== Contenedor ==='
docker ps --filter "name=^/${CONTAINER}$"

echo '=== Health ==='
docker inspect --format='{{.State.Status}} {{if .State.Health}}{{.State.Health.Status}}{{end}} restart={{.RestartCount}}' "$CONTAINER"

echo '=== MySQL ==='
docker exec "$CONTAINER" mysql --version

echo '=== Integridad SIVI + TLS ==='
docker exec "$CONTAINER" bash -lc '
CNF=$(mktemp)
trap '\''rm -f "$CNF"'\'' EXIT INT TERM
chmod 600 "$CNF"
printf "[client]\nuser=root\npassword=%s\nprotocol=socket\n" "$(cat /run/secrets/mysql_root_password)" > "$CNF"

mysql --defaults-extra-file="$CNF" -NBe "
SELECT CONCAT('\''version='\'',@@version);
SELECT CONCAT('\''require_secure_transport='\'',@@require_secure_transport);
SELECT CONCAT('\''db='\'',SCHEMA_NAME) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='\''${MYSQL_DATABASE}'\'';
SELECT CONCAT('\''tables='\'',COUNT(*)) FROM information_schema.TABLES WHERE TABLE_SCHEMA='\''${MYSQL_DATABASE}'\'';
SELECT CONCAT('\''backup_user='\'',COUNT(*)) FROM mysql.user WHERE user='\''${SIVI_BACKUP_USER:-sivi_backup}'\'' AND host='\''%'\'';
SELECT CONCAT('\''release_table='\'',COUNT(*)) FROM information_schema.TABLES WHERE TABLE_SCHEMA='\''${MYSQL_DATABASE}'\'' AND TABLE_NAME='\''app_release_history'\'';
SELECT CONCAT('\''schema_migrations='\'',COUNT(*)) FROM information_schema.TABLES WHERE TABLE_SCHEMA='\''${MYSQL_DATABASE}'\'' AND TABLE_NAME='\''schema_migrations'\'';
SELECT CONCAT('\''tls_main='\'',VALUE) FROM performance_schema.tls_channel_status WHERE CHANNEL='\''mysql_main'\'' AND PROPERTY='\''Enabled'\'';
SHOW GLOBAL STATUS LIKE '\''Current_tls_ca'\'';
SHOW GLOBAL STATUS LIKE '\''Current_tls_cert'\'';
SHOW GLOBAL STATUS LIKE '\''Current_tls_version'\'';
"
'
