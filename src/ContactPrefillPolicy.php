<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: src/ContactPrefillPolicy.php
 * Propósito: Controla qué datos de contacto pueden reutilizarse y evita prellenados no autorizados.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

/**
 * Regla de SIVI 0.0.0.46:
 * Los datos del responsable no se copian automáticamente desde el usuario autenticado,
 * directorio de sede o registrador asignado. Solo se muestran datos guardados de forma
 * explícita para la misma campaña y sede.
 */
final class ContactPrefillPolicy
{
    public static function persistedValue(array $campaignSite, string $field): string
    {
        $allowed = [
            'contact_name', 'responsible_name', 'registrador_name',
            'contact_position', 'responsible_position', 'cargo',
            'contact_email', 'responsible_email', 'contact_phone', 'responsible_phone'
        ];
        if (!in_array($field, $allowed, true)) {
            return '';
        }
        return trim((string) ($campaignSite[$field] ?? ''));
    }

    public static function hasPersistedContact(array $campaignSite): bool
    {
        foreach (['contact_name', 'responsible_name', 'registrador_name', 'contact_email', 'responsible_email'] as $field) {
            if (trim((string) ($campaignSite[$field] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }
}
