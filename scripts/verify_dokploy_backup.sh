#!/usr/bin/env bash
# DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
# Archivo: scripts/verify_dokploy_backup.sh
# Propósito: Verifica integridad, cifrado y contenido de un respaldo.
set -Eeuo pipefail
umask 077

PACKAGE="${1:-}"
if [[ -z "$PACKAGE" && -f "${BACKUP_PATH:-/backups}/latest.txt" ]]; then
  PACKAGE="$(cat "${BACKUP_PATH:-/backups}/latest.txt")"
fi
[[ -n "$PACKAGE" && -f "$PACKAGE" ]] || {
  echo 'No se encontró el paquete de respaldo.' >&2
  exit 1
}
[[ "$PACKAGE" == *.enc ]] || {
  echo 'El respaldo no está cifrado.' >&2
  exit 1
}
[[ -f "${PACKAGE}.sha256" ]] || {
  echo 'No se encontró la suma SHA-256 externa.' >&2
  exit 1
}
sha256sum -c "${PACKAGE}.sha256" >/dev/null

WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT INT TERM
PLAIN="$WORK_DIR/package.tar.gz"

if [[ -n "${BACKUP_ENCRYPTION_KEY_FILE:-}" && -r "${BACKUP_ENCRYPTION_KEY_FILE}" ]]; then
  openssl enc -d -aes-256-cbc -pbkdf2 \
    -iter "${BACKUP_PBKDF2_ITERATIONS:-600000}" \
    -in "$PACKAGE" -out "$PLAIN" \
    -pass file:"$BACKUP_ENCRYPTION_KEY_FILE"
else
  [[ -n "${BACKUP_ENCRYPTION_KEY:-}" ]] || {
    echo 'Falta la llave de cifrado.' >&2
    exit 1
  }
  openssl enc -d -aes-256-cbc -pbkdf2 \
    -iter "${BACKUP_PBKDF2_ITERATIONS:-600000}" \
    -in "$PACKAGE" -out "$PLAIN" \
    -pass env:BACKUP_ENCRYPTION_KEY
fi

tar -tzf "$PLAIN" >/dev/null
tar -xzf "$PLAIN" -C "$WORK_DIR"
(
  cd "$WORK_DIR"
  sha256sum -c COMPONENTS.sha256 >/dev/null
)
[[ -s "$WORK_DIR/manifest.json" ]] || {
  echo 'Manifest inválido.' >&2
  exit 1
}
printf '{"ok":true,"file":"%s","integrity":"verified","encrypted":true}\n' "$PACKAGE"
