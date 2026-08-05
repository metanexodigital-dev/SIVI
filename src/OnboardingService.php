<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: src/OnboardingService.php
 * Propósito: Gestiona el progreso y persistencia del recorrido guiado.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

final class OnboardingService
{
    public const DEFAULT_TOUR_KEY = 'sivi-first-login-v1';
    private const VALID_STATUSES = ['started', 'in_progress', 'completed', 'skipped'];

    public static function enabled(): bool
    {
        $value = strtolower(trim((string) (getenv('ONBOARDING_ENABLED') ?: 'true')));
        return !in_array($value, ['0', 'false', 'no', 'off'], true);
    }

    public static function tourKey(): string
    {
        $value = trim((string) (getenv('ONBOARDING_TOUR_KEY') ?: self::DEFAULT_TOUR_KEY));
        return preg_match('/^[a-zA-Z0-9._-]{3,100}$/', $value) ? $value : self::DEFAULT_TOUR_KEY;
    }

    public static function ensureSchema(PDO $pdo): void
    {
        // La tabla se crea únicamente durante la inicialización oficial de MySQL.
    }

    public static function get(PDO $pdo, int $userId, ?string $tourKey = null): array
    {
        self::ensureSchema($pdo);
        $tourKey = $tourKey ?: self::tourKey();
        $statement = $pdo->prepare(<<<'SQL'
SELECT status, last_step, started_at, completed_at, skipped_at, updated_at
FROM sivi_user_onboarding
WHERE user_id = :user_id AND tour_key = :tour_key
LIMIT 1
SQL);
        $statement->execute([':user_id' => $userId, ':tour_key' => $tourKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return [
                'exists' => false,
                'status' => null,
                'last_step' => 0,
                'should_show' => true,
            ];
        }

        $status = (string) ($row['status'] ?? '');
        return [
            'exists' => true,
            'status' => $status,
            'last_step' => (int) ($row['last_step'] ?? 0),
            'started_at' => $row['started_at'] ?? null,
            'completed_at' => $row['completed_at'] ?? null,
            'skipped_at' => $row['skipped_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            'should_show' => !in_array($status, ['completed', 'skipped'], true),
        ];
    }

    public static function save(PDO $pdo, int $userId, string $status, int $lastStep = 0, ?string $tourKey = null): void
    {
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new InvalidArgumentException('Estado de recorrido no válido.');
        }

        self::ensureSchema($pdo);
        $tourKey = $tourKey ?: self::tourKey();
        $lastStep = max(0, min(99, $lastStep));
        $completedAt = $status === 'completed' ? date('Y-m-d H:i:s') : null;
        $skippedAt = $status === 'skipped' ? date('Y-m-d H:i:s') : null;

        $statement = $pdo->prepare(<<<'SQL'
INSERT INTO sivi_user_onboarding
    (user_id, tour_key, status, last_step, started_at, completed_at, skipped_at)
VALUES
    (:user_id, :tour_key, :status, :last_step, CURRENT_TIMESTAMP, :completed_at, :skipped_at)
ON DUPLICATE KEY UPDATE
    status = VALUES(status),
    last_step = GREATEST(last_step, VALUES(last_step)),
    started_at = COALESCE(started_at, CURRENT_TIMESTAMP),
    completed_at = CASE WHEN VALUES(status) = 'completed' THEN VALUES(completed_at) ELSE completed_at END,
    skipped_at = CASE WHEN VALUES(status) = 'skipped' THEN VALUES(skipped_at) ELSE skipped_at END,
    updated_at = CURRENT_TIMESTAMP
SQL);
        $statement->execute([
            ':user_id' => $userId,
            ':tour_key' => $tourKey,
            ':status' => $status,
            ':last_step' => $lastStep,
            ':completed_at' => $completedAt,
            ':skipped_at' => $skippedAt,
        ]);
    }
}
