<?php

namespace App\Support\ReportDelivery;

/**
 * Opaque secret hashing — never store plaintext tokens/OTPs.
 */
final class SecretHasher
{
    public static function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }

    public static function equals(string $raw, string $storedHash): bool
    {
        return hash_equals($storedHash, self::hash($raw));
    }

    public static function randomToken(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public static function otpCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}
