<?php

namespace App\Support\Integrations\DataForSeo;

use App\Models\CoreIntegration;
use App\Services\Integrations\DataForSeo\DataForSeoCredentialResolver;
use App\Support\Integrations\ProviderRegistry;

final class DataForSeoAuthStatus
{
    public const string CONFIGURED = 'configured';

    public const string NOT_CONFIGURED = 'not_configured';

    public const string CONNECTION_ISSUE = 'connection_issue';

    public static function for(CoreIntegration $integration): string
    {
        if ($integration->provider !== ProviderRegistry::DATAFORSEO) {
            return self::NOT_CONFIGURED;
        }

        $resolver = app(DataForSeoCredentialResolver::class);

        if (! $resolver->isConfigured($integration)) {
            return self::NOT_CONFIGURED;
        }

        $connectionStatus = data_get($integration->config, 'connection_status');
        if ($connectionStatus === 'issue' || filled($integration->last_error)) {
            return self::CONNECTION_ISSUE;
        }

        return self::CONFIGURED;
    }

    public static function label(string $status): string
    {
        return match ($status) {
            self::CONFIGURED => __('operator.states.configured'),
            self::CONNECTION_ISSUE => __('operator.states.connection_issue'),
            default => __('operator.states.not_configured'),
        };
    }

    public static function configurationLabel(CoreIntegration $integration): string
    {
        $resolver = app(DataForSeoCredentialResolver::class);

        if (! $resolver->isConfigured($integration)) {
            return __('operator.states.not_configured');
        }

        $loginSource = $resolver->loginSource($integration);
        $passwordSource = $resolver->passwordSource($integration);

        if ($loginSource === DataForSeoCredentialResolver::SOURCE_ENVIRONMENT
            || $passwordSource === DataForSeoCredentialResolver::SOURCE_ENVIRONMENT) {
            if ($loginSource === DataForSeoCredentialResolver::SOURCE_DATABASE
                || $passwordSource === DataForSeoCredentialResolver::SOURCE_DATABASE) {
                return __('operator.states.configured');
            }

            return __('operator.states.configured_environment');
        }

        return __('operator.states.configured');
    }

    public static function connectionLabel(CoreIntegration $integration): string
    {
        $status = data_get($integration->config, 'connection_status');

        return match ($status) {
            'connected' => 'Connected ✓',
            'issue' => 'Connection issue',
            default => 'Not tested',
        };
    }
}
