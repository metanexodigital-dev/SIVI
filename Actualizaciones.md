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
