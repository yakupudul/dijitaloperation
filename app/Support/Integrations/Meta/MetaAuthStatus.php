<?php

namespace App\Support\Integrations\Meta;

use App\Models\CoreIntegration;
use App\Services\Integrations\Meta\MetaCredentialResolver;

/**
 * Operator-facing Meta Integration auth labels (persisted state only).
 */
final class MetaAuthStatus
{
    public const string NOT_CONFIGURED = 'not_configured';

    public const string CONFIGURED = 'configured';

    public const string CONNECTED = 'connected';

    public const string ISSUE = 'issue';

    public static function for(CoreIntegration $integration): string
    {
        $resolver = app(MetaCredentialResolver::class);
        if (! $resolver->isConfigured($integration)) {
            return self::NOT_CONFIGURED;
        }

        $connectionStatus = data_get($integration->config, 'connection_status');
        if ($connectionStatus === 'connected' && blank($integration->last_error)) {
            return self::CONNECTED;
        }

        if ($connectionStatus === 'issue' || filled($integration->last_error)) {
            return self::ISSUE;
        }

        return self::CONFIGURED;
    }

    public static function label(string $status): string
    {
        return match ($status) {
            self::CONNECTED => 'Healthy',
            self::CONFIGURED => 'Configured',
            self::ISSUE => 'Needs attention',
            default => 'Not configured',
        };
    }

    public static function configurationLabel(CoreIntegration $integration): string
    {
        $resolver = app(MetaCredentialResolver::class);

        if (! $resolver->isConfigured($integration)) {
            return 'Not configured';
        }

        if ($resolver->accessTokenSource($integration) === MetaCredentialResolver::SOURCE_ENVIRONMENT
            && ! $resolver->hasDatabaseAccessToken($integration)) {
            return 'Configured by environment';
        }

        return 'Configured';
    }

    public static function connectionLabel(CoreIntegration $integration): string
    {
        $status = data_get($integration->config, 'connection_status');

        return match ($status) {
            'connected' => 'Connected ✓',
            'issue' => 'Needs attention',
            default => 'Not tested',
        };
    }

    public static function accessTokenLabel(CoreIntegration $integration): string
    {
        $resolver = app(MetaCredentialResolver::class);

        if ($resolver->hasDatabaseAccessToken($integration)) {
            return 'Stored securely ✓';
        }

        $source = $resolver->accessTokenSource($integration);

        return $resolver->configurationLabel(
            $source,
            $resolver->accessToken($integration) !== null,
        );
    }
}
