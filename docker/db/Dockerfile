# SIVI 1.0.0.0 - Motor de base de datos independiente
# MySQL 8.4 LTS fijado para despliegues reproducibles.
FROM mysql:8.4.10

COPY docker/db/mysql-entrypoint-wrapper.sh /usr/local/bin/sivi-mysql-entrypoint.sh
COPY database/schema.mysql84.sql /docker-entrypoint-initdb.d/01-sivi-schema.sql
COPY docker/db/98-sivi-least-privilege.sh /docker-entrypoint-initdb.d/98-sivi-least-privilege.sh
COPY docker/db/99-sivi-register-release.sh /docker-entrypoint-initdb.d/99-sivi-register-release.sh

RUN chmod 0555 /usr/local/bin/sivi-mysql-entrypoint.sh \
    /docker-entrypoint-initdb.d/98-sivi-least-privilege.sh \
    /docker-entrypoint-initdb.d/99-sivi-register-release.sh

ENTRYPOINT ["/usr/local/bin/sivi-mysql-entrypoint.sh"]
CMD ["mysqld"]
