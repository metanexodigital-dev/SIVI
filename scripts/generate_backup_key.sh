#!/usr/bin/env sh
# DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
# Archivo: scripts/generate_backup_key.sh
# Propósito: Ejecuta la tarea técnica «generate backup key» para operación, validación o mantenimiento de SIVI.
set -eu
umask 077
command -v openssl >/dev/null 2>&1 || { echo 'OpenSSL no está disponible.' >&2; exit 1; }
openssl rand -base64 48 | tr -d '\n'
printf '\n'
