#!/usr/bin/env bash
# Prepara certificados TLS y secretos operativos antes de iniciar MySQL.
set -Eeuo pipefail
umask 077

TLS_DIR=/etc/mysql/sivi-tls
RUNTIME_SECRET_DIR=/run/sivi-secrets

# MySQL corre como usuario mysql. Los directorios se crean explícitamente con
# propietario y permisos que permiten lectura únicamente al proceso MySQL.
install -d -o mysql -g mysql -m 0750 "$TLS_DIR"
install -d -o mysql -g mysql -m 0700 "$RUNTIME_SECRET_DIR"

copy_tls() {
  local source="$1" target="$2" mode="$3"
  [[ -r "$source" ]] || { echo "SIVI DB: falta $source" >&2; exit 1; }
  install -o mysql -g mysql -m "$mode" "$source" "$target"
}

copy_runtime_secret() {
  local source="$1" target="$2"
  [[ -r "$source" ]] || { echo "SIVI DB: falta $source" >&2; exit 1; }
  install -o mysql -g mysql -m 0400 "$source" "$target"
}

copy_tls /run/secrets/db_ca.pem "$TLS_DIR/ca.pem" 0644
copy_tls /run/secrets/db_server_cert.pem "$TLS_DIR/server-cert.pem" 0644
copy_tls /run/secrets/db_server_key.pem "$TLS_DIR/server-key.pem" 0600

# Docker Compose monta los secretos con propietario root y modo 0600. El
# entrypoint oficial de MySQL termina ejecutando los scripts init como usuario
# mysql; por eso el secreto personalizado de respaldo se copia a un runtime
# privado accesible únicamente por mysql.
copy_runtime_secret /run/secrets/db_backup_password "$RUNTIME_SECRET_DIR/db_backup_password"
export SIVI_BACKUP_PASSWORD_FILE="$RUNTIME_SECRET_DIR/db_backup_password"

exec /usr/local/bin/docker-entrypoint.sh "$@"
