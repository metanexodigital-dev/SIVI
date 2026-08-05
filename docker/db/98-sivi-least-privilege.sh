#!/usr/bin/env bash
# SIVI - mínimo privilegio para MySQL 8.4.
set -Eeuo pipefail
umask 077

read_secret() {
  local variable="$1" file="$2" value="${!variable:-}"
  if [[ -z "$value" && -n "$file" && -r "$file" ]]; then value="$(tr -d '\r\n' < "$file")"; fi
  printf '%s' "$value"
}

DB_NAME="${MYSQL_DATABASE:?MYSQL_DATABASE es obligatorio}"
APP_USER="${MYSQL_USER:?MYSQL_USER es obligatorio}"
BACKUP_USER="${SIVI_BACKUP_USER:-sivi_backup}"
ROOT_PASSWORD="$(read_secret MYSQL_ROOT_PASSWORD "${MYSQL_ROOT_PASSWORD_FILE:-}")"
BACKUP_PASSWORD="$(read_secret SIVI_BACKUP_PASSWORD "${SIVI_BACKUP_PASSWORD_FILE:-}")"

[[ -n "$ROOT_PASSWORD" ]] || { echo 'Falta contraseña root de MySQL.' >&2; exit 1; }
[[ -n "$BACKUP_PASSWORD" ]] || { echo 'Falta contraseña del usuario de respaldo.' >&2; exit 1; }
[[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]] || { echo 'Nombre de base inválido.' >&2; exit 1; }
[[ "$APP_USER" =~ ^[A-Za-z0-9_]+$ ]] || { echo 'Usuario APP inválido.' >&2; exit 1; }
[[ "$BACKUP_USER" =~ ^[A-Za-z0-9_]+$ ]] || { echo 'Usuario backup inválido.' >&2; exit 1; }

sql_string() { printf '%s' "$1" | sed "s/'/''/g"; }
BACKUP_PASSWORD_SQL="$(sql_string "$BACKUP_PASSWORD")"

CLIENT_CNF="$(mktemp)"
trap 'rm -f "$CLIENT_CNF"' EXIT INT TERM
printf '[client]\nuser=root\npassword=%s\nprotocol=socket\n' "$ROOT_PASSWORD" > "$CLIENT_CNF"

mysql --defaults-extra-file="$CLIENT_CNF" <<SQL
REVOKE ALL PRIVILEGES, GRANT OPTION FROM '${APP_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${DB_NAME}\`.* TO '${APP_USER}'@'%';
ALTER USER '${APP_USER}'@'%' REQUIRE SSL;

CREATE USER IF NOT EXISTS '${BACKUP_USER}'@'%' IDENTIFIED BY '${BACKUP_PASSWORD_SQL}' REQUIRE SSL;
ALTER USER '${BACKUP_USER}'@'%' IDENTIFIED BY '${BACKUP_PASSWORD_SQL}' REQUIRE SSL;
REVOKE ALL PRIVILEGES, GRANT OPTION FROM '${BACKUP_USER}'@'%';
GRANT SELECT, SHOW VIEW, TRIGGER ON \`${DB_NAME}\`.* TO '${BACKUP_USER}'@'%';
FLUSH PRIVILEGES;
SQL
