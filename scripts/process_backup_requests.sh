#!/usr/bin/env bash
# Procesa solicitudes manuales generadas por la interfaz administrativa.
set -Eeuo pipefail
umask 077
BACKUP_PATH="${BACKUP_PATH:-/backups}"
REQUEST_DIR="$BACKUP_PATH/requests"
mkdir -p "$REQUEST_DIR"
shopt -s nullglob
for request in "$REQUEST_DIR"/*.request; do
  result="${request}.result"
  if /opt/sivi/backup_dokploy.sh --type manual >"$result" 2>&1; then
    rm -f "$request"
  else
    mv "$request" "${request}.failed" || true
  fi
done
