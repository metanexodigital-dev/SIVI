#!/usr/bin/env bash
# Ejecutar como root en vps3486536 después de copiar db_password, db_backup_password y ca.pem desde DB.
set -Eeuo pipefail
umask 077
SOURCE="${1:-/root/sivi-db-integration}"
mkdir -p /opt/sivi/secrets /opt/sivi/pki
chmod 700 /opt/sivi/secrets
if [[ ! -s /opt/sivi/secrets/app_setup_key ]]; then openssl rand -hex 32 > /opt/sivi/secrets/app_setup_key; fi
if [[ ! -s /opt/sivi/secrets/app_encryption_key ]]; then openssl rand -base64 48 | tr -d '\r\n' > /opt/sivi/secrets/app_encryption_key; echo >> /opt/sivi/secrets/app_encryption_key; fi
if [[ ! -s /opt/sivi/secrets/backup_encryption_key ]]; then openssl rand -base64 48 | tr -d '\r\n' > /opt/sivi/secrets/backup_encryption_key; echo >> /opt/sivi/secrets/backup_encryption_key; fi
chmod 600 /opt/sivi/secrets/app_setup_key /opt/sivi/secrets/app_encryption_key /opt/sivi/secrets/backup_encryption_key
[[ -s "$SOURCE/db_password" && -s "$SOURCE/db_backup_password" && -s "$SOURCE/ca.pem" ]] || { echo "Faltan archivos de integración en $SOURCE" >&2; exit 1; }
install -m 600 "$SOURCE/db_password" /opt/sivi/secrets/db_password
install -m 600 "$SOURCE/db_backup_password" /opt/sivi/secrets/db_backup_password
install -m 644 "$SOURCE/ca.pem" /opt/sivi/pki/ca.pem
echo 'Servidor APP preparado para conectar con MySQL remoto.'
