<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: scripts/validate_schema_source.php
 * Propósito: Ejecuta la tarea técnica «validate schema source» para operación, validación o mantenimiento de SIVI.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

$schema = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
if ($schema === false) {
    fwrite(STDERR, "No fue posible leer database/schema.sql\n");
    exit(1);
}

$errors = [];
if (preg_match('/\\brow_number\\s+INT\\b/i', $schema)) {
    $errors[] = 'El esquema todavía utiliza row_number como identificador sin escapar.';
}
if (!preg_match('/CREATE TABLE IF NOT EXISTS import_errors[\\s\\S]*?\\bsource_row\\s+INT\\s+NULL/i', $schema)) {
    $errors[] = 'No se encontró import_errors.source_row en el esquema.';
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, $error . "\n");
    }
    exit(1);
}

echo "Esquema fuente validado correctamente.\n";
