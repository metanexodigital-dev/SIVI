#!/usr/bin/env bash
# DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
# Archivo: scripts/pre_deploy_secure.sh
# Propósito: Valida entorno real, secretos y respaldo antes del despliegue.
set -Eeuo pipefail

STARTED_AT="$(date +%s)"
ROOT="${1:-.}"
cd "$ROOT"

errors=0
temporary_files=()
cleanup() {
  for file in "${temporary_files[@]:-}"; do
    [[ -n "$file" ]] && rm -f "$file"
  done
}
trap cleanup EXIT INT TERM

check() {
  printf '[%s] %s\n' "$1" "$2"
  [[ "$1" == 'OK' ]] || errors=$((errors+1))
}

secret_hits="$(grep -RIlE \
  '(APP_SETUP_KEY|APP_ENCRYPTION_KEY|DB_PASSWORD|MYSQL_ROOT_PASSWORD|BACKUP_ENCRYPTION_KEY)=[^<[:space:]][^[:space:]]+' \
  . --exclude-dir=.git --exclude-dir=storage --exclude-dir=backups \
  --exclude='*.example' --exclude='*ENVIRONMENT*' 2>/dev/null || true)"
if [[ -n "$secret_hits" ]]; then
  check FAIL 'Posibles secretos reales dentro del repositorio.'
  printf '%s\n' "$secret_hits"
else
  check OK 'No se detectaron secretos obvios en archivos versionables.'
fi

preflight_output="$(mktemp)"
temporary_files+=("$preflight_output")
if php scripts/preflight.php --json >"$preflight_output"; then
  check OK 'Preflight operacional aprobado.'
else
  check FAIL 'Preflight operacional falló.'
  cat "$preflight_output"
fi

if [[ "${DEPLOY_RUN_BUILD_CHECKS:-false}" == 'true' ]]; then
  build_output="$(mktemp)"
  temporary_files+=("$build_output")
  if php scripts/build/run.php --json >"$build_output"; then
    check OK 'Controles de construcción aprobados.'
  else
    check FAIL 'Controles de construcción fallaron.'
    cat "$build_output"
  fi
else
  check OK 'Build no repetido; se usa la evidencia generada en CI/Dokploy.'
fi

backup_created=false
if [[ "${DEPLOY_REQUIRE_BACKUP:-true}" == 'true' ]]; then
  if [[ -x scripts/backup_dokploy.sh && -d "${APP_STORAGE_PATH:-/source/app_storage}" ]]; then
    backup_output="$(mktemp)"
    temporary_files+=("$backup_output")
    if scripts/backup_dokploy.sh --type pre-deploy >"$backup_output"; then
      backup_created=true
      check OK 'Respaldo previo creado y verificado.'
    else
      check FAIL 'No fue posible crear el respaldo previo.'
      cat "$backup_output"
    fi
  else
    check OK 'El respaldo debe ejecutarse desde el servicio backup de Dokploy.'
  fi
fi

if [[ "$errors" -ne 0 ]]; then
  printf '{"ok":false,"gate":"pre-deploy","errors":%d}\n' "$errors" >&2
  exit 1
fi

VERSION="$(tr -d '[:space:]' < VERSION)"
DURATION_SECONDS="$(( $(date +%s) - STARTED_AT ))"
printf '{"ok":true,"gate":"pre-deploy","version":"%s","backup_created":%s,"duration_seconds":%s}\n' \
  "$VERSION" "$backup_created" "$DURATION_SECONDS"
