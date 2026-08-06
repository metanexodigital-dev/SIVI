# SIVI — Sistema Integrado de Verificación de Inventario

**Versión:** `Pre-1.0.0.4`
**Build:** `SIVI-Pre-1.0.0.4`
**Canal:** preproducción
**Motor de base de datos:** MySQL `8.4.10` LTS  
**Arquitectura:** aplicación y base de datos en servidores independientes  
**Migraciones automáticas:** deshabilitadas (`AUTO_MIGRATE=false`)

SIVI permite verificar y actualizar el inventario tecnológico de las sedes, gestionar campañas, novedades, equipos adicionales, traslados, correcciones, control de calidad, reportes, auditoría y respaldos.

## 1. Arquitectura vigente

SIVI se despliega en **dos servidores independientes**. La base de datos no se ejecuta dentro del Compose de la aplicación.

### Entorno actual de pruebas

| Función | Hostname | IP | Despliegue |
| --- | --- | --- | --- |
| Aplicación / Dokploy | `vps3486536` | `69.10.35.41` | `docker-compose.yml` |
| Base de datos MySQL | `vps3536788.trouble-free.net` | `69.169.109.197` | `docker-compose-db.yml` |

Dominio de pruebas:

```text
https://metanexo.digital
```

Flujo de comunicación:

```text
Internet / HTTPS
       |
       v
Servidor APP / Dokploy
vps3486536 - 69.10.35.41
       |
       | TLS 1.2/1.3 - TCP 3306
       v
Servidor DB
vps3536788.trouble-free.net - 69.169.109.197
MySQL 8.4.10
```

El puerto `3306` debe permitirse **únicamente desde el servidor APP**. Si existe red privada entre ambos servidores, debe preferirse frente a las IP públicas.

## 2. Componentes Docker

### Servidor APP — `docker-compose.yml`

El Compose de Aplicación ejecuta exclusivamente:

- `app`: PHP 8.4 + Apache + SIVI.
- `clamav`: análisis antimalware.
- `notifications`: procesamiento de notificaciones.
- `backup`: respaldos cifrados de aplicación y MySQL remoto.

**No contiene servicio `db`.**

### Servidor DB — `docker-compose-db.yml`

El Compose de Base de Datos ejecuta:

- `db`: MySQL 8.4.10 LTS.
- InnoDB y `utf8mb4`.
- TLS obligatorio mediante `require_secure_transport=ON`.
- Volumen persistente `db_data`.
- Inicialización limpia desde `database/schema.mysql84.sql`.
- Usuario `sivi_user` con privilegios mínimos CRUD.
- Usuario `sivi_backup` independiente y de solo lectura.

## 3. Estructura principal

```text
Dockerfile
docker-compose.yml
docker-compose-db.yml
VERSION
RELEASE.json
README.md
Actualizaciones.md
config/
database/
docker/
docs/
public/
scripts/
src/
storage/
```

La política documental oficial permite únicamente:

- `README.md`: documentación general.
- `Actualizaciones.md`: cambios, correcciones, mejoras e historial de versiones.

No se utiliza `CHANGELOG.md`.

## 4. Variables esenciales de Aplicación

Para el entorno actual de pruebas:

```env
APP_NAME=SIVI
APP_VERSION=Pre-1.0.0.1
APP_BUILD_ID=SIVI-Pre-1.0.0.1
APP_ENV=testing
APP_RELEASE_CHANNEL=testing
APP_GIT_COMMIT=COMMIT_REAL_DE_GITHUB
APP_URL=https://metanexo.digital
APP_TIMEZONE=America/Bogota

DEPLOYMENT_TOPOLOGY=split
APP_SERVER_HOSTNAME=vps3486536
DB_SERVER_HOSTNAME=vps3536788.trouble-free.net

DB_HOST=69.169.109.197
DB_PORT=3306
DB_DATABASE=sivi_pruebas
DB_USERNAME=sivi_user
DB_BACKUP_USERNAME=sivi_backup
DB_CHARSET=utf8mb4
DB_TLS_MODE=verify_ca
AUTO_MIGRATE=false
```

Secretos y CA se entregan mediante archivos locales del servidor:

```env
APP_SETUP_KEY_SECRET_FILE=/opt/sivi/secrets/app_setup_key
APP_ENCRYPTION_KEY_SECRET_FILE=/opt/sivi/secrets/app_encryption_key
DB_PASSWORD_SECRET_FILE=/opt/sivi/secrets/db_password
DB_BACKUP_PASSWORD_SECRET_FILE=/opt/sivi/secrets/db_backup_password
BACKUP_ENCRYPTION_KEY_SECRET_FILE=/opt/sivi/secrets/backup_encryption_key
DB_TLS_CA_HOST_PATH=/opt/sivi/pki/ca.pem
```

Las credenciales reales, `.env`, certificados privados y volúmenes de datos **no se almacenan en GitHub**.

## 5. Variables esenciales de Base de Datos

```env
APP_VERSION=Pre-1.0.0.1
APP_ENV=testing
APP_BUILD_ID=SIVI-Pre-1.0.0.1
APP_GIT_COMMIT=COMMIT_REAL_DE_GITHUB
APP_TIMEZONE=America/Bogota

DEPLOYMENT_TOPOLOGY=split
APP_SERVER_HOSTNAME=vps3486536
DB_SERVER_HOSTNAME=vps3536788.trouble-free.net

DB_CONTAINER_NAME=sivi-pruebas-db
DB_LISTEN_IP=69.169.109.197
DB_PORT=3306
DB_DATABASE=sivi_pruebas
DB_USERNAME=sivi_user
DB_BACKUP_USERNAME=sivi_backup
DB_MAX_CONNECTIONS=200
DB_INNODB_BUFFER_POOL_SIZE=3G
```

El certificado del servidor MySQL debe incluir en `Subject Alternative Name (SAN)` el hostname o IP utilizado en `DB_HOST`.

## 6. Orden de instalación

La instalación se realiza obligatoriamente en este orden:

1. Preparar el servidor DB.
2. Generar secretos y PKI del servidor DB.
3. Desplegar `docker-compose-db.yml`.
4. Confirmar `sivi-pruebas-db = healthy`.
5. Confirmar MySQL 8.4.10, esquema y TLS obligatorio.
6. Restringir TCP/3306 para permitir únicamente al servidor APP.
7. Transferir a APP solamente `db_password`, `db_backup_password` y `ca.pem`.
8. Preparar secretos locales de APP.
9. Probar conexión TCP y TLS APP -> DB.
10. Desplegar `docker-compose.yml` en Dokploy.
11. Configurar dominio y HTTPS.
12. Validar `health.php`, `ready.php`, Setup y checks técnicos.
13. Ejecutar pruebas funcionales y un respaldo verificado.

## 7. Instalación del servidor DB

En el servidor de Base de Datos:

```bash
git clone https://github.com/metanexodigital-dev/SIVI.git /opt/sivi-db
cd /opt/sivi-db

bash scripts/prepare_mysql84_db_server.sh
cp SIVI-DB-MYSQL84-ENVIRONMENT-PRUEBAS.txt .env
chmod 600 .env
```

Reemplace `APP_GIT_COMMIT` por el hash real:

```bash
git rev-parse HEAD
```

Valide y despliegue:

```bash
docker compose --env-file .env -f docker-compose-db.yml config
docker compose --env-file .env -f docker-compose-db.yml up -d --build
```

Compruebe:

```bash
docker ps
DB_CONTAINER_NAME=sivi-pruebas-db bash scripts/validate_mysql84_db_server.sh
```

No ejecutar:

```text
docker compose down -v
```

si se deben conservar datos.

## 8. Preparación del servidor APP

Desde el servidor DB se transfieren únicamente:

```text
db_password
db_backup_password
ca.pem
```

No se transfieren:

```text
mysql_root_password
ca.key
db-server.key
```

En APP:

```bash
bash scripts/prepare_app_server.sh /root/sivi-db-integration
nc -vz 69.169.109.197 3306
```

Antes de Dokploy debe comprobarse una conexión MySQL con `--ssl-mode=VERIFY_CA` y la CA suministrada por el servidor DB.

## 9. Despliegue de APP en Dokploy

Repositorio:

```text
https://github.com/metanexodigital-dev/SIVI
```

Configuración:

```text
Compose Type: Docker Compose
Compose Path: ./docker-compose.yml
```

Dominio:

```text
Domain: metanexo.digital
Service: app
Container Port: 80
HTTPS: activado
```

En **Environment Settings** utilice la plantilla:

```text
SIVI-APP-ENVIRONMENT-PRUEBAS.txt
```

Después ejecute:

```text
Rebuild without cache
Force recreate
Deploy
```

El log de APP debe utilizar `-f ./docker-compose.yml`. El servidor DB se administra de forma independiente con `docker-compose-db.yml`.

## 10. Validación posterior al despliegue

Contenedores esperados en APP:

```text
sivi-pruebas-app
sivi-pruebas-antimalware
sivi-pruebas-notificaciones
sivi-pruebas-respaldos
```

En DB:

```text
sivi-pruebas-db
```

Checks técnicos:

```bash
docker exec sivi-pruebas-app php scripts/check_mysql84_split_1_0_0_0.php
docker exec sivi-pruebas-app php scripts/check_split_topology_1_0_0_0.php
docker exec sivi-pruebas-app php scripts/check_soc_hardening_1_0_0_0.php
docker exec sivi-pruebas-app php scripts/build/run.php --json
```

Endpoints:

```text
https://metanexo.digital/health.php
https://metanexo.digital/ready.php
https://metanexo.digital/index.php?page=setup
```

El Setup crea el primer Superadministrador, pero **no ejecuta DDL ni migraciones**.

## 11. Funcionalidades principales

- Gestión territorial de departamentos, municipios, sedes y tipos de sede.
- Campañas de verificación de inventario.
- Inventario de computadores, portátiles, todo en uno, monitores, impresoras, escáneres, UPS y categorías configuradas.
- Importaciones de sedes, GLPI, Almacén, usuarios y directorio.
- Asociación automática de activos con sedes.
- Validación de serial y Placa RNEC.
- Equipos adicionales con control de duplicados.
- Novedades, correcciones, reaperturas y traslados.
- Evidencias y soportes documentales.
- Seguimiento por sede, municipio, departamento y nivel nacional.
- Control de calidad.
- Reportes y exportaciones.
- Auditoría de acciones.
- Notificaciones en segundo plano.
- Respaldos cifrados.
- PWA y diseño responsivo.

## 12. Roles

### Registrador

Trabaja sobre la sede asignada, valida equipos y registra novedades y equipos adicionales.

### Formador / Gestor departamental

Gestiona sedes dentro de sus departamentos, revisa validaciones, correcciones, reaperturas y traslados.

### Admin GI / Superadministrador

Administra el alcance nacional, campañas, usuarios, importaciones, parámetros, auditoría y reportes.

## 13. Seguridad

La línea `Pre-1.0.0.1` incorpora:

- TLS entre APP y MySQL.
- Secretos fuera del repositorio y de las imágenes.
- Usuarios MySQL con mínimo privilegio.
- ClamAV para cargas de archivos.
- Validaciones MIME, tamaño y estructura de XLSX/ZIP.
- CSP y cabeceras HTTP reforzadas.
- CSRF y controles de sesión.
- Limitación de intentos de autenticación.
- Eventos estructurados para SIEM/SOC.
- Respaldos cifrados.
- Health/readiness con exposición mínima.
- Contenedores con `no-new-privileges`, reducción de capacidades y límites de recursos.

La aprobación SOC final depende también de la configuración real de infraestructura, firewall, PKI, CI/SAST, escaneo de imágenes, SBOM y DAST.

## 14. Respaldos y persistencia

Volúmenes que deben conservarse:

```text
DB:  db_data
APP: app_storage
APP: sivi_backups
APP: clamav_signatures
```

El servicio de respaldo utiliza el usuario MySQL independiente `sivi_backup` y cifra los archivos de respaldo.

## 15. Política de base de datos

- MySQL `8.4.10` es el motor oficial de esta arquitectura.
- `AUTO_MIGRATE=false` es obligatorio.
- El esquema se crea únicamente durante la primera inicialización de una base nueva mediante `/docker-entrypoint-initdb.d`.
- La aplicación y el Setup no tienen privilegios DDL.
- No se distribuyen migraciones de esquema en tiempo de ejecución.
- Antes de cualquier actualización se requiere respaldo verificado y procedimiento de cambio controlado.

## 16. Versionamiento

Durante preproducción se utiliza el formato:

```text
Pre-N.N.N.N
```

La versión base de preproducción fue `Pre-1.0.0.0`. El primer cambio controlado es:

```text
Pre-1.0.0.1
```

Los cambios siguientes se incrementarán consecutivamente como `Pre-1.0.0.2`, `Pre-1.0.0.3`, etc. Al aprobar el paso a producción se publicará la primera versión oficial `1.0.0.0`.

`VERSION` es la fuente de versión del código. `APP_GIT_COMMIT` debe contener el hash real del commit desplegado.

El historial de cambios se mantiene exclusivamente en `Actualizaciones.md`, inicializado a partir de `Pre-1.0.0.1`.
