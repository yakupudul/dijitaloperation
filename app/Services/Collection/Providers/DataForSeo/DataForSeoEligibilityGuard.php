<?php

namespace App\Services\Collection\Providers\DataForSeo;

use App\Enums\Collection\CollectionErrorCategory;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Services\Collection\Support\DatasetExecutionResult;
use App\Support\Integrations\DataForSeo\DataForSeoAuthStatus;
use App\Support\Integrations\ProviderRegistry;
use MoxDop\Website\SeoIntelligence\DataForSeoIntegrationResolver;
use MoxDop\Website\SeoIntelligence\WebsiteDomainTarget;

/**
 * DataForSEO enrichment is agency-credentialed and Website-asset scoped.
 * Google/Meta bindings never participate. Paid families require explicit consent.
 */
final class DataForSeoEligibilityGuard
{
    public function __construct(
        private readonly DataForSeoIntegrationResolver $resolver,
    ) {}

    /**
     * @return array{
     *   asset: DigitalAsset,
     *   integration: CoreIntegration,
     *   target: string,
     *   location_code: ?int,
     *   language_code: ?string,
     *   location_name: ?string,
     *   language_name: ?string,
     *   paid_consented: bool,
     *   discovery_requested: bool,
     *   force_refresh: bool
     * }|DatasetExecutionResult
     */
    public function assertEligible(
        CollectionRun $collectionRun,
        CollectionResourceRun $resourceRun,
        bool $paidFamily,
        bool $discoveryFamily,
    ): array|DatasetExecutionResult {
        if ($resourceRun->core_asset_binding_id !== null) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::ContractMismatch,
                'DataForSEO enrichment does not use provider External Resource bindings.',
                'BINDING_NOT_USED',
            );
        }

        $assetId = $resourceRun->digital_asset_id !== null
            ? (int) $resourceRun->digital_asset_id
            : (int) $collectionRun->digital_asset_id;

        if ($assetId !== (int) $collectionRun->digital_asset_id) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authorization,
                'DataForSEO enrichment cannot target a sibling Digital Asset.',
                'ASSET_SCOPE_MISMATCH',
            );
        }

        $asset = DigitalAsset::query()->with('brand')->find($assetId);
        if (! $asset instanceof DigitalAsset || (string) $asset->type !== 'website') {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::ContractMismatch,
                'DataForSEO enrichment requires a Website Digital Asset.',
                'ASSET_TYPE_MISMATCH',
            );
        }

        if ($collectionRun->brand_id !== null && (int) $asset->brand_id !== (int) $collectionRun->brand_id) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authorization,
                'Website Digital Asset is outside the CollectionRun Brand.',
                'BRAND_SCOPE_MISMATCH',
            );
        }

        $status = $this->resolver->status();
        $integration = $status['integration'];
        if (! $status['configured'] || ! $integration instanceof CoreIntegration) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authentication,
                'DataForSEO Integration is not configured.',
                'INTEGRATION_NOT_CONFIGURED',
            );
        }

        if ($integration->provider !== ProviderRegistry::DATAFORSEO || $integration->status !== CoreIntegration::STATUS_ACTIVE) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authentication,
                'DataForSEO Integration is not active.',
                'INTEGRATION_INACTIVE',
            );
        }

        $auth = DataForSeoAuthStatus::for($integration);
        if ($auth === DataForSeoAuthStatus::NOT_CONFIGURED) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authentication,
                'DataForSEO credentials are not configured.',
                'CREDENTIALS_MISSING',
            );
        }

        $context = is_array($collectionRun->request_context) ? $collectionRun->request_context : [];
        $nested = is_array($context['context'] ?? null) ? $context['context'] : [];
        $paidConsented = (bool) ($nested['paid_enrichment_consented'] ?? $context['paid_enrichment_consented'] ?? false);
        $discoveryRequested = (bool) ($nested['public_discovery'] ?? $context['public_discovery'] ?? false);
        $forceRefresh = (bool) ($context['force_refresh'] ?? false);

        if ($paidFamily && ! $paidConsented) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authorization,
                'Paid DataForSEO enrichment requires explicit operator consent. It is never auto-scheduled.',
                'PAID_CONSENT_REQUIRED',
            );
        }

        if ($discoveryFamily && ! $discoveryRequested) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authorization,
                'Competitor-domain enrichment requires an explicit Public Discovery request.',
                'DISCOVERY_REQUEST_REQUIRED',
            );
        }

        $target = WebsiteDomainTarget::fromAsset($asset);
        if ($paidFamily && $target === null) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::InvalidRequest,
                'Website domain is required for DataForSEO Labs enrichment.',
                'TARGET_REQUIRED',
            );
        }

        if ($paidFamily && ! $asset->hasSeoMarketConfigured()) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::InvalidRequest,
                'Choose the Website SEO market and language before paid DataForSEO enrichment. Silent US/English default is forbidden.',
                'SEO_MARKET_REQUIRED',
            );
        }

        return [
            'asset' => $asset,
            'integration' => $integration,
            'target' => $target ?? '',
            'location_code' => $asset->seo_market_location_code !== null ? (int) $asset->seo_market_location_code : null,
            'language_code' => is_string($asset->seo_market_language_code) ? $asset->seo_market_language_code : null,
            'location_name' => is_string($asset->seo_market_location_name) ? $asset->seo_market_location_name : null,
            'language_name' => is_string($asset->seo_market_language_name) ? $asset->seo_market_language_name : null,
            'paid_consented' => $paidConsented,
            'discovery_requested' => $discoveryRequested,
            'force_refresh' => $forceRefresh,
        ];
    }
}
