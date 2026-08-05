#!/usr/bin/env bash
# Prepara certificados TLS montados como Docker Secrets antes de iniciar MySQL.
set -Eeuo pipefail
umask 077

TLS_DIR=/etc/mysql/sivi-tls
mkdir -p "$TLS_DIR"

copy_tls() {
  local source="$1" target="$2" mode="$3"
  [[ -r "$source" ]] || { echo "SIVI DB: falta $source" >&2; exit 1; }
  install -o mysql -g mysql -m "$mode" "$source" "$target"
}

copy_tls /run/secrets/db_ca.pem "$TLS_DIR/ca.pem" 0644
copy_tls /run/secrets/db_server_cert.pem "$TLS_DIR/server-cert.pem" 0644
copy_tls /run/secrets/db_server_key.pem "$TLS_DIR/server-key.pem" 0600

exec /usr/local/bin/docker-entrypoint.sh "$@"
