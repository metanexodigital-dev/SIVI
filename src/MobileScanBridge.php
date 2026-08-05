<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: src/MobileScanBridge.php
 * Propósito: Conecta temporalmente el navegador del computador con el lector móvil.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

/**
 * Conexión temporal entre un formulario abierto en el computador y un celular.
 * Permite lectura en vivo, fotografía/galería, reconocimiento OCR y confirmación
 * de recepción sin exponer el inventario completo en el dispositivo móvil.
 */
final class MobileScanBridge
{
    public const DEFAULT_TTL_SECONDS = 600;
    private const TARGETS = ['serial_number', 'placa_rnec'];

    /** @return array{token:string,pairing_code:string,expires_at:string,expires_in:int} */
    public static function start(int $userId, int $campaignId = 0, int $sedeId = 0): array
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('Usuario inválido para iniciar la conexión móvil.');
        }

        self::cleanup();
        Database::execute(
            "UPDATE mobile_scan_sessions SET status='cancelled',updated_at=NOW() WHERE user_id=? AND status='active'",
            [$userId]
        );

        $token = bin2hex(random_bytes(32));
        $pairingCode = (string)random_int(100000, 999999);
        $ttlSeconds = self::ttlSeconds();
        $expiresAt = date('Y-m-d H:i:s', time() + $ttlSeconds);

        Database::execute(
            'INSERT INTO mobile_scan_sessions '
            . '(token_hash,pairing_code,user_id,campaign_id,sede_id,purpose,status,scan_sequence,ack_sequence,expires_at) '
            . "VALUES (?,?,?,?,?,'inventory_capture','active',0,0,?)",
            [
                hash('sha256', $token),
                $pairingCode,
                $userId,
                $campaignId > 0 ? $campaignId : null,
                $sedeId > 0 ? $sedeId : null,
                $expiresAt,
            ]
        );

        return [
            'token' => $token,
            'pairing_code' => $pairingCode,
            'expires_at' => $expiresAt,
            'expires_in' => $ttlSeconds,
        ];
    }

    /** @return array<string,mixed>|null */
    public static function find(string $token): ?array
    {
        if (!self::validToken($token)) return null;
        $row = Database::fetchOne(
            'SELECT * FROM mobile_scan_sessions WHERE token_hash=? LIMIT 1',
            [hash('sha256', $token)]
        );
        if (!$row) return null;
        if ((string)$row['status'] !== 'active' || strtotime((string)$row['expires_at']) <= time()) {
            if ((string)$row['status'] === 'active') {
                Database::execute("UPDATE mobile_scan_sessions SET status='expired',updated_at=NOW() WHERE id=?", [(int)$row['id']]);
                $row['status'] = 'expired';
            }
        }
        return $row;
    }

    /** @return array<string,mixed>|null */
    public static function findForUser(string $token, int $userId): ?array
    {
        $row = self::find($token);
        if (!$row || (int)$row['user_id'] !== $userId) return null;
        return $row;
    }

    /** @return array{status:string,sequence:int,ack_sequence:int,expires_at:string,expires_in:int} */
    public static function mobileStatus(string $token): array
    {
        $row = self::find($token);
        if (!$row) {
            throw new RuntimeException('La conexión móvil no existe.');
        }
        if ((string)$row['status'] === 'active') {
            Database::execute('UPDATE mobile_scan_sessions SET mobile_last_seen_at=NOW(),updated_at=NOW() WHERE id=?', [(int)$row['id']]);
        }
        $expiresIn = max(0, strtotime((string)$row['expires_at']) - time());
        return [
            'status' => (string)$row['status'],
            'sequence' => (int)$row['scan_sequence'],
            'ack_sequence' => (int)($row['ack_sequence'] ?? 0),
            'expires_at' => (string)$row['expires_at'],
            'expires_in' => $expiresIn,
        ];
    }

    /**
     * @return array{sequence:int,target:string,value:string,format:string,scanned_at:string,acknowledged:bool}
     */
    public static function submit(string $token, string $target, string $value, string $format = '', string $requestId = ''): array
    {
        $row = self::find($token);
        if (!$row || (string)$row['status'] !== 'active') {
            throw new RuntimeException('La conexión móvil venció o ya no está disponible.');
        }
        if (!in_array($target, self::TARGETS, true)) {
            throw new InvalidArgumentException('Seleccione si el código corresponde al serial o a la Placa RNEC.');
        }

        $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? $value);
        if ($value === '') throw new InvalidArgumentException('No se recibió un valor para enviar.');
        if (mb_strlen($value) > 255) throw new InvalidArgumentException('El valor leído supera la longitud permitida.');

        if ($target === 'placa_rnec') {
            $normalizedPlate = normalize_placa_rnec($value);
            if ($normalizedPlate !== null) $value = $normalizedPlate;
        }

        $format = mb_substr(trim($format), 0, 80);
        $requestId = trim($requestId);
        if ($requestId !== '' && preg_match('/^[A-Za-z0-9_-]{12,80}$/', $requestId) !== 1) {
            throw new InvalidArgumentException('La referencia del envío no es válida.');
        }

        if ($requestId !== ''
            && hash_equals((string)($row['last_request_id'] ?? ''), $requestId)
            && (string)($row['last_target'] ?? '') === $target
            && (string)($row['last_value'] ?? '') === $value) {
            return [
                'sequence' => (int)$row['scan_sequence'],
                'target' => $target,
                'value' => $value,
                'format' => (string)($row['last_format'] ?? ''),
                'scanned_at' => (string)($row['last_scanned_at'] ?? ''),
                'acknowledged' => (int)($row['ack_sequence'] ?? 0) >= (int)$row['scan_sequence'],
            ];
        }

        Database::execute(
            'UPDATE mobile_scan_sessions SET '
            . 'scan_sequence=scan_sequence+1,last_target=?,last_value=?,last_format=?,last_request_id=?,last_scanned_at=NOW(),updated_at=NOW() '
            . "WHERE id=? AND status='active' AND expires_at>NOW()",
            [$target, $value, $format, $requestId !== '' ? $requestId : null, (int)$row['id']]
        );
        $fresh = Database::fetchOne(
            'SELECT scan_sequence,ack_sequence,last_target,last_value,last_format,last_scanned_at FROM mobile_scan_sessions WHERE id=?',
            [(int)$row['id']]
        );
        if (!$fresh) throw new RuntimeException('No fue posible confirmar el envío del código.');

        return [
            'sequence' => (int)$fresh['scan_sequence'],
            'target' => (string)$fresh['last_target'],
            'value' => (string)$fresh['last_value'],
            'format' => (string)($fresh['last_format'] ?? ''),
            'scanned_at' => (string)$fresh['last_scanned_at'],
            'acknowledged' => (int)($fresh['ack_sequence'] ?? 0) >= (int)$fresh['scan_sequence'],
        ];
    }

    /** @return array{sequence:int,ack_sequence:int,acknowledged_at:string} */
    public static function acknowledge(string $token, int $userId, int $sequence): array
    {
        $row = self::findForUser($token, $userId);
        if (!$row || (string)$row['status'] !== 'active') {
            throw new RuntimeException('La conexión móvil venció o ya no está disponible.');
        }
        $current = (int)$row['scan_sequence'];
        if ($sequence < 1 || $sequence > $current) {
            throw new InvalidArgumentException('La lectura que intenta confirmar no es válida.');
        }
        Database::execute(
            'UPDATE mobile_scan_sessions SET ack_sequence=GREATEST(ack_sequence,?),last_acknowledged_at=NOW(),updated_at=NOW() WHERE id=?',
            [$sequence, (int)$row['id']]
        );
        return [
            'sequence' => $current,
            'ack_sequence' => $sequence,
            'acknowledged_at' => date('Y-m-d H:i:s'),
        ];
    }

    /** @return array{expires_at:string,expires_in:int} */
    public static function renew(string $token, int $userId): array
    {
        $row = self::findForUser($token, $userId);
        if (!$row || !in_array((string)$row['status'], ['active', 'expired'], true)) {
            throw new RuntimeException('La conexión móvil no puede renovarse.');
        }
        $ttlSeconds = self::ttlSeconds();
        $expiresAt = date('Y-m-d H:i:s', time() + $ttlSeconds);
        Database::execute(
            "UPDATE mobile_scan_sessions SET status='active',expires_at=?,renewed_at=NOW(),updated_at=NOW() WHERE id=?",
            [$expiresAt, (int)$row['id']]
        );
        return ['expires_at' => $expiresAt, 'expires_in' => $ttlSeconds];
    }

    /**
     * Decodifica una fotografía mediante zbarimg y usa OCR como alternativa
     * cuando la etiqueta no contiene un código legible.
     *
     * @param array<string,mixed> $file
     * @return array{value:string,format:string,candidates:array<int,string>,method:string}
     */
    public static function decodeUploadedImage(string $token, array $file, string $target = 'serial_number'): array
    {
        $row = self::find($token);
        if (!$row || (string)$row['status'] !== 'active') {
            throw new RuntimeException('La conexión móvil venció o ya no está disponible.');
        }
        if (!in_array($target, self::TARGETS, true)) $target = 'serial_number';

        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('No se recibió una fotografía válida. Intente tomarla nuevamente.');
        }
        $size = (int)($file['size'] ?? 0);
        if ($size < 1 || $size > 12 * 1024 * 1024) {
            throw new InvalidArgumentException('La imagen debe pesar menos de 12 MB.');
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            throw new InvalidArgumentException('No fue posible acceder a la imagen capturada.');
        }

        MalwareScanner::scanOrFail($tmp);

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = strtolower((string)$finfo->file($tmp));
        $allowed = ['image/jpeg','image/png','image/webp','image/gif','image/bmp'];
        if (!in_array($mime, $allowed, true)) {
            throw new InvalidArgumentException('El formato de la imagen no es compatible. Use JPG, PNG o WEBP.');
        }

        $barcode = self::decodeBarcode($tmp);
        if ($barcode !== null) {
            return [
                'value' => $barcode['value'],
                'format' => $barcode['format'],
                'candidates' => [$barcode['value']],
                'method' => 'barcode',
            ];
        }

        $ocrText = self::readText($tmp);
        $candidates = self::extractOcrCandidates($ocrText, $target);
        if ($candidates === []) {
            throw new InvalidArgumentException('No se detectó un código ni un serial legible. Acerque la cámara, evite reflejos o seleccione una imagen más nítida.');
        }

        return [
            'value' => $candidates[0],
            'format' => 'Texto detectado · OCR',
            'candidates' => $candidates,
            'method' => 'ocr',
        ];
    }

    /** @return array{value:string,format:string}|null */
    private static function decodeBarcode(string $tmp): ?array
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open(['zbarimg','--quiet',$tmp], $descriptors, $pipes);
        if (!is_resource($process)) return null;
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $lines = preg_split('/\R/u', trim((string)$stdout)) ?: [];
        $line = trim((string)($lines[0] ?? ''));
        if ($exitCode !== 0 || $line === '') {
            if (trim((string)$stderr) !== '') error_log('mobile_scan_image barcode: '.trim((string)$stderr));
            return null;
        }
        $separator = strpos($line, ':');
        $format = $separator === false ? 'Código en imagen' : trim(substr($line, 0, $separator));
        $value = $separator === false ? $line : trim(substr($line, $separator + 1));
        if ($value === '' || mb_strlen($value) > 255) return null;
        return ['value' => $value, 'format' => mb_substr(str_replace('-', ' ', $format).' · IMAGEN', 0, 80)];
    }

    private static function readText(string $tmp): string
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open(['tesseract',$tmp,'stdout','--psm','6','-l','eng'], $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('El reconocimiento de texto no está disponible en el servidor.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            error_log('mobile_scan_image OCR: '.trim((string)$stderr));
            return '';
        }
        return trim((string)$stdout);
    }

    /** @return array<int,string> */
    private static function extractOcrCandidates(string $text, string $target): array
    {
        $normalizedText = str_replace(["\r", "\t"], ["\n", ' '], $text);
        $upper = function_exists('mb_strtoupper') ? mb_strtoupper($normalizedText) : strtoupper($normalizedText);
        $candidates = [];
        $push = static function(string $value) use (&$candidates): void {
            $value = trim($value, " \t\n\r\0\x0B:;,.#|[](){}<>");
            $value = preg_replace('/\s+/', '', $value) ?? $value;
            $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
            if ($value === '' || $length < 4 || $length > 40) return;
            if (!preg_match('/[0-9]/', $value)) return;
            $upperValue = function_exists('mb_strtoupper') ? mb_strtoupper($value) : strtoupper($value);
            $key = preg_replace('/[^A-Z0-9]/', '', $upperValue) ?: '';
            if ($key === '' || isset($candidates[$key])) return;
            $candidates[$key] = $value;
        };

        if (preg_match_all('/(?:S\/?N|SN|SERIAL(?:\s+NUMBER)?|SERVICE\s+TAG|SERIE)\s*[:#\-]?\s*([A-Z0-9][A-Z0-9\-\.]{3,39})/u', $upper, $matches)) {
            foreach ($matches[1] as $value) $push((string)$value);
        }
        if (preg_match_all('/\b(\d{3})[\s\-]?(\d{5})\b/u', $upper, $plates, PREG_SET_ORDER)) {
            foreach ($plates as $match) $push($match[1].'-'.$match[2]);
        }
        if (preg_match_all('/\b[A-Z0-9][A-Z0-9\-\.]{4,31}\b/u', $upper, $tokens)) {
            foreach ($tokens[0] as $value) {
                $value = (string)$value;
                if (preg_match('/[A-Z]/', $value) && preg_match('/[0-9]/', $value)) $push($value);
            }
        }

        $values = array_values($candidates);
        usort($values, static function(string $a, string $b) use ($target): int {
            $aPlate = preg_match('/^\d{3}-?\d{5}$/', $a) === 1;
            $bPlate = preg_match('/^\d{3}-?\d{5}$/', $b) === 1;
            if ($aPlate !== $bPlate) {
                return $target === 'placa_rnec' ? ($aPlate ? -1 : 1) : ($aPlate ? 1 : -1);
            }
            return strlen($a) <=> strlen($b);
        });
        return array_slice($values, 0, 8);
    }

    public static function stop(string $token, int $userId): void
    {
        if (!self::validToken($token) || $userId < 1) return;
        Database::execute(
            "UPDATE mobile_scan_sessions SET status='cancelled',updated_at=NOW() WHERE token_hash=? AND user_id=? AND status='active'",
            [hash('sha256', $token), $userId]
        );
    }

    public static function cleanup(): void
    {
        Database::execute("UPDATE mobile_scan_sessions SET status='expired',updated_at=NOW() WHERE status='active' AND expires_at<=NOW()");
        Database::execute("DELETE FROM mobile_scan_sessions WHERE created_at<DATE_SUB(NOW(), INTERVAL 2 DAY)");
    }

    public static function scannerUrl(string $token): string
    {
        return self::absoluteUrl('mobile_scanner', ['token' => $token]);
    }

    public static function absoluteUrl(string $page, array $params = []): string
    {
        $base = rtrim((string)Env::get('APP_URL', ''), '/');
        if ($base === '') {
            $trustProxy = function_exists('sivi_request_from_trusted_proxy')
                && sivi_request_from_trusted_proxy();
            $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($trustProxy && $forwardedProto === 'https');
            $host = trim((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
            $base = ($https ? 'https' : 'http') . '://' . $host;
        }
        return $base . '/index.php?' . http_build_query(array_merge(['page' => $page], $params));
    }


    private static function ttlSeconds(): int
    {
        try {
            return AppSettings::mobileSessionMinutes() * 60;
        } catch (Throwable) {
            return self::DEFAULT_TTL_SECONDS;
        }
    }

    private static function validToken(string $token): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $token) === 1;
    }
}
