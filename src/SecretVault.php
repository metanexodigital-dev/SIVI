<?php
/**
 * DOCUMENTACIÓN TÉCNICA EN ESPAÑOL
 * Archivo: src/SecretVault.php
 * Propósito: Cifra y descifra secretos de integraciones mediante la clave configurada.
 * Mantenimiento: los comentarios describen la intención y las decisiones relevantes;
 * no sustituyen las validaciones automáticas ni deben contener claves o contraseñas.
 */
declare(strict_types=1);

/**
 * Cifra secretos administrables usando una llave exclusiva del entorno.
 * La llave nunca se almacena en la base de datos ni se incluye en los paquetes.
 */
final class SecretVault
{
    private const PREFIX = 'enc:v1:';
    private const CIPHER = 'aes-256-gcm';

    public static function isConfigured(): bool
    {
        return trim((string)Env::get('APP_ENCRYPTION_KEY', '')) !== '';
    }

    public static function encrypt(string $plainText): string
    {
        if ($plainText === '') return '';
        $key = self::key();
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        if ($ivLength < 1) throw new RuntimeException('El cifrado configurado no está disponible.');
        $iv = random_bytes($ivLength);
        $tag = '';
        $cipherText = openssl_encrypt($plainText, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipherText === false || $tag === '') {
            throw new RuntimeException('No fue posible cifrar la credencial.');
        }
        return self::PREFIX . base64_encode($iv . $tag . $cipherText);
    }

    public static function decrypt(string $encrypted): string
    {
        if ($encrypted === '') return '';
        if (!str_starts_with($encrypted, self::PREFIX)) {
            throw new RuntimeException('La credencial almacenada no tiene un formato cifrado válido.');
        }
        $payload = base64_decode(substr($encrypted, strlen(self::PREFIX)), true);
        if ($payload === false) throw new RuntimeException('La credencial cifrada está dañada.');
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $tagLength = 16;
        if (strlen($payload) <= $ivLength + $tagLength) {
            throw new RuntimeException('La credencial cifrada está incompleta.');
        }
        $iv = substr($payload, 0, $ivLength);
        $tag = substr($payload, $ivLength, $tagLength);
        $cipherText = substr($payload, $ivLength + $tagLength);
        $plainText = openssl_decrypt($cipherText, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plainText === false) {
            throw new RuntimeException('No fue posible descifrar la credencial. Verifique APP_ENCRYPTION_KEY.');
        }
        return $plainText;
    }

    public static function fingerprint(string $encrypted): string
    {
        if ($encrypted === '') return '';
        return strtoupper(substr(hash('sha256', $encrypted), 0, 12));
    }

    private static function key(): string
    {
        $raw = trim((string)Env::get('APP_ENCRYPTION_KEY', ''));
        if ($raw === '') {
            throw new RuntimeException('APP_ENCRYPTION_KEY no está configurada. Genere una llave segura antes de guardar secretos.');
        }
        $decoded = base64_decode($raw, true);
        if ($decoded !== false && strlen($decoded) >= 32) return substr($decoded, 0, 32);
        if (preg_match('/^[a-f0-9]{64,}$/i', $raw)) {
            $binary = hex2bin(substr($raw, 0, 64));
            if ($binary !== false) return $binary;
        }
        return hash('sha256', $raw, true);
    }
}
