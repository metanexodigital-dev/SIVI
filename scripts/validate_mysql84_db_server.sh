#!/usr/bin/env bash
set -Eeuo pipefail
CONTAINER="${DB_CONTAINER_NAME:-sivi-pruebas-db}"
echo '=== Docker ==='; docker --version; docker compose version
echo '=== Contenedor ==='; docker ps --filter "name=^/${CONTAINER}$"
echo '=== Health ==='; docker inspect --format='{{.State.Status}} {{if .State.Health}}{{.State.Health.Status}}{{end}}' "$CONTAINER"
echo '=== MySQL ==='; docker exec "$CONTAINER" mysql --version
echo '=== TLS ==='; docker exec "$CONTAINER" bash -lc 'CNF=$$(mktemp); printf "[client]\nuser=root\npassword=%s\nprotocol=socket\n" "$$(cat /run/secrets/mysql_root_password)" > $$CNF; chmod 600 $$CNF; mysql --defaults-extra-file=$$CNF -NBe "SELECT @@version, @@require_secure_transport; SHOW STATUS LIKE \"Ssl_version\";"; rm -f $$CNF'
