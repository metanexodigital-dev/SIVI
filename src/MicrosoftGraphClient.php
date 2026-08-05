<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: src/MicrosoftGraphClient.php
 * Propósito: Realiza la autenticación y envío de correo mediante Microsoft Graph.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

final class MicrosoftGraphClient
{
    private string $tenantId;
    private string $clientId;
    private string $clientSecret;
    private string $senderAddress;
    private string $senderName;
    private string $replyTo;

    public function __construct()
    {
        $this->tenantId = trim(AppSettings::get('microsoft_graph.tenant_id'));
        $this->clientId = trim(AppSettings::get('microsoft_graph.client_id'));
        $encryptedSecret = AppSettings::get('microsoft_graph.client_secret');
        $this->clientSecret = $encryptedSecret === '' ? '' : SecretVault::decrypt($encryptedSecret);
        $this->senderAddress = strtolower(trim(AppSettings::get('microsoft_graph.sender_address')));
        $this->senderName = trim(AppSettings::get('microsoft_graph.sender_name', 'SIVI-RNEC'));
        $this->replyTo = strtolower(trim(AppSettings::get('microsoft_graph.reply_to')));
        $this->assertConfigured();
    }

    /** @return array{ok:bool,tenant_id:string,client_id:string,sender:string,roles:array<int,string>,expires_in:int} */
    public function testConnection(): array
    {
        $token = $this->accessToken();
        $claims = self::decodeJwtPayload($token['access_token']);
        $roles = array_values(array_filter(array_map('strval', is_array($claims['roles'] ?? null) ? $claims['roles'] : [])));
        if (!in_array('Mail.Send', $roles, true)) {
            throw new RuntimeException('El token fue emitido, pero no contiene el permiso de aplicación Mail.Send. Conceda consentimiento de administrador en Microsoft Entra.');
        }
        return [
            'ok' => true,
            'tenant_id' => $this->tenantId,
            'client_id' => $this->clientId,
            'sender' => $this->senderAddress,
            'roles' => $roles,
            'expires_in' => (int)($token['expires_in'] ?? 0),
        ];
    }

    /** @return array{http_status:int,request_id:string,client_request_id:string} */
    public function send(string $to, string $subject, string $html, array $cc = [], array $bcc = []): array
    {
        $toRecipients = self::recipientList([$to]);
        if ($toRecipients === []) throw new InvalidArgumentException('El destinatario principal no es válido.');
        $token = $this->accessToken();
        $clientRequestId = self::uuidV4();
        $message = [
            'subject' => mb_substr(trim($subject), 0, 255),
            'body' => ['contentType' => 'HTML', 'content' => $html],
            'toRecipients' => $toRecipients,
            'ccRecipients' => self::recipientList($cc),
            'bccRecipients' => self::recipientList($bcc),
        ];
        if (filter_var($this->replyTo, FILTER_VALIDATE_EMAIL)) {
            $message['replyTo'] = [['emailAddress' => ['address' => $this->replyTo]]];
        }
        $payload = json_encode(['message' => $message, 'saveToSentItems' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $endpoint = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($this->senderAddress) . '/sendMail';
        $response = $this->request('POST', $endpoint, [
            'Authorization: Bearer ' . $token['access_token'],
            'Content-Type: application/json',
            'Accept: application/json',
            'client-request-id: ' . $clientRequestId,
            'return-client-request-id: true',
        ], $payload);
        if ($response['status'] !== 202) {
            $detail = self::graphErrorMessage($response['body']);
            throw new RuntimeException('Microsoft Graph rechazó el envío (HTTP ' . $response['status'] . '): ' . $detail);
        }
        return [
            'http_status' => $response['status'],
            'request_id' => (string)($response['headers']['request-id'] ?? ''),
            'client_request_id' => $clientRequestId,
        ];
    }

    /** @return array{access_token:string,expires_in:int,token_type:string} */
    private function accessToken(): array
    {
        $endpoint = 'https://login.microsoftonline.com/' . rawurlencode($this->tenantId) . '/oauth2/v2.0/token';
        $body = http_build_query([
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
        ], '', '&', PHP_QUERY_RFC3986);
        $response = $this->request('POST', $endpoint, [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ], $body);
        $data = json_decode($response['body'], true);
        if ($response['status'] !== 200 || !is_array($data) || empty($data['access_token'])) {
            $description = is_array($data) ? trim((string)($data['error_description'] ?? $data['error'] ?? 'Respuesta no válida')) : 'Respuesta no válida';
            throw new RuntimeException('No fue posible obtener el token de Microsoft Entra (HTTP ' . $response['status'] . '): ' . mb_substr($description, 0, 500));
        }
        return [
            'access_token' => (string)$data['access_token'],
            'expires_in' => (int)($data['expires_in'] ?? 0),
            'token_type' => (string)($data['token_type'] ?? 'Bearer'),
        ];
    }

    /** @return array{status:int,body:string,headers:array<string,string>} */
    private function request(string $method, string $url, array $headers, string $body): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('La extensión PHP cURL no está disponible. Reconstruya la imagen de SIVI 0.0.0.49.');
        }
        $responseHeaders = [];
        $curl = curl_init($url);
        if ($curl === false) throw new RuntimeException('No fue posible inicializar la conexión HTTPS.');
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'SIVI/' . AppVersion::package(),
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $length;
            },
        ]);
        $responseBody = curl_exec($curl);
        if ($responseBody === false) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new RuntimeException('No fue posible conectar con Microsoft 365: ' . $error);
        }
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        return ['status' => $status, 'body' => (string)$responseBody, 'headers' => $responseHeaders];
    }

    private function assertConfigured(): void
    {
        if (!preg_match('/^[0-9a-f-]{36}$/i', $this->tenantId)) throw new RuntimeException('Tenant ID no está configurado o no tiene un formato válido.');
        if (!preg_match('/^[0-9a-f-]{36}$/i', $this->clientId)) throw new RuntimeException('Client ID no está configurado o no tiene un formato válido.');
        if ($this->clientSecret === '') throw new RuntimeException('El secreto de cliente no está configurado.');
        if (!filter_var($this->senderAddress, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('El buzón remitente no es válido.');
    }

    /** @param array<int|string,mixed> $addresses @return array<int,array{emailAddress:array{address:string}}> */
    private static function recipientList(array $addresses): array
    {
        $result = [];
        $seen = [];
        foreach ($addresses as $address) {
            $email = strtolower(trim((string)$address));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || isset($seen[$email])) continue;
            $seen[$email] = true;
            $result[] = ['emailAddress' => ['address' => $email]];
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private static function decodeJwtPayload(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) < 2) return [];
        $payload = strtr($parts[1], '-_', '+/');
        $padding = strlen($payload) % 4;
        if ($padding) $payload .= str_repeat('=', 4 - $padding);
        $decoded = base64_decode($payload, true);
        if ($decoded === false) return [];
        $claims = json_decode($decoded, true);
        return is_array($claims) ? $claims : [];
    }

    private static function graphErrorMessage(string $body): string
    {
        $data = json_decode($body, true);
        if (is_array($data)) {
            $message = trim((string)($data['error']['message'] ?? $data['error_description'] ?? ''));
            if ($message !== '') return mb_substr($message, 0, 500);
        }
        return mb_substr(trim(strip_tags($body)) ?: 'Sin detalle', 0, 500);
    }

    private static function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
