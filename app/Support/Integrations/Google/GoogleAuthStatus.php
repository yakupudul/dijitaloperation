<?php

namespace App\Support\Integrations\Google;

use App\Models\CoreIntegration;
use App\Services\Integrations\Google\GoogleCredentialResolver;
use App\Support\Integrations\ProviderRegistry;

final class GoogleAuthStatus
{
    public const string NOT_CONFIGURED = 'not_configured';

    public const string AUTHORIZATION_REQUIRED = 'authorization_required';

    public const string CONNECTED = 'connected';

    public const string REFRESH_REQUIRED = 'refresh_required';

    /** Alias stored value for reauthorization / action-required. */
    public const string REAUTH_REQUIRED = self::REFRESH_REQUIRED;

    public const string REVOKED = 'revoked';

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

        if (! app(GoogleCredentialResolver::class)->isAppConfigured($integration)) {
            return self::NOT_CONFIGURED;
        }

        $configStatus = (string) data_get($integration->config, 'auth_status', '');
        if (in_array($configStatus, [self::REFRESH_REQUIRED, self::ERROR, self::REVOKED], true)) {
            return $configStatus;
        }

        if (! $integration->authorizationCredential()->exists()) {
            return self::AUTHORIZATION_REQUIRED;
        }

        $payload = $integration->authorizationCredential?->encrypted_payload ?? [];
        if (! is_array($payload) || blank($payload['refresh_token'] ?? null)) {
            return self::REFRESH_REQUIRED;
        }

        return self::CONNECTED;
    }

    public static function label(string $status): string
    {
        return match ($status) {
            self::NOT_CONFIGURED => __('operator.states.not_configured'),
            self::AUTHORIZATION_REQUIRED => __('operator.states.not_authorized'),
            self::CONNECTED => __('operator.states.authorized'),
            self::REFRESH_REQUIRED => __('operator.states.reconnect_required'),
            self::REVOKED => __('operator.states.revoked'),
            self::ERROR => __('operator.states.error'),
            self::DISABLED => __('operator.states.disabled'),
            default => str($status)->replace('_', ' ')->title()->toString(),
        };
    }

    public static function applicationConfigurationLabel(CoreIntegration $integration): string
    {
        return app(GoogleCredentialResolver::class)->isAppConfigured($integration)
            ? __('operator.states.configured')
            : __('operator.states.not_configured');
    }

    public static function adsDeveloperTokenLabel(CoreIntegration $integration): string
    {
        $resolver = app(GoogleCredentialResolver::class);

        if ($resolver->hasDeveloperToken($integration)) {
            return __('operator.states.configured');
        }

        return __('operator.states.missing');
    }
}
