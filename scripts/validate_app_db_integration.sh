#!/usr/bin/env bash
set -Eeuo pipefail
CONTAINER="${APP_CONTAINER_NAME:-sivi-pruebas-app}"
docker exec "$CONTAINER" php -r 'require "/var/www/html/src/bootstrap.php"; $pdo=Database::connection(); $v=$pdo->query("SELECT VERSION()")->fetchColumn(); $s=$pdo->query("SHOW SESSION STATUS LIKE \"Ssl_version\"")->fetch(PDO::FETCH_ASSOC); echo "DB_VERSION=$v\nTLS=".($s["Value"]??"")."\n";'
docker exec "$CONTAINER" php scripts/check_mysql84_split_1_0_0_0.php
docker exec "$CONTAINER" php scripts/check_split_topology_1_0_0_0.php
docker exec "$CONTAINER" php scripts/check_soc_hardening_1_0_0_0.php
