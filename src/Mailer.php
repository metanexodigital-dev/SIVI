<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: src/Mailer.php
 * Propósito: Selecciona el mecanismo de correo y entrega los mensajes generados por SIVI.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

final class Mailer
{
    private static string $lastStatus = 'registrado';
    private static ?int $lastQueueId = null;

    public static function send(
        string $to,
        string $subject,
        string $html,
        ?int $campaignId = null,
        ?int $sedeId = null,
        string $eventKey = 'manual',
        array $cc = [],
        array $bcc = []
    ): bool {
        self::$lastStatus = 'registrado';
        self::$lastQueueId = null;

        if (AppSettings::notificationsEnabled()) {
            $provider = AppSettings::notificationProvider();
            if ($provider === 'microsoft_graph') {
                try {
                    if (!AppSettings::microsoftGraphConfigured()) {
                        throw new RuntimeException('Microsoft Graph está seleccionado, pero la configuración está incompleta.');
                    }
                    if (AppSettings::notificationQueueEnabled()) {
                        self::$lastQueueId = NotificationQueue::enqueue(
                            $to,$subject,$html,$eventKey,$campaignId,$sedeId,$cc,$bcc,Auth::id()
                        );
                        self::$lastStatus = 'encolado';
                        return true;
                    }
                    $delivery = (new MicrosoftGraphClient())->send($to,$subject,$html,$cc,$bcc);
                    Database::execute(
                        "INSERT INTO notifications(campaign_id,sede_id,recipient,subject,event_key,status,error_message,sent_at,provider_request_id) VALUES(?,?,?,?,?,'enviado',NULL,NOW(),?)",
                        [$campaignId,$sedeId,$to,$subject,$eventKey,$delivery['request_id']]
                    );
                    self::$lastStatus = 'enviado';
                    return true;
                } catch (Throwable $e) {
                    self::recordError($to,$subject,$campaignId,$sedeId,$eventKey,$e);
                    return false;
                }
            }
            return self::sendLegacy($provider,$to,$subject,$html,$campaignId,$sedeId,$eventKey);
        }

        $mode = strtolower((string)Env::get('MAIL_MODE', 'log'));
        return self::sendLegacy($mode,$to,$subject,$html,$campaignId,$sedeId,$eventKey);
    }

    public static function lastStatus(): string
    {
        return self::$lastStatus;
    }

    public static function lastQueueId(): ?int
    {
        return self::$lastQueueId;
    }

    private static function sendLegacy(string $mode, string $to, string $subject, string $html, ?int $campaignId, ?int $sedeId, string $eventKey): bool
    {
        $status = 'registrado';
        $error = null;
        try {
            if ($mode === 'smtp') {
                self::smtp($to, $subject, $html);
                $status = 'enviado';
            } elseif ($mode === 'mail') {
                $fromAddress = Env::get('MAIL_FROM_ADDRESS', 'no-reply@localhost');
                $fromName = Env::get('MAIL_FROM_NAME', 'SIVI');
                $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: {$fromName} <{$fromAddress}>\r\n";
                if (!mail($to, $subject, $html, $headers)) {
                    throw new RuntimeException('La función mail() no confirmó el envío.');
                }
                $status = 'enviado';
            } else {
                $dir = dirname(__DIR__) . '/storage/logs';
                if (!is_dir($dir)) @mkdir($dir,0770,true);
                $log = sprintf("[%s] EVENTO: %s | PARA: %s | ASUNTO: %s\n%s\n\n", date('c'), $eventKey, $to, $subject, strip_tags($html));
                file_put_contents($dir . '/mail.log', $log, FILE_APPEND | LOCK_EX);
            }
        } catch (Throwable $e) {
            $status = 'error';
            $reference = log_exception_reference($e, 'mail_delivery');
            $error = safe_error_message('No fue posible enviar la notificación', $reference);
        }
        Database::execute('INSERT INTO notifications (campaign_id,sede_id,recipient,subject,event_key,status,error_message,sent_at) VALUES (?,?,?,?,?,?,?,?)', [
            $campaignId, $sedeId, $to, $subject, $eventKey, $status, $error, $status === 'enviado' ? date('Y-m-d H:i:s') : null,
        ]);
        self::$lastStatus = $status;
        return $status !== 'error';
    }

    private static function recordError(string $to,string $subject,?int $campaignId,?int $sedeId,string $eventKey,Throwable $e): void
    {
        $reference = log_exception_reference($e,'microsoft_graph_delivery');
        $error = mb_substr($e->getMessage() . ' Referencia: ' . $reference,0,2000);
        Database::execute(
            "INSERT INTO notifications(campaign_id,sede_id,recipient,subject,event_key,status,error_message,sent_at) VALUES(?,?,?,?,?,'error',?,NULL)",
            [$campaignId,$sedeId,$to,$subject,$eventKey,$error]
        );
        self::$lastStatus = 'error';
    }

    private static function smtp(string $to, string $subject, string $html): void
    {
        $host = (string)Env::get('SMTP_HOST', '');
        $port = (int)Env::get('SMTP_PORT', '587');
        $encryption = strtolower((string)Env::get('SMTP_ENCRYPTION', 'tls'));
        $username = (string)Env::get('SMTP_USERNAME', '');
        $password = (string)Env::get('SMTP_PASSWORD', '');
        if ($host === '') throw new RuntimeException('SMTP_HOST no está configurado.');
        $transport = $encryption === 'ssl' ? "ssl://{$host}:{$port}" : "tcp://{$host}:{$port}";
        $socket = stream_socket_client($transport, $errno, $errstr, 20);
        if (!$socket) throw new RuntimeException("No fue posible conectar al SMTP: {$errstr}");
        stream_set_timeout($socket, 20);
        self::expect($socket, [220]);
        self::command($socket, "EHLO sivi-rnec", [250]);
        if ($encryption === 'tls') {
            self::command($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('No fue posible activar TLS.');
            }
            self::command($socket, "EHLO sivi-rnec", [250]);
        }
        if ($username !== '') {
            self::command($socket, 'AUTH LOGIN', [334]);
            self::command($socket, base64_encode($username), [334]);
            self::command($socket, base64_encode($password), [235]);
        }
        $from = (string)Env::get('MAIL_FROM_ADDRESS', 'no-reply@localhost');
        $fromName = (string)Env::get('MAIL_FROM_NAME', 'SIVI');
        self::command($socket, "MAIL FROM:<{$from}>", [250]);
        self::command($socket, "RCPT TO:<{$to}>", [250, 251]);
        self::command($socket, 'DATA', [354]);
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $message = "From: {$fromName} <{$from}>\r\nTo: <{$to}>\r\nSubject: {$encodedSubject}\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n{$html}\r\n.";
        self::command($socket, $message, [250]);
        self::command($socket, 'QUIT', [221]);
        fclose($socket);
    }

    private static function command($socket, string $command, array $codes): string
    {
        fwrite($socket, $command . "\r\n");
        return self::expect($socket, $codes);
    }

    private static function expect($socket, array $codes): string
    {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        $code = (int)substr($response, 0, 3);
        if (!in_array($code, $codes, true)) throw new RuntimeException('Respuesta SMTP inesperada: ' . trim($response));
        return $response;
    }
}
