<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: src/Env.php
 * Propósito: Lee y normaliza las variables de entorno requeridas por la aplicación.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

final class Env
{
    private static array $values = [];

    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $value = self::normalizeValue($value);
            self::$values[$key] = $value;
            if (getenv($key) === false) {
                putenv($key . '=' . $value);
            }
        }
    }

    /**
     * Normaliza valores de configuración o entradas copiadas desde paneles web.
     * Retira BOM, espacios de formato, comillas literales/escapadas que envuelven
     * todo el valor y saltos de línea pegados accidentalmente.
     */
    public static function normalizeValue(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
        $value = preg_replace('/^[\p{Z}\p{Cf}\s]+|[\p{Z}\p{Cf}\s]+$/u', '', $value) ?? trim($value);

        // Algunos paneles o copias incluyen varias capas de comillas.
        for ($i = 0; $i < 3 && strlen($value) >= 2; $i++) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            } elseif (str_starts_with($value, '\\"') && str_ends_with($value, '\\"')) {
                $value = substr($value, 2, -2);
            } elseif (str_starts_with($value, "\\'") && str_ends_with($value, "\\'")) {
                $value = substr($value, 2, -2);
            } else {
                break;
            }
            $value = preg_replace('/^[\p{Z}\p{Cf}\s]+|[\p{Z}\p{Cf}\s]+$/u', '', $value) ?? trim($value);
        }

        return $value;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        /*
         * Docker Secrets / secretos montados en archivos:
         * KEY_FILE=/run/secrets/key tiene prioridad sobre KEY.
         * Esto evita exponer credenciales en `docker inspect`.
         */
        $fileVariable = $key . '_FILE';
        $filePath = getenv($fileVariable);
        if ($filePath === false && array_key_exists($fileVariable, self::$values)) {
            $filePath = self::$values[$fileVariable];
        }
        if ($filePath !== false && trim((string)$filePath) !== '') {
            $path = self::normalizeValue((string)$filePath);
            if (is_file($path) && is_readable($path)) {
                $secret = file_get_contents($path);
                if ($secret !== false) {
                    return self::normalizeValue(rtrim($secret, "\r\n"));
                }
            }
        }

        $value = getenv($key);
        if ($value !== false) {
            return self::normalizeValue((string)$value);
        }
        return self::normalizeValue(self::$values[$key] ?? $default);
    }

    public static function source(string $key): string
    {
        $fileVariable = $key . '_FILE';
        $filePath = getenv($fileVariable);
        if ($filePath === false && array_key_exists($fileVariable, self::$values)) {
            $filePath = self::$values[$fileVariable];
        }
        if ($filePath !== false && trim((string)$filePath) !== '') {
            $path = self::normalizeValue((string)$filePath);
            if (is_file($path) && is_readable($path)) {
                return 'file';
            }
        }
        if (getenv($key) !== false) {
            return 'process';
        }
        if (array_key_exists($key, self::$values)) {
            return '.env';
        }
        return 'default';
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            return $default;
        }
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }
}
