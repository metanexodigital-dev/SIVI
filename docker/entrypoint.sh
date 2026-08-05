#!/bin/sh
# DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
# Archivo: docker/entrypoint.sh
# Propósito: Prepara el almacenamiento e inicia SIVI sin ejecutar migraciones.
set -eu

cd /var/www/html

mkdir -p storage/uploads storage/logs storage/import-previews storage/reports storage/backups
if [ "$(id -u)" = "0" ]; then
  chown -R www-data:www-data storage
  mkdir -p /var/backups/sivi
  chown -R www-data:www-data /var/backups/sivi
fi

echo "SIVI: esquema administrado por la inicialización de MySQL."
echo "SIVI: migraciones automáticas desactivadas permanentemente en esta compilación."

if [ "${SIVI_PROCESS_ROLE:-web}" = "notifications" ]; then
  echo "SIVI: iniciando trabajador de notificaciones Microsoft 365..."
  exec php scripts/notification_worker.php
fi

echo "SIVI: registrando la versión desplegada en la base de datos..."
attempt=1
while [ "$attempt" -le 30 ]; do
  if php scripts/register_current_release.php; then
    echo "SIVI: versión registrada correctamente."
    break
  fi
  if [ "$attempt" -eq 30 ]; then
    echo "SIVI: no fue posible registrar la versión desplegada." >&2
    exit 1
  fi
  echo "SIVI: base aún no disponible para registrar versión; intento $attempt/30."
  attempt=$((attempt + 1))
  sleep 2
done

exec apache2-foreground
