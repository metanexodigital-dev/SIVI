<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: src/Auth.php
 * Propósito: Gestiona autenticación, sesiones, roles, contraseñas y controles de acceso.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

final class LoginRateLimitException extends RuntimeException
{
    public function __construct(private readonly int $retryAfterSeconds)
    {
        parent::__construct('Demasiados intentos de inicio de sesión.');
    }

    public function retryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}

final class Auth
{
    private static ?array $cachedUser = null;
    private const DUMMY_PASSWORD_HASH = '$2y$12$ErL6vT1.ALPmtJDC9VgzlONo2KR76pCIb/IVqQHKDhW7id4AWtYwi';

    public static function attempt(string $email, string $password): bool
    {
        $email = strtolower(trim($email));
        $ip = client_ip();

        $retryAfter = self::retryAfterSeconds($email, $ip);
        if ($retryAfter > 0) {
            throw new LoginRateLimitException($retryAfter);
        }

        $user = Database::fetchOne('SELECT * FROM users WHERE email = ? AND active = 1 LIMIT 1', [$email]);
        $hash = (string)($user['password_hash'] ?? self::DUMMY_PASSWORD_HASH);
        $validPassword = password_verify($password, $hash);

        if (!$user || !$validPassword) {
            self::registerFailure($email, $ip);
            security_event('authentication.failed', [
                'subject_hash' => hash('sha256', $email),
            ], 'warning');
            $retryAfter = self::retryAfterSeconds($email, $ip);
            if ($retryAfter > 0) {
                security_event('authentication.locked', [
                    'subject_hash' => hash('sha256', $email),
                    'retry_after_seconds' => $retryAfter,
                ], 'warning');
                throw new LoginRateLimitException($retryAfter);
            }
            return false;
        }

        self::clearEmailThrottle($email);
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['session_started_at'] = time();
        $_SESSION['session_last_activity_at'] = time();
        $_SESSION['session_last_regenerated_at'] = time();
        Database::execute('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$user['id']]);
        self::$cachedUser = null;
        audit('login', 'user', (int)$user['id'], null, ['email' => $user['email']]);
        return true;
    }

    public static function logout(): void
    {
        $user = self::user();
        if ($user) {
            audit('logout', 'user', (int)$user['id']);
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        self::$cachedUser = null;
    }

    public static function user(): ?array
    {
        if (self::$cachedUser !== null) {
            return self::$cachedUser;
        }
        $id = (int)($_SESSION['user_id'] ?? 0);
        if ($id < 1) {
            return null;
        }
        $user = Database::fetchOne('SELECT u.*, s.identificador AS sede_identificador, s.nombre_sede, s.departamento AS sede_departamento FROM users u LEFT JOIN sedes s ON s.id=u.sede_id WHERE u.id=? AND u.active=1', [$id]);
        if (!$user) {
            unset($_SESSION['user_id']);
            return null;
        }
        $user['departments'] = array_column(Database::fetchAll('SELECT cod_dd FROM user_departments WHERE user_id=? ORDER BY cod_dd', [$id]), 'cod_dd');
        self::$cachedUser = $user;
        return $user;
    }

    /**
     * Identificador del usuario autenticado o null cuando no existe sesión.
     * Centraliza el acceso al ID y evita depender directamente de $_SESSION.
     */
    public static function id(): ?int
    {
        $user = self::user();
        if (!$user || !isset($user['id'])) {
            return null;
        }
        $id = (int)$user['id'];
        return $id > 0 ? $id : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            redirect('login');
        }
    }

    public static function requireRole(array|string $roles): void
    {
        self::requireLogin();
        $roles = (array)$roles;
        $currentRole = (string)self::user()['role'];
        $allowed = in_array($currentRole, $roles, true) || ($currentRole === 'superadmin' && in_array('admin_gi', $roles, true));
        if (!$allowed) {
            http_response_code(403);
            render_error('Acceso denegado', 'No tiene permisos para acceder a esta funcionalidad.');
            exit;
        }
    }

    public static function is(string $role): bool
    {
        $current = (string)(self::user()['role'] ?? '');
        return $current === $role || ($current === 'superadmin' && $role === 'admin_gi');
    }

    public static function mustChangePassword(): bool
    {
        return (bool)((int)(self::user()['must_change_password'] ?? 0));
    }


    public static function enforceSessionPolicy(): ?string
    {
        if (!isset($_SESSION['user_id'])) {
            return null;
        }

        $now = time();
        $idleMinutes = max(5, min(240, (int)(Env::get('SESSION_IDLE_TIMEOUT_MINUTES', '30') ?? '30')));
        $absoluteHours = max(1, min(72, (int)(Env::get('SESSION_ABSOLUTE_TIMEOUT_HOURS', '12') ?? '12')));
        $regenerateMinutes = max(5, min(60, (int)(Env::get('SESSION_REGENERATE_MINUTES', '15') ?? '15')));

        $startedAt = (int)($_SESSION['session_started_at'] ?? $now);
        $lastActivityAt = (int)($_SESSION['session_last_activity_at'] ?? $now);
        $lastRegeneratedAt = (int)($_SESSION['session_last_regenerated_at'] ?? $now);

        if (($now - $lastActivityAt) >= ($idleMinutes * 60)) {
            self::logoutWithReason('session_expired_idle');
            return 'idle';
        }
        if (($now - $startedAt) >= ($absoluteHours * 3600)) {
            self::logoutWithReason('session_expired_absolute');
            return 'absolute';
        }

        if (($now - $lastRegeneratedAt) >= ($regenerateMinutes * 60)) {
            session_regenerate_id(true);
            $_SESSION['session_last_regenerated_at'] = $now;
        }
        $_SESSION['session_last_activity_at'] = $now;
        return null;
    }

    public static function touchSession(): void
    {
        if (isset($_SESSION['user_id'])) {
            $_SESSION['session_last_activity_at'] = time();
        }
    }

    private static function logoutWithReason(string $action): void
    {
        $user = self::user();
        if ($user) {
            audit($action, 'user', (int)$user['id']);
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        self::$cachedUser = null;
    }

    public static function forgetCachedUser(): void
    {
        self::$cachedUser = null;
    }

    private static function retryAfterSeconds(string $email, string $ip): int
    {
        try {
            $emailRetry = self::keyRetryAfter('email', self::keyHash($email));
            $ipRetry = self::keyRetryAfter('ip', self::keyHash($ip));
            return max($emailRetry, $ipRetry);
        } catch (Throwable $exception) {
            // La aplicación debe seguir disponible si la migración todavía no creó
            // la tabla; el detalle queda registrado y el instalador debe ejecutarse.
            log_exception_reference($exception, 'login_rate_limit_check');
            return 0;
        }
    }

    private static function keyRetryAfter(string $type, string $key): int
    {
        $row = Database::fetchOne(
            'SELECT attempts,first_attempt_at,last_attempt_at,locked_until FROM login_throttles WHERE throttle_type=? AND throttle_key=?',
            [$type, $key]
        );
        if (!$row) return 0;

        $now = time();
        $lockedUntil = !empty($row['locked_until']) ? strtotime((string)$row['locked_until']) : false;
        if ($lockedUntil !== false && $lockedUntil > $now) {
            return $lockedUntil - $now;
        }

        $windowSeconds = self::windowMinutes() * 60;
        $firstAttempt = strtotime((string)$row['first_attempt_at']) ?: 0;
        if (($lockedUntil !== false && $lockedUntil <= $now) || $firstAttempt < ($now - $windowSeconds)) {
            Database::execute('DELETE FROM login_throttles WHERE throttle_type=? AND throttle_key=?', [$type, $key]);
        }
        return 0;
    }

    private static function registerFailure(string $email, string $ip): void
    {
        try {
            // Evita crecimiento indefinido de registros antiguos.
            Database::execute('DELETE FROM login_throttles WHERE last_attempt_at < DATE_SUB(NOW(), INTERVAL 7 DAY)');
            self::registerFailureForKey('email', self::keyHash($email), self::emailMaxAttempts());
            self::registerFailureForKey('ip', self::keyHash($ip), self::ipMaxAttempts());
        } catch (Throwable $exception) {
            log_exception_reference($exception, 'login_rate_limit_register');
        }
    }

    private static function registerFailureForKey(string $type, string $key, int $maxAttempts): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $row = Database::fetchOne(
                'SELECT attempts,first_attempt_at,locked_until FROM login_throttles WHERE throttle_type=? AND throttle_key=? FOR UPDATE',
                [$type, $key]
            );
            $now = time();
            $windowSeconds = self::windowMinutes() * 60;
            $reset = !$row;
            if ($row) {
                $firstAttempt = strtotime((string)$row['first_attempt_at']) ?: 0;
                $lockedUntil = !empty($row['locked_until']) ? strtotime((string)$row['locked_until']) : false;
                $reset = $firstAttempt < ($now - $windowSeconds) || ($lockedUntil !== false && $lockedUntil <= $now);
            }

            $attempts = $reset ? 1 : ((int)$row['attempts'] + 1);
            $lockedUntilValue = $attempts >= $maxAttempts
                ? date('Y-m-d H:i:s', $now + self::lockoutMinutes() * 60)
                : null;

            if ($row) {
                Database::execute(
                    'UPDATE login_throttles SET attempts=?,first_attempt_at=IF(?,NOW(),first_attempt_at),last_attempt_at=NOW(),locked_until=? WHERE throttle_type=? AND throttle_key=?',
                    [$attempts, $reset ? 1 : 0, $lockedUntilValue, $type, $key]
                );
            } else {
                Database::execute(
                    'INSERT INTO login_throttles (throttle_type,throttle_key,attempts,first_attempt_at,last_attempt_at,locked_until) VALUES (?,?,1,NOW(),NOW(),?)',
                    [$type, $key, $lockedUntilValue]
                );
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }

    public static function clearLoginThrottleForEmail(string $email): void
    {
        self::clearEmailThrottle($email);
    }

    private static function clearEmailThrottle(string $email): void
    {
        try {
            Database::execute('DELETE FROM login_throttles WHERE throttle_type="email" AND throttle_key=?', [self::keyHash($email)]);
        } catch (Throwable $exception) {
            log_exception_reference($exception, 'login_rate_limit_clear');
        }
    }

    private static function keyHash(string $value): string
    {
        return hash('sha256', strtolower(trim($value)));
    }

    private static function emailMaxAttempts(): int
    {
        return max(3, (int)Env::get('LOGIN_MAX_ATTEMPTS', '5'));
    }

    private static function ipMaxAttempts(): int
    {
        return max(self::emailMaxAttempts(), (int)Env::get('LOGIN_IP_MAX_ATTEMPTS', '25'));
    }

    private static function windowMinutes(): int
    {
        return max(1, (int)Env::get('LOGIN_ATTEMPT_WINDOW_MINUTES', '15'));
    }

    private static function lockoutMinutes(): int
    {
        return max(1, (int)Env::get('LOGIN_LOCKOUT_MINUTES', '15'));
    }
}
