<?php

namespace App\Support\Integrations\Google;

final class GoogleOAuthConfig
{
    public static function clientId(): ?string
    {
        $value = config('moxdop.google.client_id');

        return filled($value) ? (string) $value : null;
    }

    public static function clientSecret(): ?string
    {
        $value = config('moxdop.google.client_secret');

        return filled($value) ? (string) $value : null;
    }

    public static function redirectUri(): string
    {
        return (string) config('moxdop.google.redirect_uri');
    }

    public static function developerToken(): ?string
    {
        $value = config('moxdop.google.developer_token');

        return filled($value) ? (string) $value : null;
    }

    public static function isConfigured(): bool
    {
        return self::clientId() !== null && self::clientSecret() !== null;
    }

    /**
     * @return list<string>
     */
    public static function missingKeys(): array
    {
        $missing = [];

        if (self::clientId() === null) {
            $missing[] = 'GOOGLE_CLIENT_ID';
        }

        if (self::clientSecret() === null) {
            $missing[] = 'GOOGLE_CLIENT_SECRET';
        }

        return $missing;
    }
}
