# Actualizaciones de SIVI


## Motor de base de datos independiente - MySQL 8.4 LTS

- Se adopta MySQL 8.4.10 como motor de base de datos para la arquitectura separada APP/DB.
- `docker-compose-db.yml` se ejecuta exclusivamente en el servidor de base de datos.
- `docker-compose.yml` no contiene servicio `db`; la aplicación se conecta al servidor MySQL remoto mediante `DB_HOST`.
- MySQL obliga TLS 1.2/1.3 mediante `require_secure_transport=ON`.
- El certificado del servidor debe incluir como SAN el hostname o IP configurado en `DB_HOST`.
- El usuario `sivi_user` conserva solo `SELECT`, `INSERT`, `UPDATE` y `DELETE`; `sivi_backup` es de solo lectura y ambos requieren SSL.
- El esquema de instalación para MySQL 8.4 se distribuye como `database/schema.mysql84.sql` y se carga exclusivamente en la primera inicialización del volumen `db_data`.
- `AUTO_MIGRATE=false` permanece obligatorio.
- Se corrigió el mapeo de Docker Secrets TLS para que `ca.pem`, `db-server.crt` y `db-server.key` se monten con los nombres que consumen MySQL y los contenedores APP/backup.
- Se corrigió `README.md` para que el despliegue documentado coincida con la topología real: `docker-compose.yml` ejecuta únicamente los servicios de aplicación y `docker-compose-db.yml` despliega MySQL 8.4 de forma independiente.
- Se corrigió la inicialización MySQL 8.4 por uso de `LAST_VALUE`, palabra reservada del motor. La imagen de DB cita el identificador al preparar el esquema de runtime y la imagen APP cita las consultas del puente de captura móvil.
- Se corrigieron los permisos del directorio `/etc/mysql/sivi-tls`: el directorio se crea explícitamente con propietario `mysql:mysql` y modo `0750`, evitando que `umask 077` bloquee la lectura de certificados al proceso MySQL.
- Se reforzó el `healthcheck` de DB para no marcar el contenedor como saludable si falta el esquema, `app_release_history`, el usuario `sivi_backup`, `require_secure_transport` o el canal TLS `mysql_main`.
- Se amplió `scripts/validate_mysql84_db_server.sh` para validar versión, base, cantidad de tablas, tablas de control, usuario de respaldo y contexto TLS activo.

Este archivo es la fuente oficial y única para documentar cambios, mejoras,
correcciones y evolución de versiones de **SIVI — Sistema Integrado de
Verificación de Inventario**.

La documentación general del aplicativo permanece en `README.md`.

## Política de documentación

- `README.md`: arquitectura, instalación, operación, seguridad y uso general.
- `Actualizaciones.md`: cambios funcionales, técnicos, de seguridad y de experiencia de usuario.
- No se utilizará `CHANGELOG.md`.
- No se crearán archivos Markdown separados por versión, parche o mejora.
- Cada nueva versión debe agregarse en este mismo archivo.
- `RELEASE.json` conserva los metadatos técnicos de la compilación.
- La fuente de versión del código continúa siendo `VERSION`.

---

## 1.0.0.0 — Relanzamiento oficial de producción

**Estado:** Preparación final previa al lanzamiento  
**Build:** `SIVI-1.0.0.0`  
**Canal:** `production`  
**Siguiente versión compatible:** `1.0.0.1`

Esta versión consolida todas las mejoras realizadas durante el alistamiento del
nuevo despliegue de SIVI.

### Arquitectura y despliegue

- Implementación mediante Docker.
- Arquitectura separada entre servidor de aplicación y servidor MySQL.
- `APP_SERVER_HOSTNAME`, `DB_SERVER_HOSTNAME`, `APP_URL`,
  `APP_INTERNAL_DNS`, `DB_HOST` y `DB_LISTEN_IP` son parametrizables.
- La compilación puede utilizar distintos hostnames, dominios e IP sin modificar
  el código fuente.
- MySQL se despliega mediante `docker-compose-db.yml`.
- Aplicación, notificaciones, respaldos y antimalware se despliegan mediante
  `docker-compose.yml`.
- `AUTO_MIGRATE=false`.
- El esquema se inicializa exclusivamente mediante
  `/docker-entrypoint-initdb.d` en una base nueva.
- La aplicación web y el Setup no realizan cambios DDL.

### Seguridad y preparación SOC

- TLS entre aplicación y MySQL.
- Secretos fuera del repositorio y de las imágenes mediante archivos montados.
- Usuario MySQL de aplicación con mínimo privilegio.
- Usuario independiente para respaldos.
- ClamAV para análisis antimalware de archivos cargados.
- Validación MIME, límites de tamaño y controles estructurales para XLSX.
- Protección frente a expansión anómala de archivos ZIP.
- CSP reforzada y cabeceras HTTP de seguridad.
- Cookies `Secure`, `HttpOnly` y `SameSite`.
- Protección CSRF.
- Limitación de intentos de autenticación.
- Auditoría y eventos estructurados `SIVI_SECURITY_EVENT` preparados para SIEM.
- Respaldos cifrados.
- `health.php` y `ready.php` con exposición mínima de información.
- Contenedores con controles de endurecimiento.
- Pipeline preparado para SAST, Trivy, SBOM y DAST.

### Rendimiento

- Caché por solicitud para configuración y estado de inicialización.
- Optimización de consultas de Placa RNEC.
- Consultas agrupadas a `information_schema`.
- Eliminación de consultas N+1 en campañas, calidad y reaperturas.
- Liberación anticipada del bloqueo de sesión en solicitudes AJAX.
- Compresión HTTP.
- Caché controlada para recursos estáticos.
- Service Worker protegido contra caché obsoleta.
- Motor centralizado de controles de construcción.
- Optimización de trabajador de notificaciones y respaldos.

### Experiencia de usuario

- Borradores administrados únicamente desde Configuración.
- El usuario operativo no puede activar, desactivar ni descartar borradores.
- Guardado automático silencioso cuando la opción administrativa está habilitada.
- Botón **Usar placa sugerida** ubicado junto al campo Placa RNEC.
- Equipo propiedad de la RNEC sin placa visible puede continuar únicamente con
  una justificación obligatoria.
- La opción **Otro motivo** al indicar que un equipo no pertenece a la sede
  permite y exige escribir la explicación.
- Si Administración configura **No solicitar imágenes en Validar inventario**,
  no se muestran los controles de carga de imágenes y Control de Calidad no las exige.
- Se retiró **Resultado de la validación** de la información editable de la sede.
- Cargos disponibles: `Registrador`, `Auxiliar` y `Técnico`.
- Notificaciones y Correcciones se muestran únicamente cuando existen pendientes.
- Al trabajar dentro de una sede se ocultan filtros territoriales redundantes y
  se mantiene la sede actual como contexto.
- Corrección del `401` de onboarding en la pantalla de login: el recorrido guiado
  se carga únicamente después de la autenticación.

### Integridad funcional

Se conservan:

- Login, sesiones y cambio obligatorio de contraseña.
- Roles Registrador, Formador y Administración GI.
- Importaciones de Sedes, GLPI y Almacén.
- Validación de inventario.
- Equipos adicionales.
- Validación de serial y Placa RNEC.
- Prevención de duplicados.
- Campañas.
- Seguimiento.
- Control de calidad.
- Traslados.
- Reaperturas.
- Reportes y exportaciones.
- Captura móvil.
- PWA.
- Notificaciones.
- Respaldos.
- Auditoría.

### Base de datos

Esta consolidación no introduce cambios automáticos de estructura desde la
aplicación.

La política vigente es:

```text
AUTO_MIGRATE=false
```

Los volúmenes persistentes deben conservarse durante actualizaciones:

```text
db_data
app_storage
sivi_backups
clamav_signatures
```

No debe utilizarse `docker compose down -v` en un entorno con información que
deba conservarse.

---

## Plantilla para próximas versiones

Las próximas actualizaciones deben agregarse arriba de la versión anterior
siguiendo esta estructura:

```text
## X.X.X.X — Título breve

Fecha:
Build:
Tipo: corrección / mejora / funcionalidad / seguridad

### Cambios
- ...

### Archivos principales
- ...

### Base de datos
- Sin cambios / descripción autorizada.

### Compatibilidad y despliegue
- ...

### Validaciones
- ...
```
