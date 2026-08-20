<?php

namespace App\Services\Collection\DataForSeo;

use App\Enums\Collection\CollectionTriggerType;
use App\Models\Collection\CollectionRun;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Collection\Providers\DataForSeo\DataForSeoRequestFamilyCatalog;
use App\Services\Collection\StartCollectionService;
use App\Services\Collection\Support\StartCollectionRequest;
use InvalidArgumentException;

/**
 * Starts shared-engine DataForSEO enrichment for one Website Digital Asset.
 * Agency Integration credentials; facts remain asset-scoped. Never auto-scheduled.
 */
final class DataForSeoEnrichmentOrchestrator
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
        bool $paidEnrichmentConsented = false,
        bool $publicDiscovery = false,
        ?array $requestFamilyIds = null,
        array $context = [],
    ): CollectionRun {
        if ((string) $asset->type !== 'website') {
            throw new InvalidArgumentException('DataForSEO enrichment requires a Website Digital Asset.');
        }

        return $this->starter->start(new StartCollectionRequest(
            digitalAsset: $asset,
            triggerType: CollectionTriggerType::Manual,
            requestedBy: $requestedBy,
            bindingIds: [],
            requestFamilyIds: $requestFamilyIds ?? DataForSeoRequestFamilyCatalog::supportedFamilies(),
            providerSources: ['DATAFORSEO'],
            dateRange: null,
            idempotencyKey: $context['idempotency_key'] ?? null,
            forceRefresh: (bool) ($context['force_refresh'] ?? false),
            context: array_merge($context, [
                'collection_intent' => 'dataforseo_production_enrichment',
                'collection_intent_label' => 'DataForSEO production enrichment',
                'allow_multi_asset_bindings' => false,
                'paid_enrichment_consented' => $paidEnrichmentConsented,
                'public_discovery' => $publicDiscovery,
            ]),
        ));
    }
}
