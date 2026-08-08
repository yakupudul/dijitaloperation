<?php

namespace App\Support\Integrations\Google;

use App\Models\CoreIntegration;
use App\Support\Integrations\ProviderRegistry;

final class GoogleAuthStatus
{
    public const string NOT_CONFIGURED = 'not_configured';

    public const string AUTHORIZATION_REQUIRED = 'authorization_required';

    public const string CONNECTED = 'connected';

    public const string REFRESH_REQUIRED = 'refresh_required';

    public const string ERROR = 'error';

    public const string DISABLED = 'disabled';

    public static function for(CoreIntegration $integration): string
    {
        if ($integration->provider !== ProviderRegistry::GOOGLE) {
            return self::ERROR;
        }

        if ($integration->status === CoreIntegration::STATUS_DISABLED) {
            return self::DISABLED;
        }

        if (! GoogleOAuthConfig::isConfigured()) {
            return self::NOT_CONFIGURED;
        }

        $configStatus = (string) data_get($integration->config, 'auth_status', '');
        if (in_array($configStatus, [self::REFRESH_REQUIRED, self::ERROR], true)) {
            return $configStatus;
        }

        if (! $integration->credential()->exists()) {
            return self::AUTHORIZATION_REQUIRED;
        }

        $payload = $integration->credential?->encrypted_payload ?? [];
        if (! is_array($payload) || blank($payload['refresh_token'] ?? null)) {
            return self::REFRESH_REQUIRED;
        }

        return self::CONNECTED;
    }

    public static function label(string $status): string
    {
        return match ($status) {
            self::NOT_CONFIGURED => 'Not configured',
            self::AUTHORIZATION_REQUIRED => 'Authorization required',
            self::CONNECTED => 'Connected',
            self::REFRESH_REQUIRED => 'Expired / Refresh required',
            self::ERROR => 'Error',
            self::DISABLED => 'Disabled',
            default => str($status)->replace('_', ' ')->title()->toString(),
        };
    }
}
