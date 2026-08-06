# SIVI - Respaldos compatibles con MySQL 8.4.
FROM mysql:8.4.10
USER root
RUN microdnf install -y bash tar gzip openssl tzdata findutils util-linux cronie \
    && microdnf clean all
WORKDIR /opt/sivi
COPY scripts/backup_dokploy.sh /opt/sivi/backup_dokploy.sh
COPY scripts/verify_dokploy_backup.sh /opt/sivi/verify_dokploy_backup.sh
COPY scripts/restore_dokploy_backup.sh /opt/sivi/restore_dokploy_backup.sh
COPY scripts/process_backup_requests.sh /opt/sivi/process_backup_requests.sh
COPY docker/backup/entrypoint.sh /entrypoint.sh
RUN chmod 750 /entrypoint.sh /opt/sivi/*.sh
ENTRYPOINT ["/entrypoint.sh"]
CMD ["scheduler"]
