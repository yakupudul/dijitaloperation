<?php

namespace App\Support\Integrations\Anthropic;

use App\Models\CoreIntegration;
use App\Services\Integrations\Anthropic\AnthropicCredentialResolver;
use App\Support\Ai\AiProviderCatalog;

final class AnthropicAuthStatus
{
    public static function configurationLabel(CoreIntegration $integration): string
    {
        $resolver = app(AnthropicCredentialResolver::class);

        if (! $resolver->isConfigured($integration)) {
            return __('operator.states.not_configured');
        }

        if ($resolver->apiKeySource($integration) === AnthropicCredentialResolver::SOURCE_ENVIRONMENT
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
            'issue' => 'Needs attention',
            default => 'Not tested',
        };
    }

    public static function apiKeyLabel(CoreIntegration $integration): string
    {
        $resolver = app(AnthropicCredentialResolver::class);

        if ($resolver->hasDatabaseApiKey($integration)) {
            return 'Stored securely ✓';
        }

        if ($resolver->apiKeySource($integration) === AnthropicCredentialResolver::SOURCE_ENVIRONMENT) {
            return 'Configured by environment';
        }

        return 'Not configured';
    }

    public static function assertProvider(CoreIntegration $integration): bool
    {
        return $integration->provider === AiProviderCatalog::ANTHROPIC;
    }
}
