<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: src/AppSettings.php
 * Propósito: Administra configuraciones operativas almacenadas en la base de datos.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

/**
 * Configuración funcional administrable desde SIVI.
 * Usa app_settings para evitar depender de nuevas variables de entorno.
 */
final class AppSettings
{
    /** @var array<string,string>|null */
    private static ?array $requestCache = null;

    private const DEFAULTS = [
        'mobile_capture.enabled' => '1',
        'mobile_capture.live_camera' => '1',
        'mobile_capture.image_upload' => '1',
        'mobile_capture.manual_entry' => '1',
        'mobile_capture.session_minutes' => '10',
        'pwa.install_enabled' => '1',
        // Experiencia de validación administrada centralmente.
        'validation.drafts_enabled' => '1',
        'validation.images_mode' => 'optional',
        // Control administrable de imágenes para equipos adicionales.
        'additional_equipment.images_mode' => 'none',
        'notifications.enabled' => '0',
        'notifications.provider' => 'log',
        'notifications.queue_enabled' => '1',
        'notifications.max_attempts' => '5',
        'notifications.retry_minutes' => '10',
        'microsoft_graph.tenant_id' => '',
        'microsoft_graph.client_id' => '',
        'microsoft_graph.client_secret' => '',
        'microsoft_graph.sender_address' => '',
        'microsoft_graph.sender_name' => 'SIVI-RNEC',
        'microsoft_graph.reply_to' => '',
        'microsoft_graph.test_recipient' => '',
        'microsoft_graph.secret_expires_on' => '',
    ];

    public static function get(string $key, ?string $default = null): string
    {
        $fallback = $default ?? (self::DEFAULTS[$key] ?? '');
        try {
            $settings = self::all();
            return array_key_exists($key, $settings)
                ? (string)$settings[$key]
                : $fallback;
        } catch (Throwable) {
            return $fallback;
        }
    }

    /** @return array<string,string> */
    private static function all(): array
    {
        if (self::$requestCache !== null) {
            return self::$requestCache;
        }

        $rows = Database::fetchAll(
            'SELECT setting_key,setting_value FROM app_settings'
        );
        $settings = [];
        foreach ($rows as $row) {
            $settings[(string)$row['setting_key']] =
                (string)($row['setting_value'] ?? '');
        }

        self::$requestCache = $settings;
        return self::$requestCache;
    }

    public static function clearRequestCache(): void
    {
        self::$requestCache = null;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $fallback = $default ? '1' : '0';
        return in_array(strtolower(trim(self::get($key, $fallback))), ['1','true','yes','si','sí','on'], true);
    }

    public static function int(string $key, int $default, int $minimum, int $maximum): int
    {
        $value = (int)self::get($key, (string)$default);
        return max($minimum, min($maximum, $value));
    }

    /** @param array<string,string|int|bool> $values */
    public static function setMany(array $values): void
    {
        $pdo = Database::connection();
        $started = !$pdo->inTransaction();
        if ($started) $pdo->beginTransaction();

        $statement = $pdo->prepare(
            'INSERT INTO app_settings(setting_key,setting_value,updated_at) '
            . 'VALUES(?,?,NOW()) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'setting_value=VALUES(setting_value),updated_at=NOW()'
        );

        try {
            foreach ($values as $key => $value) {
                if (!array_key_exists($key, self::DEFAULTS)) continue;
                if (is_bool($value)) $value = $value ? '1' : '0';
                $statement->execute([$key, (string)$value]);
            }
            if ($started) $pdo->commit();
            self::clearRequestCache();
        } catch (Throwable $e) {
            self::clearRequestCache();
            if ($started && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function mobileCaptureEnabled(): bool
    {
        return self::bool('mobile_capture.enabled', true);
    }

    public static function liveCameraEnabled(): bool
    {
        return self::mobileCaptureEnabled() && self::bool('mobile_capture.live_camera', true);
    }

    public static function imageUploadEnabled(): bool
    {
        return self::mobileCaptureEnabled() && self::bool('mobile_capture.image_upload', true);
    }

    public static function manualEntryEnabled(): bool
    {
        return self::mobileCaptureEnabled() && self::bool('mobile_capture.manual_entry', true);
    }

    public static function mobileSessionMinutes(): int
    {
        return self::int('mobile_capture.session_minutes', 10, 5, 30);
    }

    public static function pwaInstallEnabled(): bool
    {
        return self::bool('pwa.install_enabled', true);
    }

    public static function validationDraftsEnabled(): bool
    {
        return self::bool('validation.drafts_enabled', true);
    }

    /**
     * En validación de inventario la configuración global puede ocultar por
     * completo las evidencias. Cuando está habilitada, la campaña conserva la
     * decisión de hacer obligatoria o no la fotografía general.
     */
    public static function validationImagesMode(): string
    {
        $mode = strtolower(trim(self::get('validation.images_mode', 'optional')));
        return in_array($mode, ['none','optional'], true) ? $mode : 'optional';
    }

    public static function validationImagesEnabled(): bool
    {
        return self::validationImagesMode() !== 'none';
    }

    /** @return array{drafts_enabled:bool,images_mode:string} */
    public static function validationExperienceConfig(): array
    {
        return [
            'drafts_enabled' => self::validationDraftsEnabled(),
            'images_mode' => self::validationImagesMode(),
        ];
    }

    /** Define si las imágenes de equipos adicionales no se solicitan, son opcionales o requeridas. */
    public static function additionalEquipmentImagesMode(): string
    {
        $mode = strtolower(trim(self::get('additional_equipment.images_mode', 'none')));
        return in_array($mode, ['none','optional','required'], true) ? $mode : 'none';
    }

    /** @return array{images_mode:string} */
    public static function additionalEquipmentConfig(): array
    {
        return ['images_mode' => self::additionalEquipmentImagesMode()];
    }


    public static function notificationsEnabled(): bool
    {
        return self::bool('notifications.enabled', false);
    }

    public static function notificationProvider(): string
    {
        $provider = strtolower(trim(self::get('notifications.provider', 'log')));
        return in_array($provider, ['log','mail','smtp','microsoft_graph'], true) ? $provider : 'log';
    }

    public static function notificationQueueEnabled(): bool
    {
        return self::bool('notifications.queue_enabled', true);
    }

    public static function microsoftGraphConfigured(): bool
    {
        return self::get('microsoft_graph.tenant_id') !== ''
            && self::get('microsoft_graph.client_id') !== ''
            && self::get('microsoft_graph.client_secret') !== ''
            && filter_var(self::get('microsoft_graph.sender_address'), FILTER_VALIDATE_EMAIL) !== false
            && SecretVault::isConfigured();
    }

    /** @return array<string,mixed> */
    public static function notificationConfig(): array
    {
        return [
            'enabled' => self::notificationsEnabled(),
            'provider' => self::notificationProvider(),
            'queue_enabled' => self::notificationQueueEnabled(),
            'max_attempts' => self::int('notifications.max_attempts',5,1,12),
            'retry_minutes' => self::int('notifications.retry_minutes',10,1,1440),
            'graph_configured' => self::microsoftGraphConfigured(),
            'tenant_id' => self::get('microsoft_graph.tenant_id'),
            'client_id' => self::get('microsoft_graph.client_id'),
            'sender_address' => self::get('microsoft_graph.sender_address'),
            'sender_name' => self::get('microsoft_graph.sender_name','SIVI-RNEC'),
            'reply_to' => self::get('microsoft_graph.reply_to'),
            'test_recipient' => self::get('microsoft_graph.test_recipient'),
            'secret_expires_on' => self::get('microsoft_graph.secret_expires_on'),
            'secret_configured' => self::get('microsoft_graph.client_secret') !== '',
            'encryption_key_configured' => SecretVault::isConfigured(),
        ];
    }

    /** @return array<string,mixed> */
    public static function mobileCaptureConfig(): array
    {
        return [
            'enabled' => self::mobileCaptureEnabled(),
            'live_camera' => self::liveCameraEnabled(),
            'image_upload' => self::imageUploadEnabled(),
            'manual_entry' => self::manualEntryEnabled(),
            'session_minutes' => self::mobileSessionMinutes(),
        ];
    }
}
