#!/usr/bin/env bash
# SIVI - secretos por servidor para topología APP + MySQL 8.4 independiente.
set -Eeuo pipefail
umask 077
ROLE="${1:-all}"
TARGET="${2:-/opt/sivi/secrets}"
case "$ROLE" in app|db|all) ;; *) echo "Uso: $0 [app|db|all] [/ruta/secretos]" >&2; exit 2;; esac
mkdir -p "$TARGET"; chmod 700 "$TARGET"
random_b64(){ openssl rand -base64 48 | tr -d '\r\n'; }
random_hex(){ openssl rand -hex 48 | tr -d '\r\n'; }
create_if_missing(){ local p="$1" v="$2"; if [[ -e "$p" ]]; then chmod 600 "$p"; echo "Conservado: $p"; else printf '%s\n' "$v" > "$p"; chmod 600 "$p"; echo "Creado: $p"; fi; }
if [[ "$ROLE" == app || "$ROLE" == all ]]; then
  create_if_missing "$TARGET/app_setup_key" "$(random_hex)"
  create_if_missing "$TARGET/app_encryption_key" "$(random_b64)"
  create_if_missing "$TARGET/backup_encryption_key" "$(random_b64)"
fi
if [[ "$ROLE" == db || "$ROLE" == all ]]; then
  create_if_missing "$TARGET/db_password" "$(random_b64)"
  create_if_missing "$TARGET/db_backup_password" "$(random_b64)"
  create_if_missing "$TARGET/mysql_root_password" "$(random_b64)"
fi
cat <<'MSG'
Topología separada:
- DB genera db_password, db_backup_password y mysql_root_password.
- APP genera sus llaves locales y recibe por canal SSH seguro db_password, db_backup_password y ca.pem.
- mysql_root_password nunca debe salir del servidor DB.
MSG
