<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/install.php
 * Propósito: Verifica que el esquema inicial de MySQL esté listo sin ejecutar DDL.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

try {
    $schema = Database::schemaStatus();
    if (!$schema['ok']) {
        fwrite(
            STDERR,
            "SIVI: el esquema no está listo. Revise sivi-produccion-db y /docker-entrypoint-initdb.d.\n"
        );
        exit(2);
    }

    if (Database::isInstalled()) {
        echo "SIVI: esquema listo y usuario inicial existente. No se realizaron cambios.\n";
    } else {
        echo "SIVI: esquema listo. Abra /index.php?page=setup para crear el Superadministrador.\n";
    }

    exit(0);
} catch (Throwable $exception) {
    $reference = log_exception_reference($exception, 'install_check');
    fwrite(
        STDERR,
        "SIVI: no fue posible verificar la instalación. Referencia: {$reference}.\n"
    );
    exit(1);
}
