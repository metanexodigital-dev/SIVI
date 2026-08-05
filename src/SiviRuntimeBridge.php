<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: src/SiviRuntimeBridge.php
 * Propósito: Mantiene compatibilidad entre componentes y mecanismos de ejecución de distintas versiones.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

final class SiviRuntimeBridge
{
    public static function startSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
            session_start();
        }
    }

    public static function pdo(): PDO
    {
        $root = dirname(__DIR__);
        if (!class_exists('Env')) {
            require_once $root . '/src/Env.php';
            Env::load($root . '/.env');
        }
        if (!class_exists('Database')) {
            require_once $root . '/src/Database.php';
        }

        // Reutiliza la conexión oficial para conservar Docker Secrets y TLS.
        return Database::connection();
    }

    public static function currentUser(): array
    {
        self::startSession();

        if (class_exists('Auth')) {
            try {
                $user = Auth::user();
                if (is_array($user)) return $user;
            } catch (Throwable) {
                // Continúa con mecanismos de compatibilidad.
            }
        }

        foreach (['current_user', 'auth_user', 'authenticated_user'] as $function) {
            if (function_exists($function)) {
                try {
                    $user = $function();
                    if (is_array($user)) {
                        return $user;
                    }
                } catch (Throwable) {
                    // Continúa con las alternativas de sesión.
                }
            }
        }

        foreach (['user', 'auth_user', 'authenticated_user'] as $key) {
            if (isset($_SESSION[$key]) && is_array($_SESSION[$key])) {
                return $_SESSION[$key];
            }
        }

        return [];
    }

    public static function role(): string
    {
        $user = self::currentUser();
        $role = $user['role'] ?? $user['rol'] ?? $user['role_code'] ?? $user['perfil']
            ?? $_SESSION['role'] ?? $_SESSION['rol'] ?? $_SESSION['user_role'] ?? '';

        if (is_array($role)) {
            $role = $role['code'] ?? $role['name'] ?? $role['nombre'] ?? '';
        }

        return strtolower(trim((string) $role));
    }

    public static function isAdmin(): bool
    {
        foreach (['is_admin', 'isAdmin', 'is_superadmin'] as $function) {
            if (function_exists($function)) {
                try {
                    if ((bool) $function()) {
                        return true;
                    }
                } catch (Throwable) {
                    // Continúa con la validación por rol.
                }
            }
        }

        $normalized = preg_replace('/[^a-z0-9]+/', '_', self::role()) ?: '';
        return in_array($normalized, [
            'admin', 'administrador', 'admin_gi', 'administrador_gi',
            'superadmin', 'super_admin', 'administrador_general'
        ], true);
    }

    public static function userId(): ?int
    {
        $user = self::currentUser();
        $id = $user['id'] ?? $user['user_id'] ?? $user['usuario_id'] ?? $_SESSION['user_id'] ?? null;
        return is_numeric($id) ? (int) $id : null;
    }

    public static function csrfToken(): string
    {
        self::startSession();
        if (empty($_SESSION['sivi_plate_policy_csrf'])) {
            $_SESSION['sivi_plate_policy_csrf'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['sivi_plate_policy_csrf'];
    }

    public static function verifyCsrf(string $token): bool
    {
        self::startSession();
        $stored = (string) ($_SESSION['sivi_plate_policy_csrf'] ?? '');
        return $stored !== '' && hash_equals($stored, $token);
    }

    public static function wantsJson(): bool
    {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        return str_contains($accept, 'application/json') || $requestedWith === 'xmlhttprequest';
    }
}
