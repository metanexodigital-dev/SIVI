<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: src/PlatePolicy.php
 * Propósito: Centraliza la configuración, formato, normalización y validación de la Placa RNEC.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

final class PlatePolicy
{
    private static ?int $requestTotalCharacters = null;
    private static bool $schemaEnsured = false;

    public const SETTING_KEY = 'plate_rnec_total_characters';
    public const LEGACY_SETTING_KEY = 'plate_rnec_digits';
    public const DEFAULT_TOTAL_CHARACTERS = 9;
    public const MIN_TOTAL_CHARACTERS = 5;
    public const MAX_TOTAL_CHARACTERS = 21;
    public const PREFIX_DIGITS = 3;
    public const REQUIRED_PREFIX = '000';

    /**
     * Inicializa la fila de configuración. La tabla forma parte del esquema
     * oficial y no se crean objetos DDL desde la cuenta de la aplicación.
     */
    public static function ensureSchema(PDO $pdo): void
    {
        $legacyDigits = self::legacyDigits($pdo);
        $defaultTotal = self::clampTotalCharacters($legacyDigits !== null ? $legacyDigits + 1 : self::defaultTotalCharacters());

        $statement = $pdo->prepare(<<<'SQL'
INSERT INTO sivi_runtime_settings
    (setting_key, setting_value, setting_type, description, updated_by)
VALUES
    (:key, :value, 'integer', :description, NULL)
ON DUPLICATE KEY UPDATE
    description = VALUES(description),
    setting_type = VALUES(setting_type)
SQL);
        $statement->execute([
            ':key' => self::SETTING_KEY,
            ':value' => (string) $defaultTotal,
            ':description' => 'Cantidad total de caracteres de la Placa RNEC, incluido el guion después de los primeros tres números',
        ]);
        self::$schemaEnsured = true;
    }

    /** Obtiene la longitud predeterminada desde las variables de entorno. */
    public static function defaultTotalCharacters(): int
    {
        $configured = getenv('PLATE_RNEC_TOTAL_CHARACTERS');
        if ($configured !== false && trim((string) $configured) !== '') {
            return self::clampTotalCharacters((int) $configured);
        }

        $legacy = getenv('PLATE_RNEC_DIGITS');
        if ($legacy !== false && trim((string) $legacy) !== '') {
            return self::clampTotalCharacters(((int) $legacy) + 1);
        }

        return self::DEFAULT_TOTAL_CHARACTERS;
    }

    /** Devuelve la longitud vigente, priorizando la configuración guardada por el administrador. */
    public static function totalCharacters(?PDO $pdo = null): int
    {
        if (!$pdo) {
            return self::defaultTotalCharacters();
        }
        if (self::$requestTotalCharacters !== null) {
            return self::$requestTotalCharacters;
        }

        try {
            /*
             * En una instalación ya preparada la tabla existe. Primero se lee
             * directamente y solo se ejecuta ensureSchema cuando realmente falta.
             */
            $statement = $pdo->prepare(
                'SELECT setting_value FROM sivi_runtime_settings '
                . 'WHERE setting_key = :key LIMIT 1'
            );
            $statement->execute([':key' => self::SETTING_KEY]);
            $value = $statement->fetchColumn();

            if ($value === false || trim((string)$value) === '') {
                if (!self::$schemaEnsured) {
                    self::ensureSchema($pdo);
                }
                $statement->execute([':key' => self::SETTING_KEY]);
                $value = $statement->fetchColumn();
            }

            self::$requestTotalCharacters = self::clampTotalCharacters(
                (int)$value
            );
            return self::$requestTotalCharacters;
        } catch (Throwable) {
            try {
                if (!self::$schemaEnsured) {
                    self::ensureSchema($pdo);
                    return self::totalCharacters($pdo);
                }
            } catch (Throwable) {
                // Mantener compatibilidad con instalaciones aún no preparadas.
            }
            self::$requestTotalCharacters = self::defaultTotalCharacters();
            return self::$requestTotalCharacters;
        }
    }

    /** Compatibilidad con integraciones de 0.0.0.46: devuelve solo la cantidad de números. */
    public static function digits(?PDO $pdo = null): int
    {
        return self::digitCount(self::totalCharacters($pdo));
    }

    /** Calcula la cantidad de números esperada al descontar el guion. */
    public static function digitCount(int $totalCharacters): int
    {
        return self::clampTotalCharacters($totalCharacters) - 1;
    }

    /** Calcula cuántos números deben aparecer después del prefijo 000-. */
    public static function suffixDigits(int $totalCharacters): int
    {
        return self::digitCount($totalCharacters) - self::PREFIX_DIGITS;
    }

    /** Guarda la longitud definida por el administrador y registra quién realizó el cambio. */
    public static function saveTotalCharacters(PDO $pdo, int $totalCharacters, ?int $userId): void
    {
        $totalCharacters = self::clampTotalCharacters($totalCharacters);
        self::ensureSchema($pdo);
        $statement = $pdo->prepare(<<<'SQL'
INSERT INTO sivi_runtime_settings
    (setting_key, setting_value, setting_type, description, updated_by)
VALUES
    (:key, :value, 'integer', :description, :updated_by)
ON DUPLICATE KEY UPDATE
    setting_value = VALUES(setting_value),
    setting_type = VALUES(setting_type),
    description = VALUES(description),
    updated_by = VALUES(updated_by),
    updated_at = CURRENT_TIMESTAMP
SQL);
        $statement->execute([
            ':key' => self::SETTING_KEY,
            ':value' => (string) $totalCharacters,
            ':description' => 'Cantidad total de caracteres de la Placa RNEC, incluido el guion después de los primeros tres números',
            ':updated_by' => $userId,
        ]);
        self::$requestTotalCharacters = $totalCharacters;
        self::$schemaEnsured = true;
    }

    /** Compatibilidad con código anterior que guardaba la cantidad de números. */
    public static function saveDigits(PDO $pdo, int $digits, ?int $userId): void
    {
        self::saveTotalCharacters($pdo, $digits + 1, $userId);
    }

    /** Conserva únicamente los números para comparar, validar y normalizar el valor ingresado. */
    public static function normalizeDigits(?string $value): string
    {
        return preg_replace('/\D+/', '', trim((string) $value)) ?? '';
    }

    /** Devuelve la placa con el formato 000- seguido de la cantidad configurada de números. */
    public static function format(?string $value, int $totalCharacters): string
    {
        $digitCount = self::digitCount($totalCharacters);
        $digits = substr(self::normalizeDigits($value), 0, $digitCount);
        if (strlen($digits) < self::PREFIX_DIGITS) {
            return $digits;
        }

        if (substr($digits, 0, self::PREFIX_DIGITS) !== self::REQUIRED_PREFIX) {
            return $digits;
        }

        return self::REQUIRED_PREFIX
            . '-'
            . substr($digits, self::PREFIX_DIGITS);
    }

    /** Genera un ejemplo dinámico que ayuda al usuario a diligenciar la placa. */
    public static function example(int $totalCharacters): string
    {
        $suffix = self::suffixDigits($totalCharacters);
        $sequence = str_repeat('1234567890', 3);
        return self::REQUIRED_PREFIX . '-' . substr($sequence, 0, $suffix);
    }

    /** Valida obligatoriedad, longitud, prefijo 000 y formato final antes de guardar. */
    public static function validate(?string $value, int $totalCharacters, bool $required = true): array
    {
        $totalCharacters = self::clampTotalCharacters($totalCharacters);
        $raw = trim((string) $value);
        if ($raw === '') {
            return $required
                ? ['ok' => false, 'value' => '', 'message' => 'Debe ingresar la Placa RNEC.']
                : ['ok' => true, 'value' => '', 'message' => null];
        }

        $digitCount = self::digitCount($totalCharacters);
        $digits = self::normalizeDigits($raw);

        if (strlen($digits) >= self::PREFIX_DIGITS
            && substr($digits, 0, self::PREFIX_DIGITS) !== self::REQUIRED_PREFIX) {
            return [
                'ok' => false,
                'value' => self::format($raw, $totalCharacters),
                'message' => 'La Placa RNEC debe iniciar con 000 antes del guion.',
            ];
        }

        if (strlen($digits) !== $digitCount) {
            return [
                'ok' => false,
                'value' => self::format($raw, $totalCharacters),
                'message' => sprintf(
                    'La Placa RNEC debe tener %d caracteres en total: %d números y un guion. Ejemplo: %s.',
                    $totalCharacters,
                    $digitCount,
                    self::example($totalCharacters)
                ),
            ];
        }

        $formatted = self::format($raw, $totalCharacters);
        $suffix = self::suffixDigits($totalCharacters);
        $pattern = '/^' . preg_quote(self::REQUIRED_PREFIX, '/') . '-\d{' . $suffix . '}$/';
        if (strlen($formatted) !== $totalCharacters || preg_match($pattern, $formatted) !== 1) {
            return [
                'ok' => false,
                'value' => $formatted,
                'message' => sprintf('Use el formato %s.', self::example($totalCharacters)),
            ];
        }

        return ['ok' => true, 'value' => $formatted, 'message' => null];
    }

    /** Normaliza y valida los campos de placa recibidos en una solicitud POST. */
    public static function applyToCurrentRequest(?PDO $pdo = null): array
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            return [];
        }

        $totalCharacters = self::totalCharacters($pdo);
        $errors = [];
        $fields = ['placa_rnec', 'verified_plate', 'plate_rnec'];

        foreach ($fields as $field) {
            if (!array_key_exists($field, $_POST)) {
                continue;
            }

            $raw = is_scalar($_POST[$field]) ? (string) $_POST[$field] : '';
            if (trim($raw) === '') {
                continue; // El flujo existente decide cuándo la placa es obligatoria.
            }

            $result = self::validate($raw, $totalCharacters, true);
            $_POST[$field] = $result['value'];
            if (!$result['ok']) {
                $errors[$field] = $result['message'];
            }
        }

        return $errors;
    }

    /** Lee la configuración de versiones anteriores para conservar compatibilidad. */
    private static function legacyDigits(PDO $pdo): ?int
    {
        try {
            $statement = $pdo->prepare('SELECT setting_value FROM sivi_runtime_settings WHERE setting_key = :key LIMIT 1');
            $statement->execute([':key' => self::LEGACY_SETTING_KEY]);
            $value = $statement->fetchColumn();
            return $value === false ? null : max(1, min(20, (int) $value));
        } catch (Throwable) {
            return null;
        }
    }

    /** Mantiene la longitud dentro de los límites seguros permitidos por la aplicación. */
    private static function clampTotalCharacters(int $totalCharacters): int
    {
        return max(self::MIN_TOTAL_CHARACTERS, min(self::MAX_TOTAL_CHARACTERS, $totalCharacters));
    }
}
