<?php

namespace App\Support\Integrations\Google;

/**
 * Prevents provider secrets / OAuth tokens from living in CoreIntegration.config.
 */
final class GoogleIntegrationConfigGuard
{
    /**
     * Keys that must never be stored in Integration.config (case-insensitive).
     *
     * @var list<string>
     */
    public const FORBIDDEN_KEYS = [
        'client_secret',
        'clientsecret',
        'developer_token',
        'developertoken',
        'google_ads_developer_token',
        'access_token',
        'accesstoken',
        'refresh_token',
        'refreshtoken',
        'password',
        'secret',
        'token',
    ];

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function stripUnsafe(array $config): array
    {
        $clean = [];

        foreach ($config as $key => $value) {
            if (! is_string($key) && ! is_int($key)) {
                continue;
            }

            $keyString = (string) $key;
            if (self::isUnsafeKey($keyString) || self::looksLikeOAuthClientIdKey($keyString)) {
                continue;
            }

            if (self::valueLooksLikeSecret($value)) {
                continue;
            }

            $clean[$keyString] = $value;
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function containsUnsafe(array $config): bool
    {
        return self::stripUnsafe($config) !== $config;
    }

    public static function isUnsafeKey(string $key): bool
    {
        $normalized = strtolower(trim($key));
        $normalized = str_replace(['-', ' '], '_', $normalized);

        if (in_array($normalized, self::FORBIDDEN_KEYS, true)) {
            return true;
        }

        foreach (['client_secret', 'developer_token', 'access_token', 'refresh_token', 'password'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mis-entered Client IDs as KeyValue keys (*.apps.googleusercontent.com).
     */
    public static function looksLikeOAuthClientIdKey(string $key): bool
    {
        return (bool) preg_match('/\.apps\.googleusercontent\.com$/i', trim($key));
    }

    public static function valueLooksLikeSecret(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return false;
        }

        // Common Google OAuth secret / token shapes — detect without logging values.
        return (bool) preg_match('/^(GOCSPX-|ya29\.|1\/\/)/', $trimmed);
    }
}
