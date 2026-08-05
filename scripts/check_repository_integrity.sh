#!/usr/bin/env sh
# DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
# Archivo: scripts/check_repository_integrity.sh
# Propósito: Verifica automáticamente que la funcionalidad «repository integrity» esté presente y sea coherente antes o después del despliegue.
set -eu
ROOT_DIR="${1:-$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)}"
cd "$ROOT_DIR"

say() { printf '%s\n' "$1"; }
fail() { printf 'ERROR: %s\n' "$1" >&2; exit 1; }

say '[1/8] Verificando conflictos de Git'
sh scripts/check_git_conflicts.sh . >/dev/null

say '[2/8] Validando sintaxis PHP'
find . -type f -name '*.php' \
  ! -path './storage/*' ! -path './.git/*' -print0 \
  | xargs -0 -n1 php -l >/dev/null

say '[3/8] Validando JavaScript'
if command -v node >/dev/null 2>&1; then
  find public/assets -type f -name '*.js' -print0 | xargs -0 -n1 node --check >/dev/null
else
  say 'AVISO: node no está disponible; JavaScript se validará en CI.'
fi

say '[4/8] Validando JSON'
php -r '
$root=getcwd();$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
foreach($it as $file){if(!$file->isFile()||strtolower($file->getExtension())!=="json")continue;$p=$file->getPathname();if(str_contains($p,DIRECTORY_SEPARATOR."storage".DIRECTORY_SEPARATOR))continue;json_decode((string)file_get_contents($p),true);if(json_last_error()!==JSON_ERROR_NONE){fwrite(STDERR,"JSON inválido: $p: ".json_last_error_msg().PHP_EOL);exit(2);}}
'

say '[5/8] Validando scripts shell'
find scripts docker -type f -name '*.sh' -print0 | xargs -0 -n1 bash -n

say '[6/8] Validando versión y archivos requeridos'
php scripts/check_release_files.php >/dev/null
php scripts/check_required_files.php >/dev/null
php scripts/check_system_stability.php >/dev/null
php scripts/check_soc_hardening_1_0_0_0.php >/dev/null

say '[7/8] Validando Docker Compose'
if command -v docker >/dev/null 2>&1; then
  docker compose -f docker-compose.yml config --no-interpolate >/dev/null
  docker compose -f docker-compose-db.yml config --no-interpolate >/dev/null
else
  say 'AVISO: Docker no está disponible; Compose se validará en CI/Dokploy.'
fi

say '[8/8] Verificando archivos sensibles'
if find . -type f \( -name '.env' -o -name '*.pem' -o -name '*.key' -o -name '*.p12' -o -name '*.pfx' \) ! -path './storage/*' ! -path './.git/*' | grep -q .; then
  find . -type f \( -name '.env' -o -name '*.pem' -o -name '*.key' -o -name '*.p12' -o -name '*.pfx' \) ! -path './storage/*' ! -path './.git/*' >&2
  fail 'Se encontraron archivos sensibles dentro del repositorio.'
fi

say '{"ok":true,"message":"Repositorio listo para construir y desplegar."}'
