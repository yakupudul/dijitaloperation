<?php

namespace App\Services\Collection\Website;

use App\Enums\Collection\CollectionTriggerType;
use App\Models\Collection\CollectionRun;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Collection\Providers\DataForSeo\DataForSeoRequestFamilyCatalog;
use App\Services\Collection\Providers\Website\WebsiteRequestFamilyCatalog;
use App\Services\Collection\StartCollectionService;
use App\Services\Collection\Support\StartCollectionRequest;
use InvalidArgumentException;

/**
 * Starts shared-engine Website production collection for one Website Digital Asset.
 * Does not pull Google/Meta sibling bindings.
 */
final class WebsiteCollectionOrchestrator
{
    public function __construct(
        private readonly StartCollectionService $starter,
    ) {}

    /**
     * @param  list<string>|null  $requestFamilyIds
     * @param  array<string, mixed>  $context
     */
    public function start(
        DigitalAsset $asset,
        ?User $requestedBy = null,
        ?array $requestFamilyIds = null,
        array $context = [],
        bool $includeDataForSeo = false,
        bool $paidEnrichmentConsented = false,
        bool $publicDiscovery = false,
    ): CollectionRun {
        if ((string) $asset->type !== 'website') {
            throw new InvalidArgumentException('Website production collection requires a Website Digital Asset.');
        }

        $providers = ['WEBSITE_DIRECT', 'DOMAIN_DNS_TLS', 'PAGESPEED_TECHNICAL'];
        if ($includeDataForSeo) {
            $providers[] = 'DATAFORSEO';
        }

        $families = $requestFamilyIds;
        if ($families === null) {
            $families = array_values(array_unique(array_merge(
                WebsiteRequestFamilyCatalog::supportedFamilies(),
                [WebsiteRequestFamilyCatalog::FAMILY_WP_REST],
            )));
            if ($includeDataForSeo) {
                $families = array_merge(
                    $families,
                    DataForSeoRequestFamilyCatalog::supportedFamilies(),
                );
            }
        }

        return $this->starter->start(new StartCollectionRequest(
            digitalAsset: $asset,
            triggerType: CollectionTriggerType::Manual,
            requestedBy: $requestedBy,
            bindingIds: [],
            requestFamilyIds: $families,
            providerSources: $providers,
            dateRange: null,
            idempotencyKey: $context['idempotency_key'] ?? null,
            forceRefresh: (bool) ($context['force_refresh'] ?? false),
            context: array_merge($context, [
                'collection_intent' => 'website_production_collection',
                'collection_intent_label' => 'Website production collection',
                'allow_multi_asset_bindings' => false,
                'paid_enrichment_consented' => $paidEnrichmentConsented,
                'public_discovery' => $publicDiscovery,
                'website_intelligence_version' => 'v1',
            ]),
        ));
    }
}
