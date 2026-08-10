<?php

namespace App\Support\Integrations\Presentation;

use App\Models\CoreIntegration;
use App\Services\Integrations\Anthropic\AnthropicCredentialResolver;
use App\Services\Integrations\DataForSeo\DataForSeoCredentialResolver;
use App\Services\Integrations\Gemini\GeminiCredentialResolver;
use App\Services\Integrations\Google\GoogleCredentialResolver;
use App\Services\Integrations\OpenAi\OpenAiCredentialResolver;
use App\Support\Ai\AiProviderCatalog;
use App\Support\Integrations\Google\GoogleAuthStatus;
use App\Support\Integrations\ProviderRegistry;
use Illuminate\Support\Carbon;

/**
 * Derives operator-facing health from persisted Integration state only.
 * Never performs provider HTTP calls.
 */
final class IntegrationHealthPresenter
{
    public function status(?CoreIntegration $integration, string $provider): string
    {
        if (! $integration instanceof CoreIntegration) {
            return IntegrationOperatorStatus::NOT_CONFIGURED;
        }

        if ($integration->status === CoreIntegration::STATUS_DISABLED) {
            return IntegrationOperatorStatus::DISABLED;
        }

        return match ($provider) {
            ProviderRegistry::GOOGLE => $this->googleStatus($integration),
            ProviderRegistry::DATAFORSEO => $this->apiKeyProviderStatus(
                $integration,
                app(DataForSeoCredentialResolver::class)->isConfigured($integration),
            ),
            ProviderRegistry::OPENAI => $this->apiKeyProviderStatus(
                $integration,
                app(OpenAiCredentialResolver::class)->isConfigured($integration),
            ),
            ProviderRegistry::ANTHROPIC => $this->apiKeyProviderStatus(
                $integration,
                app(AnthropicCredentialResolver::class)->isConfigured($integration),
            ),
            ProviderRegistry::GEMINI => $this->apiKeyProviderStatus(
                $integration,
                app(GeminiCredentialResolver::class)->isConfigured($integration),
            ),
            default => IntegrationOperatorStatus::NOT_CONFIGURED,
        };
    }

    /**
     * @return list<string>
     */
    public function summaryLines(?CoreIntegration $integration, string $provider): array
    {
        return match ($provider) {
            ProviderRegistry::GOOGLE => $this->googleSummary($integration),
            ProviderRegistry::DATAFORSEO => $this->dataForSeoSummary($integration),
            ProviderRegistry::OPENAI,
            ProviderRegistry::ANTHROPIC,
            ProviderRegistry::GEMINI => $this->aiProviderSummary($integration),
            default => [],
        };
    }

    public function lastCheckedLabel(?CoreIntegration $integration, string $provider): ?string
    {
        if (! $integration instanceof CoreIntegration) {
            return null;
        }

        $raw = match ($provider) {
            ProviderRegistry::GOOGLE => data_get($integration->config, 'last_resource_refresh_at')
                ?? ($integration->last_success_at?->toIso8601String()),
            default => data_get($integration->config, 'last_tested_at')
                ?? ($integration->last_success_at?->toIso8601String()),
        };

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return 'Last checked '.Carbon::parse($raw)->diffForHumans(short: true);
        } catch (\Throwable) {
            return null;
        }
    }

    private function googleStatus(CoreIntegration $integration): string
    {
        if (! app(GoogleCredentialResolver::class)->isAppConfigured($integration)) {
            return IntegrationOperatorStatus::NOT_CONFIGURED;
        }

        $auth = GoogleAuthStatus::for($integration);

        return match ($auth) {
            GoogleAuthStatus::CONNECTED => IntegrationOperatorStatus::CONNECTED,
            GoogleAuthStatus::DISABLED => IntegrationOperatorStatus::DISABLED,
            GoogleAuthStatus::ERROR, GoogleAuthStatus::REFRESH_REQUIRED => IntegrationOperatorStatus::NEEDS_ATTENTION,
            GoogleAuthStatus::AUTHORIZATION_REQUIRED => IntegrationOperatorStatus::CONFIGURED,
            default => IntegrationOperatorStatus::NOT_CONFIGURED,
        };
    }

    private function apiKeyProviderStatus(CoreIntegration $integration, bool $configured): string
    {
        if (! $configured) {
            return IntegrationOperatorStatus::NOT_CONFIGURED;
        }

        $connectionStatus = data_get($integration->config, 'connection_status');

        if ($connectionStatus === 'connected' && blank($integration->last_error)) {
            return IntegrationOperatorStatus::CONNECTED;
        }

        if ($connectionStatus === 'issue' || filled($integration->last_error)) {
            return IntegrationOperatorStatus::NEEDS_ATTENTION;
        }

        // Credentials exist but never successfully tested.
        return IntegrationOperatorStatus::CONFIGURED;
    }

    /**
     * @return list<string>
     */
    private function googleSummary(?CoreIntegration $integration): array
    {
        $meta = IntegrationPresentationRegistry::for(ProviderRegistry::GOOGLE);
        $capabilityLine = implode(' · ', $meta['capability_labels'] ?? [
            'Search Console', 'GA4', 'Google Ads', 'GBP',
        ]);

        $lines = [$capabilityLine];

        if ($integration instanceof CoreIntegration) {
            $email = data_get($integration->config, 'account_email');
            if (is_string($email) && $email !== '') {
                array_unshift($lines, $email);
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function dataForSeoSummary(?CoreIntegration $integration): array
    {
        $lines = ['External SEO intelligence'];

        if (! $integration instanceof CoreIntegration) {
            return $lines;
        }

        $login = data_get($integration->config, 'account_login');
        if (! is_string($login) || $login === '') {
            $login = app(DataForSeoCredentialResolver::class)->databaseLogin($integration);
        }
        if (is_string($login) && $login !== '') {
            $lines[] = $login;
        }

        $balance = data_get($integration->config, 'balance');
        if (is_numeric($balance)) {
            $lines[] = 'Balance '.$balance.' USD';
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function aiProviderSummary(?CoreIntegration $integration): array
    {
        return [
            'Available for AI routes',
        ];
    }

    private function humanModelLabel(string $model): string
    {
        return AiProviderCatalog::humanModelLabel($model);
    }
}
