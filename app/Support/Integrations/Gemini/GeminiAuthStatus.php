<?php

namespace App\Support\Integrations\Gemini;

use App\Models\CoreIntegration;
use App\Services\Integrations\Gemini\GeminiCredentialResolver;
use App\Support\Ai\AiProviderCatalog;

final class GeminiAuthStatus
{
    public static function configurationLabel(CoreIntegration $integration): string
    {
        $resolver = app(GeminiCredentialResolver::class);

        if (! $resolver->isConfigured($integration)) {
            return 'Not configured';
        }

        if ($resolver->apiKeySource($integration) === GeminiCredentialResolver::SOURCE_ENVIRONMENT
            && ! $resolver->hasDatabaseApiKey($integration)) {
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

    public static function apiKeyLabel(CoreIntegration $integration): string
    {
        $resolver = app(GeminiCredentialResolver::class);

        if ($resolver->hasDatabaseApiKey($integration)) {
            return 'Stored securely ✓';
        }

        if ($resolver->apiKeySource($integration) === GeminiCredentialResolver::SOURCE_ENVIRONMENT) {
            return 'Configured by environment';
        }

        return 'Not configured';
    }

    public static function assertProvider(CoreIntegration $integration): bool
    {
        return $integration->provider === AiProviderCatalog::GEMINI;
    }
}
