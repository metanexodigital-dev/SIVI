#!/usr/bin/env bash
# DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
# Archivo: scripts/post_deploy_check.sh
# Propósito: Comprueba health y readiness con espera progresiva y métricas.
set -Eeuo pipefail

BASE_URL="${APP_URL:-}"
[[ -n "$BASE_URL" ]] || {
  echo '{"ok":false,"message":"Falta APP_URL."}' >&2
  exit 1
}
BASE_URL="${BASE_URL%/}"

HEALTH_PATH="${HEALTHCHECK_PATH:-/health.php}"
READY_PATH="${READINESS_PATH:-/ready.php}"
ATTEMPTS="${POST_DEPLOY_ATTEMPTS:-8}"
INSECURE_TLS="${POST_DEPLOY_INSECURE_TLS:-false}"
STARTED_AT="$(date +%s)"

curl_tls_args=()
if [[ "$INSECURE_TLS" == "true" ]]; then
  curl_tls_args=(-k)
fi

PROBE_MS=0
probe() {
  local path="$1" expected_status="$2" body metadata code seconds status
  body="$(mktemp)"
  metadata="$(curl "${curl_tls_args[@]}" -sS \
    --connect-timeout 5 --max-time 15 \
    -o "$body" -w '%{http_code} %{time_total}' \
    "$BASE_URL$path" || true)"
  read -r code seconds <<<"$metadata"
  PROBE_MS="$(awk -v value="${seconds:-0}" 'BEGIN {printf "%.0f", value*1000}')"

  if [[ "$code" != "200" ]]; then
    rm -f "$body"
    return 1
  fi

  status="$(php -r '
    $data=json_decode((string)file_get_contents($argv[1]),true);
    echo is_array($data)?(string)($data["status"]??""):"";
  ' "$body")"
  rm -f "$body"
  [[ "$status" == "$expected_status" ]]
}

delays=(2 3 5 8)
last_health_ms=0
last_ready_ms=0

for ((attempt=1; attempt<=ATTEMPTS; attempt++)); do
  health_ok=false
  ready_ok=false

  if probe "$HEALTH_PATH" "ok"; then
    health_ok=true
    last_health_ms="$PROBE_MS"
  fi

  if probe "$READY_PATH" "ready"; then
    ready_ok=true
    last_ready_ms="$PROBE_MS"
  fi

  if [[ "$health_ok" == "true" && "$ready_ok" == "true" ]]; then
    duration="$(( $(date +%s) - STARTED_AT ))"
    printf '{"ok":true,"health":true,"ready":true,"health_ms":%s,"ready_ms":%s,"attempt":%d,"duration_seconds":%s,"version":"%s"}\n' \
      "$last_health_ms" "$last_ready_ms" "$attempt" "$duration" "${APP_VERSION:-unknown}"
    exit 0
  fi

  delay_index=$((attempt - 1))
  if (( delay_index >= ${#delays[@]} )); then
    delay_index=$((${#delays[@]} - 1))
  fi
  sleep "${delays[$delay_index]}"
done

duration="$(( $(date +%s) - STARTED_AT ))"
printf '{"ok":false,"health_or_ready_failed":true,"rollback_recommended":true,"attempts":%s,"duration_seconds":%s}\n' \
  "$ATTEMPTS" "$duration" >&2
exit 1
