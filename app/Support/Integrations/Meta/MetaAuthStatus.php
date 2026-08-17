<?php

namespace App\Support\Integrations\Meta;

use App\Models\CoreIntegration;
use App\Services\Integrations\Meta\MetaCredentialResolver;
use App\Services\Integrations\Meta\MetaPermissionCoverageService;

/**
 * Operator-facing Meta Integration auth labels (persisted state only).
 *
 * Distinguishes application configuration from tenant authorization.
 * Token exists ≠ token validated (Prompt 22 owns validation).
 */
final class MetaAuthStatus
{
    public const string NOT_CONFIGURED = 'not_configured';

    public const string AUTHORIZATION_REQUIRED = 'authorization_required';

    public const string CONFIGURED = 'configured';

    public const string CONNECTED = 'connected';

    public const string REAUTH_REQUIRED = 'reauth_required';

    public const string PERMISSION_REQUIRED = 'permission_required';

    public const string ISSUE = 'issue';

    public static function for(CoreIntegration $integration): string
    {
        $resolver = app(MetaCredentialResolver::class);

        if (! $resolver->hasTenantAuthorization($integration) && ! $resolver->isApplicationConfigured($integration)) {
            return self::NOT_CONFIGURED;
        }

        if (! $resolver->hasTenantAuthorization($integration)) {
            return self::AUTHORIZATION_REQUIRED;
        }

        $authStatus = data_get($integration->config, 'auth_status');
        $credentialStatus = data_get($integration->config, 'credential_status');

        if (in_array($authStatus, ['reauth_required'], true)
            || in_array($credentialStatus, ['expired', 'revoked', 'invalid', 'wrong_app'], true)) {
            return self::REAUTH_REQUIRED;
        }

        $coverage = app(MetaPermissionCoverageService::class);
        if ($coverage->missingForAdAccountDiscovery($integration) !== []
            && data_get($integration->config, 'granted_permissions') !== null) {
            return self::PERMISSION_REQUIRED;
        }

        $connectionStatus = data_get($integration->config, 'connection_status');
        if (($authStatus === 'connected' || $connectionStatus === 'connected') && blank($integration->last_error)) {
            return self::CONNECTED;
        }

        if ($connectionStatus === 'issue' || filled($integration->last_error)) {
            return self::ISSUE;
        }

        // Tenant token present but not yet health-tested — authorized credential stored.
        return self::CONFIGURED;
    }

    public static function label(string $status): string
    {
        return match ($status) {
            self::CONNECTED => 'Authorized',
            self::CONFIGURED => 'Credential stored',
            self::AUTHORIZATION_REQUIRED => 'Authorization required',
            self::REAUTH_REQUIRED => 'Reauthorization required',
            self::PERMISSION_REQUIRED => 'Permission required',
            self::ISSUE => 'Needs attention',
            default => 'Not configured',
        };
    }

    public static function configurationLabel(CoreIntegration $integration): string
    {
        return app(MetaCredentialResolver::class)->applicationConfigurationLabel($integration);
    }

    public static function connectionLabel(CoreIntegration $integration): string
    {
        $status = data_get($integration->config, 'connection_status');

        return match ($status) {
            'connected' => 'Connection tested ✓',
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
