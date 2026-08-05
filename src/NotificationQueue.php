<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: src/NotificationQueue.php
 * Propósito: Administra la cola, reintentos y estados de las notificaciones pendientes.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

final class NotificationQueue
{
    public static function enqueue(
        string $recipient,
        string $subject,
        string $html,
        string $eventKey = 'manual',
        ?int $campaignId = null,
        ?int $sedeId = null,
        array $cc = [],
        array $bcc = [],
        ?int $createdBy = null
    ): int {
        $recipient = strtolower(trim($recipient));
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('El destinatario no es válido.');
        $maxAttempts = AppSettings::int('notifications.max_attempts', 5, 1, 12);
        $pdo = Database::connection();
        $started = !$pdo->inTransaction();
        if ($started) $pdo->beginTransaction();
        try {
            Database::execute(
                "INSERT INTO notifications(campaign_id,sede_id,recipient,subject,status,error_message,sent_at) VALUES(?,?,?,?,'encolado',NULL,NULL)",
                [$campaignId,$sedeId,$recipient,mb_substr($subject,0,255)]
            );
            $notificationId = (int)$pdo->lastInsertId();
            Database::execute(
                "INSERT INTO notification_queue(notification_id,event_key,campaign_id,sede_id,recipient,cc_json,bcc_json,subject,html_body,status,attempts,max_attempts,next_attempt_at,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,'pendiente',0,?,NOW(),?,NOW(),NOW())",
                [
                    $notificationId,$eventKey,$campaignId,$sedeId,$recipient,
                    json_encode(array_values($cc),JSON_UNESCAPED_UNICODE),
                    json_encode(array_values($bcc),JSON_UNESCAPED_UNICODE),
                    mb_substr($subject,0,255),$html,$maxAttempts,$createdBy,
                ]
            );
            $queueId = (int)$pdo->lastInsertId();
            Database::execute('UPDATE notifications SET queue_id=?,event_key=? WHERE id=?',[$queueId,$eventKey,$notificationId]);
            if ($started) $pdo->commit();
            return $queueId;
        } catch (Throwable $e) {
            if ($started && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** @return array{processed:int,sent:int,failed:int,skipped:int} */
    public static function processBatch(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        if (!AppSettings::notificationsEnabled() || AppSettings::notificationProvider() !== 'microsoft_graph') {
            return ['processed'=>0,'sent'=>0,'failed'=>0,'skipped'=>0];
        }
        $rows = Database::fetchAll(
            "SELECT * FROM notification_queue WHERE status IN ('pendiente','error') AND attempts<max_attempts AND (next_attempt_at IS NULL OR next_attempt_at<=NOW()) ORDER BY id LIMIT {$limit}"
        );
        $result = ['processed'=>0,'sent'=>0,'failed'=>0,'skipped'=>0];
        foreach ($rows as $row) {
            $claimed = Database::execute(
                "UPDATE notification_queue SET status='procesando',locked_at=NOW(),locked_by=?,updated_at=NOW() WHERE id=? AND status IN ('pendiente','error')",
                [gethostname() ?: 'sivi-worker',(int)$row['id']]
            );
            if ($claimed < 1) { $result['skipped']++; continue; }
            $result['processed']++;
            try {
                $client = new MicrosoftGraphClient();
                $delivery = $client->send(
                    (string)$row['recipient'],
                    (string)$row['subject'],
                    (string)$row['html_body'],
                    self::decodeRecipients((string)($row['cc_json'] ?? '[]')),
                    self::decodeRecipients((string)($row['bcc_json'] ?? '[]'))
                );
                Database::execute(
                    "UPDATE notification_queue SET status='enviado',attempts=attempts+1,last_error=NULL,last_http_status=?,provider_request_id=?,client_request_id=?,sent_at=NOW(),processed_at=NOW(),locked_at=NULL,locked_by=NULL,updated_at=NOW() WHERE id=?",
                    [$delivery['http_status'],$delivery['request_id'],$delivery['client_request_id'],(int)$row['id']]
                );
                Database::execute("UPDATE notifications SET status='enviado',error_message=NULL,sent_at=NOW() WHERE id=?",[(int)$row['notification_id']]);
                self::syncCampaignSedeStatus($row, 'enviada', null);
                $result['sent']++;
            } catch (Throwable $e) {
                $attempt = (int)$row['attempts'] + 1;
                $max = (int)$row['max_attempts'];
                $retryMinutes = AppSettings::int('notifications.retry_minutes', 10, 1, 1440);
                $next = $attempt >= $max ? null : date('Y-m-d H:i:s', time() + ($retryMinutes * 60 * max(1,$attempt)));
                $reference = log_exception_reference($e, 'notification_queue_send');
                $message = mb_substr($e->getMessage() . ' Referencia: ' . $reference,0,2000);
                Database::execute(
                    "UPDATE notification_queue SET status='error',attempts=?,last_error=?,next_attempt_at=?,processed_at=NOW(),locked_at=NULL,locked_by=NULL,updated_at=NOW() WHERE id=?",
                    [$attempt,$message,$next,(int)$row['id']]
                );
                Database::execute("UPDATE notifications SET status='error',error_message=? WHERE id=?",[$message,(int)$row['notification_id']]);
                self::syncCampaignSedeStatus($row, 'error', $message);
                $result['failed']++;
            }
        }
        return $result;
    }

    public static function retry(int $queueId): void
    {
        Database::execute("UPDATE notification_queue SET status='pendiente',attempts=0,next_attempt_at=NOW(),last_error=NULL,locked_at=NULL,locked_by=NULL,updated_at=NOW() WHERE id=?",[$queueId]);
        Database::execute("UPDATE notifications n JOIN notification_queue q ON q.notification_id=n.id SET n.status='encolado',n.error_message=NULL WHERE q.id=?",[$queueId]);
    }

    /** @return array<string,int> */
    public static function stats(): array
    {
        $row = Database::fetchOne("SELECT COUNT(*) total,SUM(status='pendiente') pendientes,SUM(status='procesando') procesando,SUM(status='enviado') enviados,SUM(status='error') errores FROM notification_queue") ?: [];
        return [
            'total'=>(int)($row['total']??0),
            'pendientes'=>(int)($row['pendientes']??0),
            'procesando'=>(int)($row['procesando']??0),
            'enviados'=>(int)($row['enviados']??0),
            'errores'=>(int)($row['errores']??0),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public static function recent(int $limit = 100): array
    {
        $limit = max(1,min(250,$limit));
        return Database::fetchAll("SELECT q.*,c.name campaign_name,s.identificador,s.nombre_sede,u.name created_name FROM notification_queue q LEFT JOIN campaigns c ON c.id=q.campaign_id LEFT JOIN sedes s ON s.id=q.sede_id LEFT JOIN users u ON u.id=q.created_by ORDER BY q.id DESC LIMIT {$limit}");
    }

    /** @return array<int,string> */
    private static function decodeRecipients(string $json): array
    {
        $data = json_decode($json,true);
        if (!is_array($data)) return [];
        return array_values(array_filter(array_map('strval',$data),static fn(string $v): bool => filter_var($v,FILTER_VALIDATE_EMAIL)!==false));
    }

    /** @param array<string,mixed> $row */
    private static function syncCampaignSedeStatus(array $row, string $status, ?string $error): void
    {
        if ((string)($row['event_key'] ?? '') !== 'campaign_published' || empty($row['campaign_id']) || empty($row['sede_id'])) return;
        Database::execute('UPDATE campaign_sedes SET notification_status=?,notification_error=?,notified_at=IF(?=\'enviada\',NOW(),notified_at) WHERE campaign_id=? AND sede_id=?',[
            $status,$error,$status,(int)$row['campaign_id'],(int)$row['sede_id']
        ]);
    }
}
