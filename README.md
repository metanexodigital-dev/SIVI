
## Arquitectura vigente de despliegue

SIVI se despliega en dos servidores independientes. El servidor de aplicación ejecuta `docker-compose.yml` y se conecta por TLS al servidor de base de datos. El servidor DB ejecuta `docker-compose-db.yml` con MySQL 8.4.10. Para la prueba actual, APP corresponde a `vps3486536` (`69.10.35.41`) y DB a `vps3536788` (`69.169.109.197`). El puerto 3306 debe permitirse únicamente desde el servidor APP.

# SIVI — Sistema Integrado de Verificación de Inventario


### Arquitectura oficial de producción

El relanzamiento `1.0.0.0` utiliza dos servidores independientes:

| Función | Hostname | Sistema operativo | CPU | RAM | DD | DD2 |
| --- | --- | --- | ---: | ---: | ---: | ---: |
| Aplicación SIVI | `APP_SERVER_HOSTNAME` | Oracle Linux 10.1 | 4 | 8 GB | 100 GB | 100 GB |
| Base de datos MySQL | `DB_SERVER_HOSTNAME` | Oracle Linux 10.1 | 8 | 8 GB | 100 GB | 100 GB |

DNS interno de aplicación: `DOMINIO_CONFIGURADO_EN_APP_URL`.

- `docker-compose.yml` corresponde al servidor `APP_SERVER_HOSTNAME`.
- `docker-compose-db.yml` corresponde al servidor `DB_SERVER_HOSTNAME`.
- La aplicación usa `DB_HOST=HOST_O_IP_PRIVADA_DE_MARIADB`.
- MySQL publica TCP/3306 únicamente en la IP privada configurada mediante `DB_LISTEN_IP`.
- El puerto 3306 debe autorizarse exclusivamente desde la IP privada de `APP_SERVER_HOSTNAME`.
- El servidor de base de datos no requiere publicación HTTP/HTTPS.
- `AUTO_MIGRATE=false`.


## Versión oficial de producción 1.0.0.0

**Versión actual:** `1.0.0.0`  
**Canal:** producción  
**Build:** `SIVI-1.0.0.0`  
**Ambiente:** `production`  
**Modo de base de datos:** inicialización limpia sin cambios de esquema

Esta compilación se despliega sobre volúmenes nuevos. El contenedor de MySQL
incluye el esquema completo de SIVI y lo ejecuta una sola vez cuando crea el
volumen `db_data`.

La aplicación no distribuye herramientas de cambio de esquema en tiempo de ejecución. `AUTO_MIGRATE` queda forzado a `false` en los servicios `app` y `notifications`.

Flujo:

1. Crear proyecto o eliminar los volúmenes de una instalación fallida.
2. Desplegar el Compose.
3. MySQL crea `sivi_produccion` y carga el esquema completo.
4. MySQL registra la huella del esquema y la versión `1.0.0.0`.
5. Apache inicia cuando la base queda saludable.
6. Abrir `/index.php?page=setup`.
7. Crear el primer Superadministrador con `APP_SETUP_KEY`.
8. El Setup queda cerrado cuando ya existe el usuario.

Incluye selección jerárquica de sedes, registro dinámico de equipos adicionales,
configuración administrativa de imágenes, Placa RNEC `000-XXXXX`, respaldos,
seguridad HTTP, health check y readiness.

> Las credenciales reales se entregan en un archivo separado y no deben subirse
> a GitHub. `README.md` contiene la documentación general y `Actualizaciones.md`
> concentra el historial oficial de cambios y versiones.

## 1. Objetivo

SIVI permite verificar y actualizar el inventario tecnológico de las sedes de la RNEC. Cada oficina puede revisar sus elementos, confirmar serial y Placa RNEC, reportar estado físico, registrar evidencias y solicitar correcciones. Los perfiles departamentales y nacionales realizan seguimiento, aprobación, control de calidad y reportes.

## 2. Funcionalidades principales

- Gestión de sedes, departamentos, municipios y tipos de sede.
- Campañas de validación por uno, varios o todos los departamentos.
- Inventario de computadores, portátiles, todo en uno, monitores, impresoras, escáneres, UPS y otros elementos autorizados.
- Importación de inventario GLPI, almacén, sedes, usuarios y directorio.
- Asociación automática de elementos con la sede más probable.
- Validación guiada del activo, serial y Placa RNEC.
- Registro de equipos adicionales, novedades, correcciones, reaperturas y traslados.
- Evidencias fotográficas y soportes documentales.
- Seguimiento nacional, departamental, municipal y por sede.
- Exportación a Excel y CSV con protección frente a fórmulas maliciosas.
- Auditoría de acciones, historial del activo y control de versiones desplegadas.
- Interfaz adaptable para computador, tableta y celular.

## 3. Roles y alcance

### Registrador

- Accede únicamente a la sede asignada.
- Valida los equipos de su oficina.
- Registra equipos adicionales y novedades.
- Corrige información cuando una validación es devuelta.
- Consulta el avance de su sede.

### Formador o gestor departamental

- Consulta las sedes de los departamentos asignados.
- Revisa y aprueba validaciones.
- Gestiona correcciones, reaperturas y traslados.
- Restablece contraseñas de usuarios de su alcance.
- Consulta indicadores municipales y departamentales.

### Admin GI o Superadministrador

- Accede al ámbito nacional.
- Administra campañas, sedes, usuarios, importaciones y parámetros.
- Supervisa calidad, asociaciones territoriales y avance nacional.
- Consulta auditoría, reportes y estado técnico del aplicativo.

## 4. Flujo de campañas

Al crear una campaña se define:

1. Nombre, descripción y fechas.
2. Uno, varios o todos los departamentos.
3. Sedes participantes.
4. Instrucciones para los usuarios.
5. Estado de la campaña: borrador, programada, activa, en revisión, finalizada o cancelada.

Los usuarios solo visualizan campañas y sedes dentro de su alcance territorial.

## 5. Validación del activo

El formulario se presenta como un asistente guiado.

### Paso 1. Situación del equipo

Tipo de propiedad:

- Propio de la RNEC.
- En comodato.
- Donado sin legalizar.

Estado físico:

- Activo.
- Inactivo.
- Para baja.
- Dado de baja.
- En Almacén.
- En Mantenimiento.
- Trasladado.

Reglas condicionales:

- **Dado de baja:** exige fecha y resolución o acta de baja.
- **Trasladado:** exige departamento, municipio, tipo de sede y sede destino.
- La pertenencia a la oficina se calcula automáticamente: un equipo trasladado no pertenece a la sede que realiza la validación.

### Paso 2. Serial y Placa RNEC

- El número de serie verificado es obligatorio para todos los equipos.
- La Placa RNEC es obligatoria para equipos propios de la RNEC.
- La placa es opcional para comodatos y donaciones sin legalizar.
- Cuando se registra una placa, debe cumplir el formato `000-00000`.
- El sistema compara el valor registrado con el valor observado y muestra si coincide o será corregido.

### Paso 3. Evidencias y confirmación

Se pueden solicitar:

- Foto general del equipo.
- Foto del serial.
- Foto de la Placa RNEC.
- Soporte de baja, daño o mantenimiento.

Antes de guardar se presenta un resumen y el usuario confirma que realizó la verificación física.

## 6. Asociación automática de sede

SIVI aplica el siguiente orden obligatorio:

1. **Hostname contra Maestro de Sedes:** se busca una coincidencia directa y suficientemente específica con el identificador oficial.
2. **Usuario GLPI:** si el hostname no identifica la sede, se buscan municipio y departamento en el usuario; una coincidencia solo por nombre de municipio no se considera suficiente.
3. **Localización GLPI y cruce de fuentes:** se identifican departamento, municipio y tipo de sede, y se cruzan con los datos territoriales obtenidos del usuario. La nomenclatura se utiliza como apoyo cuando las fuentes GLPI no aportan el dato.
4. **Reglas de contingencia:** se aplican únicamente cuando las tres validaciones anteriores no determinan una sede exacta.

Reglas finales:

- Nomenclatura `AB-` sin sede auxiliar identificable: asignación provisional a la **Registraduría Distrital**.
- Nomenclatura `RM-{código departamento}` sin municipio identificable: asignación provisional a la **Delegación Departamental** correspondiente.
- Registraduría Auxiliar sin sede identificable: asignación provisional a la **Delegación Departamental** correspondiente.

Las asociaciones de contingencia quedan con confianza baja y requieren revisión. Una asignación manual aprobada o un traslado confirmado no debe ser reemplazado automáticamente por una nueva importación.

## 7. Experiencia de usuario

- Menú organizado por procesos y permisos.
- Centro de prioridades en el dashboard.
- Filtros por campaña, departamento, municipio y sede.
- Formularios por pasos con mensajes junto al campo.
- Campos condicionales visibles solo cuando son necesarios.
- Borrador automático de validaciones sin guardar fotografías localmente.
- Protección contra doble envío.
- Aviso al intentar salir con cambios pendientes.
- Navegación anterior/siguiente para validar equipos consecutivamente.
- Indicador de avance de la sede y la campaña.
- Accesibilidad mediante teclado y diseño responsivo.

## 8. Arquitectura

- PHP `8.4` con Apache.
- MySQL `8.4.10`.
- Docker Compose.
- Despliegue recomendado en Dokploy con Traefik y HTTPS.
- Bootstrap y JavaScript servidos localmente.
- OPcache, compresión y caché de recursos estáticos.
- Service Worker limitado a archivos visuales; no guarda formularios ni datos privados.

Estructura principal:

```text
Dockerfile
docker-compose.yml
VERSION
RELEASE.json
README.md
config/
database/
docker/
docs/
public/
scripts/
src/
storage/
```

La carpeta `docs/` conserva únicamente archivos de apoyo no Markdown, como maestros o plantillas Excel.

## 9. Variables de entorno

La plantilla oficial está en:

```text
config/environment.example
```

Variables esenciales:

```env
APP_NAME=SIVI
APP_VERSION=1.0.0.0
APP_ENV=production
APP_BUILD_ID=SIVI-1.0.0.0
APP_GIT_COMMIT=COMMIT_REAL_DE_GITHUB
APP_URL=https://DOMINIO_CONFIGURADO_EN_APP_URL
APP_TIMEZONE=America/Bogota

DB_HOST=HOST_O_IP_PRIVADA_DE_MARIADB
DB_PORT=3306
DB_DATABASE=sivi_produccion
DB_USERNAME=sivi_user
DB_TLS_MODE=verify_ca
AUTO_MIGRATE=false

APP_SETUP_KEY_SECRET_FILE=/opt/sivi/secrets/app_setup_key
APP_ENCRYPTION_KEY_SECRET_FILE=/opt/sivi/secrets/app_encryption_key
DB_PASSWORD_SECRET_FILE=/opt/sivi/secrets/db_password
DB_BACKUP_PASSWORD_SECRET_FILE=/opt/sivi/secrets/db_backup_password
BACKUP_ENCRYPTION_KEY_SECRET_FILE=/opt/sivi/secrets/backup_encryption_key
DB_TLS_CA_HOST_PATH=/opt/sivi/pki/ca.pem
```

Los valores secretos se almacenan en archivos con permisos restrictivos y se montan como Docker Secrets; no se guardan en Git ni como texto dentro del Compose.


## Configuración Microsoft 365

En Microsoft Entra registre una aplicación denominada **SIVI Notificaciones**, agregue el permiso de aplicación `Mail.Send`, conceda consentimiento de administrador y limite el acceso al buzón remitente mediante RBAC para aplicaciones de Exchange Online. En SIVI ingrese Tenant ID, Client ID, valor del secreto, buzón remitente y fecha de vencimiento desde **Administración → Correo y notificaciones**.

El servicio `notifications` procesa la cola en segundo plano. Para una ejecución manual use:

```bash
php scripts/process_notification_queue.php --limit=50
```

## 10. Despliegue en Dokploy

### Repositorio

El contenido debe quedar directamente en la raíz, sin una carpeta adicional que envuelva el proyecto.

### Dominio

En Dokploy configure:

```text
Dominio: DOMINIO_CONFIGURADO_EN_APP_URL
Servicio: app
Container Port: 80
HTTPS: activado
```

El servicio no publica un puerto del servidor. Dokploy y Traefik deben dirigir el dominio al puerto interno `80` del servicio `app`, evitando conflictos con otros aplicativos.

### Construcción

Ejecute:

```text
Rebuild without cache
Force recreate
Deploy
```

La aplicación debe construir los servicios `app`, `notifications` y `db`. MySQL debe alcanzar el estado saludable antes de iniciar la aplicación.

## 11. Instalación inicial

Abra:

```text
https://DOMINIO_CONFIGURADO_EN_APP_URL/index.php?page=setup
```

En producción, `SETUP_REQUIRE_KEY=true` exige la clave de instalación configurada mediante Docker Secret para crear el primer Superadministrador. Una vez creado el primer usuario, la ruta de instalación se cierra automáticamente.

Endpoints de diagnóstico:

```text
/ping.php
/health.php
/index.php?page=versionamiento
/index.php?page=diagnostico
```

## 12. Scripts obligatorios

Los scripts forman parte del control de calidad. El `Dockerfile` ejecuta únicamente los controles funcionales que pueden bloquear una construcción defectuosa; las validaciones documentales se conservan para ejecución manual.

```bash
php scripts/check_required_files.php
php scripts/check_sede_association.php
php scripts/check_optimization.php
```

Funciones:

- `check_release_files.php`: valida versión, manifiestos y documentación única; se ejecuta manualmente y no bloquea el contenedor.
- `check_required_files.php`: confirma que estén presentes los archivos necesarios para operar.
- `check_sede_association.php`: prueba la jerarquía de asociación territorial.
- `check_optimization.php`: comprueba recursos locales, seguridad, caché, puerto y healthcheck.

Scripts operativos adicionales:

```bash
php scripts/preflight.php
php scripts/check_schema.php
php scripts/check_environment.php
```

## 13. Política de actualización y base de datos

- Conserve siempre los volúmenes `db_data`, `app_storage`, `sivi_backups` y `clamav_signatures`.
- `AUTO_MIGRATE=false` es obligatorio en producción.
- El esquema oficial se crea únicamente durante la inicialización de una base nueva mediante la imagen `sivi-db` y `/docker-entrypoint-initdb.d`.
- La aplicación web y el Setup no tienen privilegios DDL y no modifican la estructura de MySQL.
- Antes de una actualización futura se debe disponer de respaldo verificado, paquete aprobado y procedimiento de cambio autorizado por infraestructura/SOC.

```bash
php /var/www/html/scripts/check_schema.php
php /var/www/html/scripts/preflight.php --json
```

## 14. Pruebas funcionales mínimas

Después del despliegue valide:

1. Apertura de `ping.php` y `health.php`.
2. Creación o acceso del Superadministrador.
3. Importación del maestro de sedes.
4. Creación de una campaña por departamento.
5. Acceso de un Registrador únicamente a su sede.
6. Validación de un equipo propio con serial y placa.
7. Validación de un comodato sin placa.
8. Estado “Dado de baja” con sus datos obligatorios.
9. Estado “Trasladado” con selección completa de sede destino.
10. Asociación territorial por hostname, usuario, localización GLPI y contingencias.
11. Aprobación o devolución por el perfil departamental.
12. Exportación a Excel y CSV.
13. Consulta de auditoría y reportes.

## 15. Seguridad

- Contraseñas almacenadas mediante hash seguro.
- Cambio obligatorio de contraseña cuando corresponda.
- Protección CSRF en formularios.
- Limitación de intentos de acceso.
- Sesiones con expiración por inactividad y tiempo absoluto.
- Cookies `HttpOnly`, `Secure` y `SameSite` configurables.
- Encabezados de seguridad y política CSP.
- Validación de archivos, tamaños y extensiones.
- Sanitización de datos exportados.
- Contenedores con `no-new-privileges` y rotación de logs.

## 16. Identidad visual

La identidad oficial es **SIVI — Sistema Integrado de Verificación de Inventario**. Los logotipos, isotipos, favicon, iconos PWA y recursos para correo se encuentran en:

```text
public/assets/brand/
```

La aplicación usa el logotipo horizontal en el acceso y el isotipo en la navegación.

## 17. Versionamiento

- Formato: `N.N.N.N`.
- La línea base oficial de producción es `1.0.0.0`.
- Las correcciones y mejoras compatibles continuarán como `1.0.0.1`, `1.0.0.2`, etc.
- La fuente única de la versión del código es el archivo `VERSION`.
- `APP_VERSION` debe coincidir con `VERSION`.
- `APP_GIT_COMMIT` debe contener el hash del commit desplegado.
- Consulte `Actualizaciones.md` para el historial oficial de cambios y versiones.

El historial oficial de cambios y versiones se mantiene en `Actualizaciones.md`. `README.md` conserva la documentación general y `RELEASE.json` los metadatos técnicos de cada compilación.

## 18. Licencia

Consulte `LICENSE.txt`.



## Validaciones durante la construcción

La imagen de producción utiliza `scripts/run_production_build_checks.sh`.

Los controles críticos de versión, archivos requeridos, esquema, contenedores,
volúmenes y configuración de producción detienen el build cuando detectan un
error. Las comprobaciones funcionales complementarias se ejecutan y registran
como advertencias para evitar que una diferencia de formato o un falso positivo
de análisis estático impida construir una versión previamente validada.

Cada control imprime en el log su nombre, comando y resultado.


### Preparación para validación SOC

La compilación oficial `1.0.0.0` incorpora controles preventivos orientados a una revisión SOC, sin alterar los módulos funcionales de SIVI:

- Secretos de aplicación, base de datos y respaldos mediante archivos montados y no incluidos en la imagen.
- TLS obligatorio entre `APP_SERVER_HOSTNAME` y `DB_SERVER_HOSTNAME` con CA confiable.
- Usuario de aplicación MySQL limitado a `SELECT`, `INSERT`, `UPDATE` y `DELETE`; usuario independiente de respaldo de solo lectura.
- Análisis antimalware de cargas mediante ClamAV y validación estructural de XLSX, incluida protección contra expansión anómala de ZIP.
- CSP estricta sin `unsafe-inline`, HSTS y cabeceras de aislamiento/navegador.
- Eventos de seguridad estructurados para recolección por SIEM/SOC.
- Respaldos cifrados y restauración con credenciales temporales de privilegio elevado.
- Contenedores con `no-new-privileges`, reducción de capacidades, límites de PIDs y `tmpfs` protegido.
- Endpoints públicos `health` y `ready` con respuesta mínima, sin versión, esquema, latencia o enumeración de componentes.
- El Setup inicial no ejecuta DDL: el esquema se crea exclusivamente durante la inicialización del contenedor MySQL.
- Pipeline definido para SAST, análisis de secretos, vulnerabilidades de imágenes, SBOM y DAST pasivo.

La aprobación final depende de los controles, políticas, escáneres y configuración de infraestructura utilizados por el SOC. Los resultados de CI, escaneo de imágenes y DAST deben conservarse como evidencia del despliegue revisado.


### Ajustes finales de experiencia de usuario

Antes del lanzamiento oficial se consolidaron mejoras para reducir decisiones innecesarias y hacer más guiado el trabajo operativo:

- El guardado de borradores se controla exclusivamente desde **Administración > Configuración**. Cuando está activo funciona de forma automática y el usuario operativo no dispone de botones para deshabilitarlo o descartarlo.
- En equipos propios de la RNEC, **Usar placa sugerida** aparece junto al campo de Placa RNEC.
- Cuando una Placa RNEC no puede visualizarse físicamente, el equipo propio puede continuar únicamente registrando una justificación obligatoria.
- Al seleccionar **Otro** como motivo de no pertenencia a la sede, se habilita una explicación obligatoria.
- La carga de imágenes del paso final de Validar inventario puede deshabilitarse centralmente. Si está deshabilitada, el paso no presenta campos de adjuntos ni el cierre de calidad exige fotografías.
- La información de la sede ya no solicita al usuario seleccionar un resultado de validación; SIVI lo determina a partir de la existencia o no de una observación.
- El cargo del responsable se limita a **Registrador**, **Auxiliar** y **Técnico**.
- **Notificaciones** y **Correcciones** aparecen en navegación únicamente cuando existe trabajo pendiente dentro de la sede o alcance del usuario.
- Cuando el usuario ya trabaja dentro de una sede, se ocultan los filtros territoriales redundantes y se conserva la sede como contexto fijo.
- Las observaciones especiales de placa y pertenencia se almacenan sin modificar el esquema de base de datos y se presentan de forma legible en reportes.


## 19. Historial oficial de versiones

### 1.0.0.0 — 2026-08-03

Primera versión oficial del relanzamiento de producción de SIVI.

La compilación consolida en una única línea base estable las funcionalidades
y optimizaciones preparadas para el nuevo servidor:

- Gestión y validación integral del inventario.
- Importación de sedes, GLPI e inventario de Almacén.
- Validación optimizada de serial y Placa RNEC.
- Prevención de registros duplicados.
- Popup con información y ubicación del equipo cuando existe coincidencia.
- Registro y control de equipos adicionales.
- Roles y permisos por usuario, sede y departamento.
- Campañas, seguimiento, control de calidad, traslados y reaperturas.
- Reportes y exportaciones.
- Captura móvil, QR/código de barras y PWA.
- Notificaciones con trabajador resiliente, heartbeat y backoff.
- Optimización de consultas MySQL y eliminación de consultas N+1.
- Cachés controladas por solicitud y para catálogos.
- Compresión HTTP y caché segura de archivos estáticos.
- Motor centralizado de validaciones de construcción.
- Respaldos y restauración.
- Pre-deploy y post-deploy seguros.
- Scripts históricos protegidos contra ejecución accidental.
- Validación robusta de contenedores y volúmenes Docker.
- Seguridad reforzada de Apache y PHP.
- `DB_HOST=HOST_O_IP_PRIVADA_DE_MARIADB`.
- `AUTO_MIGRATE=false`.
- Inicialización de MySQL mediante `/docker-entrypoint-initdb.d`.

Este relanzamiento está preparado para una instalación limpia en servidor nuevo.

La siguiente actualización oficial será `1.0.0.1`.


- Arquitectura de dos servidores: `APP_SERVER_HOSTNAME` para aplicación y `DB_SERVER_HOSTNAME` para MySQL.
- DNS interno oficial: `DOMINIO_CONFIGURADO_EN_APP_URL`.
- El onboarding ya no se carga antes de autenticarse, evitando el `401` innecesario en la pantalla de login.
- Ajustes finales de UX: borrador administrado centralmente, placa sugerida junto al campo, excepción justificada para placa RNEC no visible, “Otro motivo” explicable, evidencias condicionadas por configuración, cargos cerrados, navegación por pendientes y filtros de sede simplificados.
