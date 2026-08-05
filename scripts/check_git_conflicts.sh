#!/usr/bin/env sh
# DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
# Archivo: scripts/check_git_conflicts.sh
# Propósito: Verifica automáticamente que la funcionalidad «git conflicts» esté presente y sea coherente antes o después del despliegue.
set -eu
ROOT_DIR="${1:-.}"

# Ignora los metadatos de Git y los directorios binarios o de ejecución. Cualquier resultado bloquea el despliegue.
MATCHES=$(grep -RInE '^(<<<<<<< .*|=======|>>>>>>> .*)$' "$ROOT_DIR" \
  --exclude-dir=.git \
  --exclude-dir=storage \
  --exclude='*.zip' \
  --exclude='*.xlsx' \
  --exclude='*.xls' \
  --exclude='*.png' \
  --exclude='*.jpg' \
  --exclude='*.jpeg' \
  --exclude='*.gif' \
  --exclude='*.webp' \
  --exclude='*.pdf' 2>/dev/null || true)

if [ -n "$MATCHES" ]; then
  echo "ERROR: se encontraron marcadores de conflicto de Git:" >&2
  echo "$MATCHES" >&2
  exit 1
fi

echo '{"ok":true,"message":"No se encontraron marcadores de conflicto de Git."}'
