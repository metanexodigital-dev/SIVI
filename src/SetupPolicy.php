<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: src/SetupPolicy.php
 * Propósito: Controla la disponibilidad y validación segura del proceso de instalación inicial.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

/**
 * Controla el acceso al instalador inicial.
 *
 * En pruebas puede deshabilitarse la clave mediante SETUP_REQUIRE_KEY=false.
 * En producción la clave siempre es obligatoria, incluso si la variable se
 * configuró por error en false. El instalador deja de estar disponible en
 * cuanto existe al menos un usuario en la base de datos.
 */
final class SetupPolicy
{
    public static function environment(): string
    {
        return strtolower(trim((string)Env::get('APP_ENV', 'production')));
    }

    public static function requiresKey(): bool
    {
        if (self::environment() === 'production') {
            return true;
        }

        return Env::bool('SETUP_REQUIRE_KEY', true);
    }

    public static function configuredKey(): string
    {
        return Env::normalizeValue((string)Env::get('APP_SETUP_KEY', ''));
    }

    public static function normalizeProvidedKey(?string $value): string
    {
        return Env::normalizeValue((string)$value);
    }

    public static function validate(?string $provided): bool
    {
        if (!self::requiresKey()) {
            return true;
        }

        $configured = self::configuredKey();
        if ($configured === '') {
            return false;
        }

        return hash_equals($configured, self::normalizeProvidedKey($provided));
    }

    public static function modeLabel(): string
    {
        if (!self::requiresKey()) {
            return 'pruebas sin clave (solo hasta crear el primer usuario)';
        }

        $key = self::configuredKey();
        if ($key === '') {
            return 'clave obligatoria no configurada';
        }

        return 'clave obligatoria configurada (' . strlen($key) . ' caracteres, fuente ' . Env::source('APP_SETUP_KEY') . ')';
    }
}
