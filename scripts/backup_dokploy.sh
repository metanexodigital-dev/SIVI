#!/usr/bin/env bash
# DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
# Archivo: scripts/backup_dokploy.sh
# Propósito: Genera respaldos cifrados de MySQL 8.4 y app_storage.
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

DB_PASSWORD="$(read_secret DB_PASSWORD)"
BACKUP_ENCRYPTION_KEY="$(read_secret BACKUP_ENCRYPTION_KEY)"

BACKUP_TYPE="manual"
while [[ $# -gt 0 ]]; do
  case "$1" in
    --type) BACKUP_TYPE="${2:-manual}"; shift 2 ;;
    *) echo "Argumento no reconocido: $1" >&2; exit 2 ;;
  esac
done

[[ "${BACKUP_ENABLED:-true}" == "true" ]] || {
  echo '{"ok":false,"message":"Los respaldos están deshabilitados."}' >&2
  exit 1
}

for variable in DB_HOST DB_DATABASE DB_USERNAME; do
  [[ -n "${!variable:-}" ]] || {
    echo "{\"ok\":false,\"message\":\"Falta ${variable}.\"}" >&2
    exit 1
  }
done
[[ -n "$DB_PASSWORD" ]] || {
  echo '{"ok":false,"message":"Falta DB_PASSWORD/DB_PASSWORD_FILE."}' >&2
  exit 1
}

DB_PORT="${DB_PORT:-3306}"
DB_TLS_MODE="${DB_TLS_MODE:-verify_ca}"
DB_TLS_CA="${DB_TLS_CA:-}"
APP_STORAGE_PATH="${APP_STORAGE_PATH:-/source/app_storage}"
BACKUP_PATH="${BACKUP_PATH:-/backups}"
BACKUP_RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-30}"
BACKUP_ENCRYPTION_ENABLED="${BACKUP_ENCRYPTION_ENABLED:-true}"
BACKUP_PBKDF2_ITERATIONS="${BACKUP_PBKDF2_ITERATIONS:-600000}"
APP_VERSION="${APP_VERSION:-unknown}"
APP_BUILD_ID="${APP_BUILD_ID:-unknown}"
APP_GIT_COMMIT="${APP_GIT_COMMIT:-unknown}"

[[ -d "$APP_STORAGE_PATH" ]] || {
  echo '{"ok":false,"message":"No se encontró app_storage."}' >&2
  exit 1
}
if [[ "$BACKUP_ENCRYPTION_ENABLED" == "true" && -z "$BACKUP_ENCRYPTION_KEY" ]]; then
  echo '{"ok":false,"message":"Falta la llave de cifrado del respaldo."}' >&2
  exit 1
fi

for command in tar gzip sha256sum openssl; do
  command -v "$command" >/dev/null 2>&1 || {
    echo "Falta el comando $command" >&2
    exit 1
  }
done

DUMP_COMMAND="mysqldump"
command -v "$DUMP_COMMAND" >/dev/null 2>&1 || {
  echo 'No se encontró mysqldump de MySQL 8.4.' >&2
  exit 1
}

mkdir -p "$BACKUP_PATH"
exec 9>"$BACKUP_PATH/.backup.lock"
if command -v flock >/dev/null 2>&1; then
  flock -n 9 || {
    echo '{"ok":false,"message":"Ya existe un respaldo en ejecución."}' >&2
    exit 1
  }
fi

if command -v pigz >/dev/null 2>&1; then
  COMPRESSOR=(pigz -6)
  COMPRESSOR_NAME="pigz-6"
else
  COMPRESSOR=(gzip -6)
  COMPRESSOR_NAME="gzip-6"
fi
compress_stream() { "${COMPRESSOR[@]}"; }

STARTED_AT="$(date +%s)"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT INT TERM

TIMESTAMP="$(TZ="${BACKUP_TIMEZONE:-America/Bogota}" date +%Y%m%d-%H%M%S)"
BACKUP_ID="SIVI-${APP_VERSION}-${TIMESTAMP}-${BACKUP_TYPE}"
DB_ARCHIVE="$WORK_DIR/database.sql.gz"
STORAGE_ARCHIVE="$WORK_DIR/app_storage.tar.gz"
PLAIN_PACKAGE="$WORK_DIR/${BACKUP_ID}.tar.gz"
MANIFEST="$WORK_DIR/manifest.json"

escape_cnf() {
  printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'
}

CLIENT_CNF="$WORK_DIR/client.cnf"
{
  echo '[client]'
  printf 'host="%s"\n' "$(escape_cnf "$DB_HOST")"
  printf 'port="%s"\n' "$(escape_cnf "$DB_PORT")"
  printf 'user="%s"\n' "$(escape_cnf "$DB_USERNAME")"
  printf 'password="%s"\n' "$(escape_cnf "$DB_PASSWORD")"
  echo 'default-character-set=utf8mb4'
  if [[ "$DB_TLS_MODE" != "disabled" ]]; then
    echo 'ssl-mode=REQUIRED'
  fi
  if [[ "$DB_TLS_MODE" == "verify_ca" ]]; then
    [[ -n "$DB_TLS_CA" && -r "$DB_TLS_CA" ]] || {
      echo 'DB_TLS_CA es obligatorio para el respaldo.' >&2
      exit 1
    }
    printf 'ssl-ca="%s"\n' "$(escape_cnf "$DB_TLS_CA")"
    echo 'ssl-mode=VERIFY_IDENTITY'
  fi
} > "$CLIENT_CNF"
chmod 600 "$CLIENT_CNF"

"$DUMP_COMMAND" --defaults-extra-file="$CLIENT_CNF" \
  --single-transaction --quick \
  --skip-lock-tables --no-tablespaces --hex-blob --skip-comments \
  --default-character-set=utf8mb4 "$DB_DATABASE" \
  | compress_stream > "$DB_ARCHIVE"
[[ -s "$DB_ARCHIVE" ]] || {
  echo 'El respaldo de base de datos quedó vacío.' >&2
  exit 1
}

tar -C "$APP_STORAGE_PATH" \
  --exclude='./import-previews/*' \
  --exclude='./logs/*.tmp' \
  --exclude='./cache/*' \
  --exclude='./*.tmp' \
  -cf - . | compress_stream > "$STORAGE_ARCHIVE"
[[ -s "$STORAGE_ARCHIVE" ]] || {
  echo 'El respaldo de app_storage quedó vacío.' >&2
  exit 1
}

(
  cd "$WORK_DIR"
  sha256sum database.sql.gz app_storage.tar.gz > COMPONENTS.sha256
)

DB_SIZE="$(stat -c '%s' "$DB_ARCHIVE")"
STORAGE_SIZE="$(stat -c '%s' "$STORAGE_ARCHIVE")"
DB_SHA="$(sha256sum "$DB_ARCHIVE" | awk '{print $1}')"
STORAGE_SHA="$(sha256sum "$STORAGE_ARCHIVE" | awk '{print $1}')"

cat > "$MANIFEST" <<JSON
{
  "application": "SIVI",
  "backup_id": "${BACKUP_ID}",
  "backup_type": "${BACKUP_TYPE}",
  "created_at": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
  "app_version": "${APP_VERSION}",
  "build_id": "${APP_BUILD_ID}",
  "git_commit": "${APP_GIT_COMMIT}",
  "database": "${DB_DATABASE}",
  "encrypted": ${BACKUP_ENCRYPTION_ENABLED},
  "tls_mode": "${DB_TLS_MODE}",
  "components": {
    "database.sql.gz": {"size": ${DB_SIZE}, "sha256": "${DB_SHA}"},
    "app_storage.tar.gz": {"size": ${STORAGE_SIZE}, "sha256": "${STORAGE_SHA}"}
  }
}
JSON

(
  cd "$WORK_DIR"
  tar -cf - manifest.json COMPONENTS.sha256 database.sql.gz app_storage.tar.gz \
    | compress_stream > "$PLAIN_PACKAGE"
)

if [[ "$BACKUP_ENCRYPTION_ENABLED" == "true" ]]; then
  FINAL_PACKAGE="$BACKUP_PATH/${BACKUP_ID}.tar.gz.enc"
  if [[ -n "${BACKUP_ENCRYPTION_KEY_FILE:-}" && -r "${BACKUP_ENCRYPTION_KEY_FILE}" ]]; then
    openssl enc -aes-256-cbc -salt -pbkdf2 \
      -iter "$BACKUP_PBKDF2_ITERATIONS" \
      -in "$PLAIN_PACKAGE" -out "$FINAL_PACKAGE" \
      -pass file:"$BACKUP_ENCRYPTION_KEY_FILE"
  else
    export BACKUP_ENCRYPTION_KEY
    openssl enc -aes-256-cbc -salt -pbkdf2 \
      -iter "$BACKUP_PBKDF2_ITERATIONS" \
      -in "$PLAIN_PACKAGE" -out "$FINAL_PACKAGE" \
      -pass env:BACKUP_ENCRYPTION_KEY
  fi
else
  echo 'Los respaldos sin cifrar están prohibidos en producción.' >&2
  exit 1
fi

sha256sum "$FINAL_PACKAGE" > "${FINAL_PACKAGE}.sha256"

VERIFY_SCRIPT="$(dirname "$0")/verify_dokploy_backup.sh"
"$VERIFY_SCRIPT" "$FINAL_PACKAGE" >/dev/null

printf '%s\n' "$FINAL_PACKAGE" > "$BACKUP_PATH/latest.txt"
find "$BACKUP_PATH" -maxdepth 1 -type f \
  \( -name 'SIVI-*.tar.gz.enc' -o -name 'SIVI-*.tar.gz.enc.sha256' \) \
  -mtime "+${BACKUP_RETENTION_DAYS}" -delete || true

PACKAGE_SIZE="$(stat -c '%s' "$FINAL_PACKAGE")"
PACKAGE_SHA="$(sha256sum "$FINAL_PACKAGE" | awk '{print $1}')"
DURATION_SECONDS="$(( $(date +%s) - STARTED_AT ))"
printf '{"ok":true,"backup_id":"%s","type":"%s","file":"%s","size":%s,"sha256":"%s","encrypted":true,"compression":"%s","duration_seconds":%s}\n' \
  "$BACKUP_ID" "$BACKUP_TYPE" "$FINAL_PACKAGE" "$PACKAGE_SIZE" "$PACKAGE_SHA" "$COMPRESSOR_NAME" "$DURATION_SECONDS"
