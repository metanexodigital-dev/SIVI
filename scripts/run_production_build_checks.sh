#!/usr/bin/env sh
# DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
# Archivo: scripts/run_production_build_checks.sh
# Propósito: Punto de entrada compatible para el motor central de controles.
set -eu

ROOT="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
cd "$ROOT"

exec php scripts/build/run.php "$@"
