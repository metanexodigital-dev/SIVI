#!/usr/bin/env bash
# SIVI - restricción TCP/3306 para servidor DB con Docker/iptables.
set -Eeuo pipefail
APP_IP="${APP_IP:-69.10.35.41}"
DB_PORT="${DB_PORT:-3306}"

if ! command -v iptables >/dev/null 2>&1; then
  echo "iptables no está disponible. Configure la regla equivalente en el firewall del proveedor." >&2
  exit 2
fi

if ! iptables -nL DOCKER-USER >/dev/null 2>&1; then
  echo "No existe DOCKER-USER. Configure TCP/${DB_PORT} en el firewall del proveedor: permitir ${APP_IP}/32 y denegar los demás." >&2
  exit 2
fi

iptables -C DOCKER-USER -p tcp -s "${APP_IP}/32" --dport "${DB_PORT}" -j ACCEPT 2>/dev/null || \
  iptables -I DOCKER-USER 1 -p tcp -s "${APP_IP}/32" --dport "${DB_PORT}" -j ACCEPT

iptables -C DOCKER-USER -p tcp --dport "${DB_PORT}" -j DROP 2>/dev/null || \
  iptables -A DOCKER-USER -p tcp --dport "${DB_PORT}" -j DROP

iptables -nL DOCKER-USER --line-numbers
