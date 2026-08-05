#!/usr/bin/env bash
# Ejecutar como root en vps3536788. Genera secretos y PKI sin sobrescribir existentes.
set -Eeuo pipefail
umask 077
DB_DNS_SHORT="${DB_DNS_SHORT:-vps3536788}"
DB_DNS_FQDN="${DB_DNS_FQDN:-vps3536788.trouble-free.net}"
DB_IP="${DB_IP:-69.169.109.197}"
mkdir -p /opt/sivi/secrets /opt/sivi/pki /opt/sivi/app-integration
chmod 700 /opt/sivi/secrets /opt/sivi/app-integration
create_secret(){ local f="$1"; if [[ ! -s "$f" ]]; then openssl rand -base64 48 | tr -d '\r\n' > "$f"; echo >> "$f"; fi; chmod 600 "$f"; }
create_secret /opt/sivi/secrets/db_password
create_secret /opt/sivi/secrets/db_backup_password
create_secret /opt/sivi/secrets/mysql_root_password
cd /opt/sivi/pki
if [[ ! -s ca.key || ! -s ca.pem ]]; then
  openssl genrsa -out ca.key 4096
  openssl req -x509 -new -nodes -key ca.key -sha256 -days 3650 -subj '/C=CO/O=METANEXO/OU=SIVI/CN=SIVI-DB-CA' -out ca.pem
fi
cat > db-server.ext <<EOF
subjectAltName=DNS:${DB_DNS_SHORT},DNS:${DB_DNS_FQDN},IP:${DB_IP}
extendedKeyUsage=serverAuth
keyUsage=digitalSignature,keyEncipherment
EOF
if [[ ! -s db-server.key || ! -s db-server.crt ]]; then
  openssl genrsa -out db-server.key 4096
  openssl req -new -key db-server.key -subj "/C=CO/O=METANEXO/OU=SIVI/CN=${DB_DNS_FQDN}" -out db-server.csr
  openssl x509 -req -in db-server.csr -CA ca.pem -CAkey ca.key -CAcreateserial -out db-server.crt -days 825 -sha256 -extfile db-server.ext
fi
chmod 600 ca.key db-server.key /opt/sivi/secrets/*
chmod 644 ca.pem db-server.crt
openssl verify -CAfile ca.pem db-server.crt
install -m 600 /opt/sivi/secrets/db_password /opt/sivi/app-integration/db_password
install -m 600 /opt/sivi/secrets/db_backup_password /opt/sivi/app-integration/db_backup_password
install -m 644 /opt/sivi/pki/ca.pem /opt/sivi/app-integration/ca.pem
echo 'Preparación DB terminada. Copie /opt/sivi/app-integration al servidor APP mediante SCP y elimine la copia temporal después.'
