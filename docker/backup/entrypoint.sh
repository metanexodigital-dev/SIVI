#!/usr/bin/env bash
# SIVI - scheduler de respaldos en imagen MySQL 8.4 / Oracle Linux.
set -Eeuo pipefail
umask 077
case "${1:-scheduler}" in
  scheduler)
    schedule="${BACKUP_CRON_SCHEDULE:-0 2 * * *}"
    if [[ ! "$schedule" =~ ^[^[:space:]]+[[:space:]]+[^[:space:]]+[[:space:]]+[^[:space:]]+[[:space:]]+[^[:space:]]+[[:space:]]+[^[:space:]]+$ ]]; then
      echo 'BACKUP_CRON_SCHEDULE debe contener cinco campos cron.' >&2; exit 1
    fi
    export TZ="${BACKUP_TIMEZONE:-America/Bogota}"
    env_file=/opt/sivi/runtime-env.sh
    : > "$env_file"; chmod 600 "$env_file"
    allowed=(DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD_FILE DB_CHARSET DB_TLS_MODE DB_TLS_CA APP_VERSION APP_BUILD_ID APP_GIT_COMMIT BACKUP_ENABLED BACKUP_TIMEZONE BACKUP_RETENTION_DAYS BACKUP_ENCRYPTION_ENABLED BACKUP_ENCRYPTION_KEY_FILE BACKUP_PBKDF2_ITERATIONS BACKUP_PATH APP_STORAGE_PATH)
    for name in "${allowed[@]}"; do printf 'export %s=%q\n' "$name" "${!name:-}" >> "$env_file"; done
    cat > /etc/cron.d/sivi-backup <<EOF
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
${schedule} root . ${env_file}; /opt/sivi/backup_dokploy.sh --type automatic >> /backups/backup.log 2>&1
* * * * * root . ${env_file}; /opt/sivi/process_backup_requests.sh >> /backups/backup-requests.log 2>&1
EOF
    chmod 0644 /etc/cron.d/sivi-backup
    exec crond -n
    ;;
  backup) shift; exec /opt/sivi/backup_dokploy.sh "$@" ;;
  verify) shift; exec /opt/sivi/verify_dokploy_backup.sh "$@" ;;
  restore) shift; exec /opt/sivi/restore_dokploy_backup.sh "$@" ;;
  *) exec "$@" ;;
esac
