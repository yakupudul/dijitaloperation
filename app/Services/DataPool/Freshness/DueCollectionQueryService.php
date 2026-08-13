<?php

namespace App\Services\DataPool\Freshness;

use App\Enums\DataPool\FreshnessState;
use App\Models\CoreAssetBinding;
use App\Models\DataPool\DatasetMaterialization;
use App\Services\Collection\DataContractRegistryLoader;
use App\Services\DataPool\Freshness\Support\DueCollectionItem;
use Illuminate\Support\Collection;

/**
 * Provider-neutral due-collection query for future Prompt 62 scheduler.
 * DB + contract/policy driven — zero analytical provider calls.
 */
final class DueCollectionQueryService
{
    /**
     * Capability → provider_or_source
     *
     * @var array<string, string>
     */
    private const CAPABILITY_PROVIDER = [
        'ga4' => 'GA4',
        'search_console' => 'SEARCH_CONSOLE',
        'google_ads' => 'GOOGLE_ADS',
        'meta_ads' => 'META_ADS',
    ];

    public function __construct(
        private readonly DataFreshnessPolicyLoader $policies,
        private readonly DataContractRegistryLoader $contracts,
        private readonly IncrementalCoveragePlanner $planner,
    ) {}

    /**
     * @param  array{
     *   customer_id?: ?int,
     *   brand_id?: ?int,
     *   digital_asset_id?: ?int,
     *   provider_sources?: list<string>|null,
     *   include_action_required?: bool,
     *   authorization_ready_by_binding_id?: array<int, bool>,
     *   integrity_blocked_by_dataset_resource?: array<string, bool>
     * }  $filters
     * @return list<DueCollectionItem>
     */
    public function query(array $filters = []): array
    {
        $this->policies->validate();
        $this->contracts->load();

        $bindings = $this->loadBindings($filters);
        if ($bindings === []) {
            return [];
        }

        $materializations = $this->loadMaterializations($bindings);
        $familiesByProvider = $this->indexExecutableFamilies($filters['provider_sources'] ?? null);

        $includeActionRequired = (bool) ($filters['include_action_required'] ?? true);
        $authByBinding = $filters['authorization_ready_by_binding_id'] ?? [];
        $integrityMap = $filters['integrity_blocked_by_dataset_resource'] ?? [];

        $items = [];
        foreach ($bindings as $binding) {
            $capability = (string) $binding->capability;
            $provider = self::CAPABILITY_PROVIDER[$capability] ?? null;
            if ($provider === null) {
                continue;
            }

            $authReady = $authByBinding[(int) $binding->id] ?? true;
            $families = $familiesByProvider[$provider] ?? [];
            foreach ($families as $family) {
                $datasetId = $this->primaryDatasetForFamily((string) $family['id']);
                if ($datasetId === null) {
                    continue;
                }

                $policy = $this->policies->policy($datasetId);
                if ($policy === null) {
                    continue;
                }

                $assetId = (int) ($binding->digital_asset_id ?? 0);
                $resourceId = $binding->external_resource_id !== null ? (int) $binding->external_resource_id : null;
                $mat = $this->findMaterialization($materializations, $datasetId, $assetId, $resourceId);

                $integrityKey = $datasetId.'|'.$assetId.'|'.($resourceId ?? 'null');
                $decision = $this->planner->planDataset($datasetId, $mat, [
                    'authorization_ready' => $authReady,
                    'integrity_blocked' => (bool) ($integrityMap[$integrityKey] ?? false),
                    'reporting_timezone' => $this->resourceTimezone($binding),
                ]);

                if ($decision->executable) {
                    $items[] = new DueCollectionItem(
                        digitalAssetId: $assetId,
                        brandId: $binding->digitalAsset?->brand_id,
                        customerId: $binding->digitalAsset?->brand?->customer_id,
                        coreAssetBindingId: (int) $binding->id,
                        externalResourceId: $resourceId,
                        providerOrSource: $provider,
                        datasetId: $datasetId,
                        requestFamilyId: (string) $family['id'],
                        freshnessState: $decision->freshnessState,
                        reasons: $decision->reasons,
                        dateRange: $decision->dateRange,
                        dueSince: $decision->dateRange['start'] ?? null,
                        priorityCategory: $this->priorityCategory($decision->freshnessState, $decision->reasons),
                        actionRequired: false,
                        policyVersion: $decision->policyVersion,
                    );

                    continue;
                }

                if ($includeActionRequired && $decision->freshnessState === FreshnessState::ActionRequired) {
                    $items[] = new DueCollectionItem(
                        digitalAssetId: $assetId,
                        brandId: $binding->digitalAsset?->brand_id,
                        customerId: $binding->digitalAsset?->brand?->customer_id,
                        coreAssetBindingId: (int) $binding->id,
                        externalResourceId: $resourceId,
                        providerOrSource: $provider,
                        datasetId: $datasetId,
                        requestFamilyId: (string) $family['id'],
                        freshnessState: $decision->freshnessState,
                        reasons: [],
                        dateRange: null,
                        dueSince: null,
                        priorityCategory: 'action_required',
                        actionRequired: true,
                        policyVersion: $decision->policyVersion,
                    );
                }
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<CoreAssetBinding>
     */
    private function loadBindings(array $filters): array
    {
        $query = CoreAssetBinding::query()
            ->with(['digitalAsset.brand', 'externalResource'])
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->whereIn('capability', array_keys(self::CAPABILITY_PROVIDER));

        if (isset($filters['digital_asset_id'])) {
            $query->where('digital_asset_id', (int) $filters['digital_asset_id']);
        }
        if (isset($filters['brand_id'])) {
            $query->whereHas('digitalAsset', fn ($q) => $q->where('brand_id', (int) $filters['brand_id']));
        }
        if (isset($filters['customer_id'])) {
            $query->whereHas('digitalAsset.brand', fn ($q) => $q->where('customer_id', (int) $filters['customer_id']));
        }

        /** @var list<CoreAssetBinding> */
        return $query->orderBy('id')->get()->all();
    }

    /**
     * @param  list<CoreAssetBinding>  $bindings
     * @return Collection<int, DatasetMaterialization>
     */
    private function loadMaterializations(array $bindings): Collection
    {
        $assetIds = [];
        $resourceIds = [];
        foreach ($bindings as $binding) {
            if ($binding->digital_asset_id !== null) {
                $assetIds[] = (int) $binding->digital_asset_id;
            }
            if ($binding->external_resource_id !== null) {
                $resourceIds[] = (int) $binding->external_resource_id;
            }
        }

        if ($assetIds === []) {
            return collect();
        }

        return DatasetMaterialization::query()
            ->whereIn('digital_asset_id', array_values(array_unique($assetIds)))
            ->when($resourceIds !== [], fn ($q) => $q->whereIn('external_resource_id', array_values(array_unique($resourceIds))))
            ->get();
    }

    /**
     * @param  list<string>|null  $providerFilter
     * @return array<string, list<array<string, mixed>>>
     */
    private function indexExecutableFamilies(?array $providerFilter): array
    {
        $indexed = [];
        foreach ($this->contracts->requestFamilies() as $family) {
            $provider = (string) ($family['provider_or_source'] ?? '');
            $status = (string) ($family['status'] ?? '');
            if ($provider === '' || in_array($status, ['DEFERRED', 'UNSUPPORTED', 'UNAVAILABLE', 'DEMO_ONLY'], true)) {
                continue;
            }
            if ($providerFilter !== null && $providerFilter !== [] && ! in_array($provider, $providerFilter, true)) {
                continue;
            }
            if (($family['id'] ?? null) === 'GSC_RF_APPEARANCE_DAILY') {
                continue;
            }
            if (($family['id'] ?? null) === 'GSC_RF_URL_INSPECTION') {
                continue;
            }
            $indexed[$provider][] = $family;
        }

        return $indexed;
    }

    private function primaryDatasetForFamily(string $familyId): ?string
    {
        foreach ($this->contracts->requirements() as $requirement) {
            if (($requirement['request_family'] ?? null) === $familyId) {
                $dataset = $requirement['dataset'] ?? null;
                if (is_string($dataset) && $dataset !== '') {
                    return $dataset;
                }
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, DatasetMaterialization>  $materializations
     */
    private function findMaterialization(
        Collection $materializations,
        string $datasetId,
        int $digitalAssetId,
        ?int $externalResourceId,
    ): ?DatasetMaterialization {
        return $materializations->first(function (DatasetMaterialization $row) use ($datasetId, $digitalAssetId, $externalResourceId): bool {
            if ($row->dataset_id !== $datasetId || (int) $row->digital_asset_id !== $digitalAssetId) {
                return false;
            }
            if ($externalResourceId === null) {
                return $row->external_resource_id === null;
            }

            return (int) $row->external_resource_id === $externalResourceId;
        });
    }

    private function resourceTimezone(CoreAssetBinding $binding): ?string
    {
        $meta = is_array($binding->externalResource?->metadata) ? $binding->externalResource->metadata : [];
        foreach (['timezone', 'timezone_name', 'timeZone', 'time_zone'] as $key) {
            if (is_string($meta[$key] ?? null) && $meta[$key] !== '') {
                return (string) $meta[$key];
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $reasons
     */
    private function priorityCategory(FreshnessState $state, array $reasons): string
    {
        if ($state === FreshnessState::Stale) {
            return 'stale';
        }
        if (in_array('GAP_RECOVERY', $reasons, true)) {
            return 'gap_recovery';
        }
        if (in_array('CATCH_UP', $reasons, true)) {
            return 'catch_up';
        }
        if (in_array('NEW_COVERAGE', $reasons, true)) {
            return 'new_coverage';
        }
        if (in_array('LATE_DATA_REPROCESS', $reasons, true)) {
            return 'reprocess';
        }
        if (in_array('SNAPSHOT_REFRESH', $reasons, true)) {
            return 'snapshot';
        }

        return 'due';
    }
}
