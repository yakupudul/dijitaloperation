<?php

namespace MoxDop\Website\SeoIntelligence;

use App\Models\CoreIntegration;
use App\Services\Integrations\DataForSeo\DataForSeoCredentialResolver;
use App\Support\Integrations\DataForSeo\DataForSeoAuthStatus;
use App\Support\Integrations\ProviderRegistry;

/**
 * Resolve the active agency DataForSEO Integration for Website SEO Intelligence.
 */
final class DataForSeoIntegrationResolver
{
    public function __construct(
        private readonly DataForSeoCredentialResolver $credentials,
    ) {}

    public function active(): ?CoreIntegration
    {
        return CoreIntegration::query()
            ->with('providerCredential')
            ->where('provider', ProviderRegistry::DATAFORSEO)
            ->where('status', CoreIntegration::STATUS_ACTIVE)
            ->orderBy('id')
            ->first();
    }

    /**
     * @return array{configured: bool, integration: ?CoreIntegration, status: string, message: ?string}
     */
    public function status(): array
    {
        $integration = $this->active();

        if ($integration === null) {
            return [
                'configured' => false,
                'integration' => null,
                'status' => DataForSeoAuthStatus::NOT_CONFIGURED,
                'message' => 'Connect DataForSEO in Settings → Integrations to enable market-wide keyword visibility.',
            ];
        }

        if (! $this->credentials->isConfigured($integration)) {
            return [
                'configured' => false,
                'integration' => $integration,
                'status' => DataForSeoAuthStatus::NOT_CONFIGURED,
                'message' => 'Connect DataForSEO in Settings → Integrations to enable market-wide keyword visibility.',
            ];
        }

        return [
            'configured' => true,
            'integration' => $integration,
            'status' => DataForSeoAuthStatus::for($integration),
            'message' => null,
        ];
    }
}
