<?php

namespace App\Support\Integrations\OpenAi;

use App\Models\CoreIntegration;
use App\Services\Integrations\OpenAi\OpenAiCredentialResolver;
use App\Support\Integrations\ProviderRegistry;

final class OpenAiAuthStatus
{
    public const string CONFIGURED = 'configured';

    public const string NOT_CONFIGURED = 'not_configured';

    public const string CONNECTION_ISSUE = 'connection_issue';

    public static function for(CoreIntegration $integration): string
    {
        if ($integration->provider !== ProviderRegistry::OPENAI) {
            return self::NOT_CONFIGURED;
        }

        $resolver = app(OpenAiCredentialResolver::class);

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
        $resolver = app(OpenAiCredentialResolver::class);

        if (! $resolver->isConfigured($integration)) {
            return __('operator.states.not_configured');
        }

        if ($resolver->apiKeySource($integration) === OpenAiCredentialResolver::SOURCE_ENVIRONMENT
            && ! $resolver->hasDatabaseApiKey($integration)) {
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

    public static function apiKeyLabel(CoreIntegration $integration): string
    {
        $resolver = app(OpenAiCredentialResolver::class);

        if ($resolver->hasDatabaseApiKey($integration)) {
            return 'Stored securely ✓';
        }

        $source = $resolver->apiKeySource($integration);

        return $resolver->configurationLabel(
            $source,
            $resolver->apiKey($integration) !== null,
        );
    }
}
