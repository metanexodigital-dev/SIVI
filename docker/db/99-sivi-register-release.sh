#!/usr/bin/env bash
# Registra huella del esquema y versión inicial en MySQL 8.4, sin migraciones.
set -Eeuo pipefail
umask 077

SCHEMA_FILE=/docker-entrypoint-initdb.d/01-sivi-schema.sql
DATABASE_NAME="${MYSQL_DATABASE:?MYSQL_DATABASE es obligatorio}"
ROOT_PASSWORD="${MYSQL_ROOT_PASSWORD:-}"
if [[ -z "$ROOT_PASSWORD" && -n "${MYSQL_ROOT_PASSWORD_FILE:-}" && -r "${MYSQL_ROOT_PASSWORD_FILE}" ]]; then
  ROOT_PASSWORD="$(tr -d '\r\n' < "${MYSQL_ROOT_PASSWORD_FILE}")"
fi
[[ -n "$ROOT_PASSWORD" ]] || { echo 'Falta contraseña root de MySQL.' >&2; exit 1; }

APP_VERSION_VALUE="${APP_VERSION:-1.0.0.0}"
APP_ENV_VALUE="${APP_ENV:-production}"
APP_BUILD_VALUE="${APP_BUILD_ID:-SIVI-1.0.0.0}"
APP_COMMIT_VALUE="${APP_GIT_COMMIT:-}"
RELEASE_COMMIT_VALUE="${APP_COMMIT_VALUE:-sin-commit}"
SCHEMA_CHECKSUM="$(sha256sum "$SCHEMA_FILE" | awk '{print $1}')"
RELEASE_KEY="$(printf '%s|%s|%s|%s' "$APP_VERSION_VALUE" "$APP_ENV_VALUE" "$APP_BUILD_VALUE" "$RELEASE_COMMIT_VALUE" | sha256sum | awk '{print $1}')"

sql_escape() { printf '%s' "$1" | sed "s/'/''/g"; }
VERSION_SQL="$(sql_escape "$APP_VERSION_VALUE")"
ENV_SQL="$(sql_escape "$APP_ENV_VALUE")"
BUILD_SQL="$(sql_escape "$APP_BUILD_VALUE")"
RELEASE_SQL="$(sql_escape "$RELEASE_KEY")"
CHECKSUM_SQL="$(sql_escape "$SCHEMA_CHECKSUM")"
if [[ -n "$APP_COMMIT_VALUE" ]]; then COMMIT_SQL="'$(sql_escape "$APP_COMMIT_VALUE")'"; else COMMIT_SQL=NULL; fi

CLIENT_CNF="$(mktemp)"
trap 'rm -f "$CLIENT_CNF"' EXIT INT TERM
printf '[client]\nuser=root\npassword=%s\nprotocol=socket\n' "$ROOT_PASSWORD" > "$CLIENT_CNF"

mysql --defaults-extra-file="$CLIENT_CNF" "$DATABASE_NAME" <<SQL
CREATE TABLE IF NOT EXISTS schema_migrations (
    migration_key VARCHAR(190) PRIMARY KEY,
    checksum CHAR(64) NOT NULL,
    statements_executed INT UNSIGNED NOT NULL DEFAULT 0,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (migration_key, checksum, statements_executed, applied_at)
VALUES ('schema.mysql84.sql', '${CHECKSUM_SQL}', 0, NOW())
ON DUPLICATE KEY UPDATE checksum=VALUES(checksum), statements_executed=VALUES(statements_executed), applied_at=NOW();

UPDATE app_release_history SET is_current=0 WHERE environment='${ENV_SQL}';
INSERT INTO app_release_history
(release_key, version, environment, build_id, git_commit, schema_checksum, is_current, release_notes, registered_by, installed_at, last_seen_at)
VALUES
('${RELEASE_SQL}', '${VERSION_SQL}', '${ENV_SQL}', '${BUILD_SQL}', ${COMMIT_SQL}, '${CHECKSUM_SQL}', 1,
 'Instalación inicial MySQL 8.4 en servidor DB independiente, sin migraciones', NULL, NOW(), NOW())
ON DUPLICATE KEY UPDATE is_current=1, last_seen_at=NOW(), schema_checksum=VALUES(schema_checksum);
SQL
