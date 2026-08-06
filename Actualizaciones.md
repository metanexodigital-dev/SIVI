# Actualizaciones de SIVI

Este archivo registra los cambios de SIVI a partir del inicio formal del control
de versiones de preproducción. No incorpora correcciones anteriores.

La documentación general del aplicativo permanece en `README.md`.

## Política de versionamiento

- Preproducción: `Pre-1.0.0.0`, `Pre-1.0.0.1`, `Pre-1.0.0.2`, etc.
- Producción: iniciará en `1.0.0.0` cuando la versión sea aprobada.
- Cada cambio debe actualizar `VERSION`, `RELEASE.json`, la versión visible,
  este archivo y el commit correspondiente.
- No se utilizará `CHANGELOG.md`.

---

## Pre-1.0.0.4 — Paquete funcional coherente para preproducción

**Fecha:** 6 de agosto de 2026
**Build:** `SIVI-Pre-1.0.0.4`
**Canal:** `preproduction`
**Tipo:** corrección técnica de empaquetado y despliegue
**Versión siguiente:** `Pre-1.0.0.5`

### Cambios

- Se alinearon `VERSION`, `RELEASE.json`, los manifiestos PWA, las plantillas de
  entorno y las etiquetas Docker con `Pre-1.0.0.4`.
- El control de archivos de publicación admite el formato seguro
  `Pre-N.N.N.N` durante preproducción y mantiene `N.N.N.N` para producción.
- Se reforzó la regla de etapa para impedir que una versión `Pre-*` sea marcada
  como producción o que una versión oficial sea marcada como preproducción.
- Se eliminó `assert.active` de `php.ini` por estar obsoleto en PHP 8.4.
- Se conserva la arquitectura separada APP/DB, MySQL 8.4, TLS, secretos,
  respaldos cifrados y todas las funciones existentes de SIVI.
- No incluye cambios de esquema ni migraciones automáticas.

### Validaciones requeridas

- Coherencia de publicación y versionamiento.
- Controles funcionales y de seguridad durante el build Docker.
- Construcción de las imágenes de aplicación y respaldo.
- Validación de Docker Compose para el servidor APP.

## Pre-1.0.0.3 — Documentación dinámica de versión

**Fecha:** 6 de agosto de 2026
**Build:** `SIVI-Pre-1.0.0.3`
**Canal:** `preproduction`
**Tipo:** corrección técnica y documentación
**Versión siguiente:** `Pre-1.0.0.4`

### Cambios

- Se sincronizó la identificación visible de `README.md` con la versión activa
  y el build `SIVI-Pre-1.0.0.3`.
- `readme_general_documentation` valida dinámicamente la versión leída desde
  `VERSION` y rechaza identificadores de preproducción con formato inválido.
- Se integra el cambio pendiente `Pre-1.0.0.2`, correspondiente al historial
  dinámico de preproducción.
- Se conserva `1.0.0.0` como línea base futura de producción.
- No incluye cambios de base de datos, migraciones ni alcance funcional.

## Pre-1.0.0.1 — Corrección del control security_ci

**Fecha:** 6 de agosto de 2026  
**Build:** `SIVI-Pre-1.0.0.1`  
**Canal:** `preproduction`  
**Tipo:** corrección técnica y seguridad  
**Versión siguiente:** `Pre-1.0.0.2`

### Cambios

- Se corrigió el bloqueo `security_ci` del verificador de hardening SOC.
- Los workflows SAST, CVE, secretos, SBOM y DAST continúan siendo obligatorios
  y se validan cuando el control se ejecuta desde el repositorio.
- Durante la construcción Docker se reconoce que `.github` fue excluido
  deliberadamente mediante `.dockerignore`, sin incorporar workflows a la
  imagen final.
- Se inició el control formal de cambios de preproducción.

### Archivos principales

- `scripts/check_soc_hardening_1_0_0_0.php`
- `VERSION`
- `RELEASE.json`
- `README.md`
- `Actualizaciones.md`

### Base de datos

- Sin cambios de estructura.
- Sin migraciones.
- `AUTO_MIGRATE=false` se mantiene obligatorio.

### Validaciones

- Validación completa de workflows cuando `.github/workflows` está disponible.
- Validación de exclusión deliberada de `.github` durante el build Docker.
- Conservación de los controles de hardening SOC.

---

## Pre-1.0.0.2 — Historial dinámico de preproducción

**Fecha:** 6 de agosto de 2026  
**Build:** `SIVI-Pre-1.0.0.2`  
**Canal:** `preproduction`  
**Tipo:** corrección técnica y control de cambios  
**Versión siguiente:** `Pre-1.0.0.3`

### Cambios

- Se corrigió el bloqueo `official_history` durante la construcción.
- El verificador obtiene la versión vigente desde `VERSION` y valida su coherencia con `RELEASE.json`.
- El historial acepta exclusivamente una secuencia continua de preproducción iniciada en `Pre-1.0.0.1`.
- Se mantiene `1.0.0.0` como línea base futura de producción, sin registrarla como versión ya publicada.
- La próxima versión se calcula y valida dinámicamente para evitar ajustes manuales del verificador en cada cambio.

### Archivos principales

- `scripts/check_clean_relaunch_1_0_0_0.php`
- `VERSION`
- `RELEASE.json`
- `README.md`
- `Actualizaciones.md`

### Base de datos

- Sin cambios de estructura.
- Sin migraciones.
- `AUTO_MIGRATE=false` se mantiene obligatorio.

### Validaciones

- Secuencia de historial esperada: `Pre-1.0.0.1`, `Pre-1.0.0.2`.
- Versión siguiente esperada: `Pre-1.0.0.3`.
- Ausencia de versiones oficiales de producción publicadas durante preproducción.
