<?php

declare(strict_types=1);

namespace Fpjd\Sso;

/**
 * Cifrado autenticado (AES-256-GCM) para el contenido de cookies y tokens de relevo.
 * Utiliza una clave simétrica de 64 caracteres hexadecimales (openssl rand -hex 32).
 *
 * El parámetro $purpose se ata al texto cifrado como datos autenticados adicionales (AAD):
 * una cookie de flujo no se puede descifrar como cookie de cuenta, evitando ataques de sustitución.
 */
class TokenCipher
{
    private const CIPHER = 'aes-256-gcm';

    public function __construct(private ?string $keyHex = null)
    {
        if ($this->keyHex !== null) {
            $this->key();
        }
    }

    public function encrypt(string $plain, string $purpose): string
    {
        $iv = random_bytes(12);
        $tag = '';

        $cipher = openssl_encrypt(
            $plain,
            self::CIPHER,
            $this->key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $purpose,
            16
        );

        if ($cipher === false) {
            throw new \RuntimeException('No se pudo cifrar la información.');
        }

        return self::b64($iv . $tag . $cipher);
    }

    public function decrypt(string $payload, string $purpose): ?string
    {
        $raw = base64_decode(strtr($payload, '-_', '+/'), true);

        if ($raw === false || strlen($raw) < 29) {
            return null;
        }

        $plain = openssl_decrypt(
            substr($raw, 28),
            self::CIPHER,
            $this->key(),
            OPENSSL_RAW_DATA,
            substr($raw, 0, 12),
            substr($raw, 12, 16),
            $purpose
        );

        return $plain === false ? null : $plain;
    }

    private function key(): string
    {
        $candidate = $this->keyHex ?? (string) ($_ENV['APP_KEY'] ?? '');

        if (strlen($candidate) === 32) {
            return $candidate;
        }

        if (strlen($candidate) === 64 && ctype_xdigit($candidate)) {
            $bin = hex2bin($candidate);
            if ($bin !== false) {
                return $bin;
            }
        }

        throw new \InvalidArgumentException('Clave de cifrado inválida: se esperan 64 caracteres hexadecimales (openssl rand -hex 32) o 32 bytes binarios.');
    }

    private static function b64(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
