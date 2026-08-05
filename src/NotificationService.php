<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: src/NotificationService.php
 * Propósito: Coordina la creación y envío de notificaciones de la aplicación.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

final class NotificationService
{
    /** @param array<string,mixed> $variables */
    public static function sendTemplate(
        string $templateKey,
        string $recipient,
        array $variables,
        ?int $campaignId = null,
        ?int $sedeId = null,
        array $cc = [],
        array $bcc = []
    ): bool {
        $rendered = NotificationTemplate::render($templateKey, $variables);
        return Mailer::send($recipient,$rendered['subject'],$rendered['html'],$campaignId,$sedeId,$templateKey,$cc,$bcc);
    }

    public static function appUrl(string $page, array $params = []): string
    {
        $base = rtrim((string)Env::get('APP_URL',''),'/');
        return $base . '/index.php?' . http_build_query(['page'=>$page] + $params);
    }

    /** @return array<string,mixed>|null */
    public static function sedeContact(int $campaignId, int $sedeId): ?array
    {
        return Database::fetchOne(
            "SELECT s.id,s.identificador,s.nombre_sede,s.departamento,s.municipio,s.email_contacto,s.email_institucional,cs.responsible_name,cs.responsible_email,(SELECT u.email FROM users u WHERE u.sede_id=s.id AND u.role='registrador' AND u.active=1 ORDER BY u.id LIMIT 1) user_email,(SELECT u.name FROM users u WHERE u.sede_id=s.id AND u.role='registrador' AND u.active=1 ORDER BY u.id LIMIT 1) user_name FROM sedes s JOIN campaign_sedes cs ON cs.sede_id=s.id AND cs.campaign_id=? WHERE s.id=?",
            [$campaignId,$sedeId]
        ) ?: null;
    }

    public static function contactEmail(array $contact): string
    {
        foreach (['responsible_email','email_contacto','email_institucional','user_email'] as $key) {
            $email = strtolower(trim((string)($contact[$key] ?? '')));
            if (filter_var($email,FILTER_VALIDATE_EMAIL)) return $email;
        }
        return '';
    }

    public static function userEmail(int $userId): string
    {
        if ($userId < 1) return '';
        $row = Database::fetchOne('SELECT email FROM users WHERE id=? AND active=1 LIMIT 1', [$userId]);
        $email = strtolower(trim((string)($row['email'] ?? '')));
        return filter_var($email,FILTER_VALIDATE_EMAIL) ? $email : '';
    }
}
