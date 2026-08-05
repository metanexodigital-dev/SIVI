#!/usr/bin/env bash
# DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
# Archivo: scripts/restore_dokploy_backup.sh
# Propósito: Restaura un respaldo previamente verificado mediante controles de seguridad.
set -Eeuo pipefail
umask 077

read_secret() {
  local name="$1"
  local file_var="${name}_FILE"
  local value="${!name:-}"
  local file_path="${!file_var:-}"
  if [[ -z "$value" && -n "$file_path" && -r "$file_path" ]]; then
    value="$(tr -d '\r\n' < "$file_path")"
  fi
  printf '%s' "$value"
}
RESTORE_DB_PASSWORD="$(read_secret RESTORE_DB_PASSWORD)"
BACKUP_ENCRYPTION_KEY="$(read_secret BACKUP_ENCRYPTION_KEY)"
export RESTORE_DB_PASSWORD BACKUP_ENCRYPTION_KEY

PACKAGE="${1:-}"
[[ "${RESTORE_CONFIRM:-}" == "SIVI-RESTORE" ]] || {
  echo 'Restauración cancelada. Defina RESTORE_CONFIRM=SIVI-RESTORE.' >&2
  exit 1
}
[[ -n "$PACKAGE" && -f "$PACKAGE" ]] || { echo 'Indique un paquete de respaldo válido.' >&2; exit 1; }

required=(DB_HOST DB_DATABASE RESTORE_DB_USERNAME RESTORE_DB_PASSWORD)
for variable in "${required[@]}"; do
  [[ -n "${!variable:-}" ]] || { echo "Falta ${variable}." >&2; exit 1; }
done

APP_STORAGE_PATH="${APP_STORAGE_PATH:-/source/app_storage}"
DB_PORT="${DB_PORT:-3306}"
if [[ "${DB_TLS_MODE:-verify_ca}" == "verify_ca" ]]; then
  [[ -n "${DB_TLS_CA:-}" && -r "${DB_TLS_CA}" ]] || { echo 'Restauración cancelada: falta DB_TLS_CA legible.' >&2; exit 1; }
fi
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
"$SCRIPT_DIR/verify_dokploy_backup.sh" "$PACKAGE" >/dev/null
"$SCRIPT_DIR/backup_dokploy.sh" --type pre-restore >/dev/null

WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT INT TERM
PLAIN="$WORK_DIR/package.tar.gz"
case "$PACKAGE" in
  *.enc)
    openssl enc -d -aes-256-cbc -pbkdf2 -iter "${BACKUP_PBKDF2_ITERATIONS:-600000}" \
      -in "$PACKAGE" -out "$PLAIN" -pass env:BACKUP_ENCRYPTION_KEY
    ;;
  *) cp "$PACKAGE" "$PLAIN" ;;
esac

tar -xzf "$PLAIN" -C "$WORK_DIR"
(
  cd "$WORK_DIR"
  sha256sum -c COMPONENTS.sha256 >/dev/null
)

CLIENT=mysql
command -v "$CLIENT" >/dev/null 2>&1 || { echo 'No se encontró el cliente MySQL 8.4.' >&2; exit 1; }

escape_cnf() { printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'; }
CLIENT_CNF="$WORK_DIR/client.cnf"
{
  echo '[client]'
  printf 'host="%s"\n' "$(escape_cnf "$DB_HOST")"
  printf 'port="%s"\n' "$(escape_cnf "$DB_PORT")"
  printf 'user="%s"\n' "$(escape_cnf "$RESTORE_DB_USERNAME")"
  printf 'password="%s"\n' "$(escape_cnf "$RESTORE_DB_PASSWORD")"
  if [[ "${DB_TLS_MODE:-verify_ca}" != "disabled" ]]; then
    echo 'ssl-mode=REQUIRED'
    if [[ -n "${DB_TLS_CA:-}" ]]; then
      printf 'ssl-ca="%s"\n' "$(escape_cnf "$DB_TLS_CA")"
      echo 'ssl-mode=VERIFY_IDENTITY'
    fi
  fi
} > "$CLIENT_CNF"
chmod 600 "$CLIENT_CNF"

gzip -dc "$WORK_DIR/database.sql.gz" | "$CLIENT" --defaults-extra-file="$CLIENT_CNF" "$DB_DATABASE"
mkdir -p "$APP_STORAGE_PATH"
find "$APP_STORAGE_PATH" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
tar -xzf "$WORK_DIR/app_storage.tar.gz" -C "$APP_STORAGE_PATH"

printf '{"ok":true,"restored":"%s","warning":"Reinicie los servicios app y notifications y ejecute las verificaciones posteriores."}\n' "$PACKAGE"
