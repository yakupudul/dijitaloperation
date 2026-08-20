<?php

namespace App\Services\Collection;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Collection\CollectionTriggerType;
use App\Enums\Collection\PlanDisposition;
use App\Enums\Collection\RequirementLevel;
use App\Models\CoreAssetBinding;
use App\Models\DataPool\DatasetMaterialization;
use App\Models\DigitalAsset;
use App\Services\Collection\Providers\MetaAds\MetaAdsRequestFamilyCatalog;
use App\Services\Collection\Support\StartCollectionRequest;
use App\Services\DataPool\Freshness\IncrementalCoveragePlanner;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Builds a deterministic CollectionPlan from Data Contract Registry + scope.
 * Does not call providers.
 */
final class CollectionPlanner
{
    /**
     * Capability → provider_or_source mapping for bound resources.
     *
     * @var array<string, string>
     */
    private const CAPABILITY_PROVIDER = [
        'ga4' => 'GA4',
        'search_console' => 'SEARCH_CONSOLE',
        'google_ads' => 'GOOGLE_ADS',
        'meta_ads' => 'META_ADS',
        'google_business_profile' => 'GBP',
    ];

    /**
     * Source Search Console Data Contract V1 explicitly excludes these from V1 collection
     * even when the unified registry lists a COLLECTION_READY family stub.
     *
     * @var list<string>
     */
    private const GSC_SOURCE_CONTRACT_EXCLUDED_FAMILIES = [
        'GSC_RF_APPEARANCE_DAILY',
    ];

    /**
     * Non-executable registry family statuses.
     *
     * @var list<string>
     */
    private const NON_EXECUTABLE_FAMILY_STATUSES = [
        'DEFERRED',
        'UNSUPPORTED',
        'UNAVAILABLE',
        'DEMO_ONLY',
    ];

    public function __construct(
        private readonly DataContractRegistryLoader $registry,
        private readonly HistoricalRangeResolver $ranges = new HistoricalRangeResolver,
        private readonly CoverageSatisfactionChecker $coverage = new CoverageSatisfactionChecker,
        private readonly ?IncrementalCoveragePlanner $incrementalPlanner = null,
    ) {}

    /**
     * @return array{
     *   resources: list<array<string, mixed>>,
     *   datasets: list<array<string, mixed>>,
     *   dispositions: list<array<string, mixed>>,
     *   contract_registry_id: string,
     *   contract_registry_version: int,
     *   contract_registry_checksum: string
     * }
     */
    public function plan(StartCollectionRequest $request): array
    {
        $this->registry->load();

        $asset = $request->digitalAsset;
        $bindings = $this->resolveBindings($asset, $request->bindingIds, $request->context);

        if ($bindings === []) {
            throw new InvalidArgumentException('No active asset bindings in collection scope.');
        }

        $resources = [];
        $datasets = [];
        $dispositions = [];

        $familiesByProvider = $this->indexRequestFamilies();
        $materializations = $this->loadMaterializations($bindings);

        foreach ($bindings as $binding) {
            $capability = (string) $binding->capability;
            $provider = self::CAPABILITY_PROVIDER[$capability] ?? strtoupper($capability);

            if ($request->providerSources !== null && ! in_array($provider, $request->providerSources, true)) {
                $dispositions[] = [
                    'type' => PlanDisposition::SkippedProviderFilter->value,
                    'binding_id' => $binding->id,
                    'provider_or_source' => $provider,
                ];

                continue;
            }

            if ($provider === 'GBP') {
                $dispositions[] = [
                    'type' => PlanDisposition::CollectorUnavailable->value,
                    'binding_id' => $binding->id,
                    'provider_or_source' => $provider,
                    'reason' => 'No production GBP analytical collector',
                ];

                continue;
            }

            $resourceKey = 'binding:'.$binding->id;
            $resources[$resourceKey] = [
                'key' => $resourceKey,
                'provider_or_source' => $provider,
                'resource_kind' => 'bound_provider_resource',
                'external_resource_id' => $binding->external_resource_id,
                'digital_asset_id' => $binding->digital_asset_id ?? $asset->id,
                'core_asset_binding_id' => $binding->id,
                'capability' => $capability,
            ];

            $families = $familiesByProvider[$provider] ?? [];
            if ($families === []) {
                $dispositions[] = [
                    'type' => PlanDisposition::CollectorUnavailable->value,
                    'binding_id' => $binding->id,
                    'provider_or_source' => $provider,
                    'reason' => 'No registry request families for provider',
                ];

                continue;
            }

            foreach ($families as $family) {
                $familyId = (string) ($family['id'] ?? '');
                if ($familyId === '') {
                    continue;
                }

                if ($request->requestFamilyIds !== null && ! in_array($familyId, $request->requestFamilyIds, true)) {
                    continue;
                }

                $status = (string) ($family['status'] ?? '');
                if (in_array($status, self::NON_EXECUTABLE_FAMILY_STATUSES, true)) {
                    $dispositions[] = [
                        'type' => match ($status) {
                            'DEFERRED' => PlanDisposition::Deferred->value,
                            'UNSUPPORTED' => PlanDisposition::Unsupported->value,
                            default => PlanDisposition::Unsupported->value,
                        },
                        'request_family_id' => $familyId,
                        'provider_or_source' => $provider,
                        'family_status' => $status,
                    ];

                    continue;
                }

                if (in_array($familyId, self::GSC_SOURCE_CONTRACT_EXCLUDED_FAMILIES, true)) {
                    $dispositions[] = [
                        'type' => PlanDisposition::SkippedSourceContract->value,
                        'request_family_id' => $familyId,
                        'provider_or_source' => $provider,
                        'reason' => 'SEARCH_CONSOLE_DATA_CONTRACT_V1 excludes searchAppearance collection',
                    ];

                    continue;
                }

                $level = $this->requirementLevelForFamily($familyId);
                $eligibility = $this->eligibilityForFamily($family, $request);
                $datasetId = $this->primaryDatasetForFamily($familyId) ?? $familyId;
                $requirements = $this->requirementsForFamily($familyId);
                $coverageTarget = $this->ranges->resolveForRequirements($requirements);
                $catalogCoverage = $this->catalogCoverageTarget($familyId, $datasetId);
                if ($catalogCoverage !== null && ($coverageTarget['kind'] ?? '') !== 'historical') {
                    $coverageTarget = $catalogCoverage;
                }
                $requirementIds = array_values(array_filter(array_map(
                    static fn (array $r): ?string => is_string($r['id'] ?? null) ? (string) $r['id'] : null,
                    $requirements,
                )));

                if ($eligibility === CollectionRunStatus::NotEligible) {
                    $datasets[] = [
                        'resource_key' => $resourceKey,
                        'provider_or_source' => $provider,
                        'dataset_contract_id' => $datasetId,
                        'request_family_id' => $familyId,
                        'requirement_ids' => $requirementIds,
                        'requirement_level' => $level->value,
                        'planned_status' => CollectionRunStatus::NotEligible->value,
                        'plan_disposition' => PlanDisposition::NotEligible->value,
                        'date_range' => null,
                        'coverage_target' => $coverageTarget,
                        'depends_on_request_family_ids' => $this->familyDependencies($familyId),
                        'core_asset_binding_id' => $binding->id,
                        'digital_asset_id' => $binding->digital_asset_id ?? $asset->id,
                        'external_resource_id' => $binding->external_resource_id,
                        'plan_disposition_detail' => [
                            'type' => PlanDisposition::NotEligible->value,
                            'request_family_id' => $familyId,
                            'binding_id' => $binding->id,
                        ],
                    ];

                    continue;
                }

                $materialization = $this->findMaterialization(
                    $materializations,
                    $datasetId,
                    (int) ($binding->digital_asset_id ?? $asset->id),
                    $binding->external_resource_id !== null ? (int) $binding->external_resource_id : null,
                );

                if ($request->triggerType === CollectionTriggerType::Incremental) {
                    $incremental = $this->planIncrementalDataset(
                        $request,
                        $binding,
                        $datasetId,
                        $materialization,
                    );

                    $plannedStatus = $incremental['executable']
                        ? CollectionRunStatus::Queued->value
                        : match ($incremental['plan_disposition']) {
                            PlanDisposition::AlreadySatisfied->value => CollectionRunStatus::Skipped->value,
                            PlanDisposition::NotEligible->value => CollectionRunStatus::NotEligible->value,
                            PlanDisposition::ActionRequired->value => CollectionRunStatus::NotEligible->value,
                            PlanDisposition::IntegrityBlocked->value => CollectionRunStatus::NotEligible->value,
                            PlanDisposition::ProviderLimited->value => CollectionRunStatus::NotEligible->value,
                            default => CollectionRunStatus::Skipped->value,
                        };

                    $datasets[] = [
                        'resource_key' => $resourceKey,
                        'provider_or_source' => $provider,
                        'dataset_contract_id' => $datasetId,
                        'request_family_id' => $familyId,
                        'requirement_ids' => $requirementIds,
                        'requirement_level' => $level->value,
                        'planned_status' => $plannedStatus,
                        'plan_disposition' => $incremental['plan_disposition'],
                        'date_range' => $incremental['date_range'],
                        'coverage_target' => $coverageTarget,
                        'depends_on_request_family_ids' => $this->familyDependencies($familyId),
                        'core_asset_binding_id' => $binding->id,
                        'digital_asset_id' => $binding->digital_asset_id ?? $asset->id,
                        'external_resource_id' => $binding->external_resource_id,
                        'plan_disposition_detail' => $incremental['plan_disposition_detail'],
                    ];

                    continue;
                }

                $satisfaction = $this->coverage->evaluate(
                    $materialization,
                    $coverageTarget,
                    $request->forceRefresh,
                );

                if ($satisfaction['disposition'] === PlanDisposition::AlreadySatisfied->value) {
                    $datasets[] = [
                        'resource_key' => $resourceKey,
                        'provider_or_source' => $provider,
                        'dataset_contract_id' => $datasetId,
                        'request_family_id' => $familyId,
                        'requirement_ids' => $requirementIds,
                        'requirement_level' => $level->value,
                        'planned_status' => CollectionRunStatus::Skipped->value,
                        'plan_disposition' => PlanDisposition::AlreadySatisfied->value,
                        'date_range' => null,
                        'coverage_target' => $coverageTarget,
                        'depends_on_request_family_ids' => $this->familyDependencies($familyId),
                        'core_asset_binding_id' => $binding->id,
                        'digital_asset_id' => $binding->digital_asset_id ?? $asset->id,
                        'external_resource_id' => $binding->external_resource_id,
                        'plan_disposition_detail' => [
                            'type' => PlanDisposition::AlreadySatisfied->value,
                            'request_family_id' => $familyId,
                            'binding_id' => $binding->id,
                            'reason' => $satisfaction['reason'],
                            'existing_coverage' => $satisfaction['existing_coverage'],
                        ],
                    ];

                    continue;
                }

                $dateRange = $satisfaction['date_range'];
                if ($dateRange === null && $coverageTarget['kind'] === 'historical') {
                    $dateRange = [
                        'start' => $coverageTarget['start'],
                        'end' => $coverageTarget['end'],
                    ];
                }

                $datasets[] = [
                    'resource_key' => $resourceKey,
                    'provider_or_source' => $provider,
                    'dataset_contract_id' => $datasetId,
                    'request_family_id' => $familyId,
                    'requirement_ids' => $requirementIds,
                    'requirement_level' => $level->value,
                    'planned_status' => CollectionRunStatus::Queued->value,
                    'plan_disposition' => PlanDisposition::Eligible->value,
                    'date_range' => $dateRange,
                    'coverage_target' => $coverageTarget,
                    'depends_on_request_family_ids' => $this->familyDependencies($familyId),
                    'core_asset_binding_id' => $binding->id,
                    'digital_asset_id' => $binding->digital_asset_id ?? $asset->id,
                    'external_resource_id' => $binding->external_resource_id,
                    'plan_disposition_detail' => [
                        'type' => PlanDisposition::Eligible->value,
                        'request_family_id' => $familyId,
                        'binding_id' => $binding->id,
                        'reason' => $satisfaction['reason'],
                    ],
                ];
            }
        }

        if ($datasets === []) {
            throw new InvalidArgumentException('Collection plan produced zero datasets for the given scope.');
        }

        return [
            'resources' => array_values($resources),
            'datasets' => $datasets,
            'dispositions' => $dispositions,
            'contract_registry_id' => $this->registry->registryId(),
            'contract_registry_version' => $this->registry->version(),
            'contract_registry_checksum' => $this->registry->checksum(),
        ];
    }

    /**
     * @param  list<int>  $bindingIds
     * @param  array<string, mixed>  $context
     * @return list<CoreAssetBinding>
     */
    private function resolveBindings(DigitalAsset $asset, array $bindingIds, array $context): array
    {
        $allowMultiAsset = (bool) ($context['allow_multi_asset_bindings'] ?? false);

        $query = CoreAssetBinding::query()
            ->with(['digitalAsset.brand', 'externalResource'])
            ->where('status', CoreAssetBinding::STATUS_ACTIVE);

        if ($bindingIds !== []) {
            $query->whereIn('id', $bindingIds);
            if (! $allowMultiAsset) {
                $query->where('digital_asset_id', $asset->id);
            }
        } else {
            $query->where('digital_asset_id', $asset->id);
        }

        $asset->loadMissing('brand');
        $anchorBrandId = $asset->brand_id !== null ? (int) $asset->brand_id : null;
        $anchorCustomerId = $asset->brand?->customer_id !== null ? (int) $asset->brand->customer_id : null;

        /** @var list<CoreAssetBinding> $eligible */
        $eligible = [];
        foreach ($query->orderBy('id')->get() as $binding) {
            $candidate = $binding->digitalAsset;
            if (! $candidate instanceof DigitalAsset) {
                continue;
            }

            $requireSameBrand = in_array(
                (string) $binding->capability,
                CollectionBindingScope::GOOGLE_SAME_BRAND_CAPABILITIES,
                true,
            );

            if (! CollectionBindingScope::anchorMayTargetAsset(
                (int) $asset->id,
                $anchorBrandId,
                $anchorCustomerId,
                $candidate,
                $allowMultiAsset,
                $requireSameBrand,
            )) {
                continue;
            }

            $eligible[] = $binding;
        }

        return $eligible;
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

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function indexRequestFamilies(): array
    {
        $indexed = [];
        foreach ($this->registry->requestFamilies() as $family) {
            $provider = (string) ($family['provider_or_source'] ?? '');
            if ($provider === '') {
                continue;
            }
            $indexed[$provider][] = $family;
        }

        return $indexed;
    }

    private function requirementLevelForFamily(string $familyId): RequirementLevel
    {
        $levels = [];
        foreach ($this->registry->requirements() as $requirement) {
            if (($requirement['request_family'] ?? null) === $familyId) {
                $levels[] = (string) ($requirement['requirement_level'] ?? 'REQUIRED');
            }
        }

        if (in_array('REQUIRED', $levels, true) || $levels === []) {
            return RequirementLevel::Required;
        }
        if (in_array('CONDITIONAL', $levels, true)) {
            return RequirementLevel::Conditional;
        }

        return RequirementLevel::Optional;
    }

    /**
     * @param  array<string, mixed>  $family
     */
    private function eligibilityForFamily(array $family, StartCollectionRequest $request): ?CollectionRunStatus
    {
        $familyId = (string) ($family['id'] ?? '');

        // Controlled URL Inspection requires explicit priority targets in the start context.
        if ($familyId === 'GSC_RF_URL_INSPECTION') {
            $targets = $request->context['url_inspection_targets'] ?? [];
            if (! is_array($targets) || $targets === []) {
                return CollectionRunStatus::NotEligible;
            }
        }

        return null;
    }

    private function primaryDatasetForFamily(string $familyId): ?string
    {
        foreach ($this->registry->requirements() as $requirement) {
            if (($requirement['request_family'] ?? null) === $familyId) {
                $dataset = $requirement['dataset'] ?? null;
                if (is_string($dataset) && $dataset !== '') {
                    return $dataset;
                }
            }
        }

        return $this->catalogPrimaryDataset($familyId);
    }

    private function catalogPrimaryDataset(string $familyId): ?string
    {
        if (! in_array($familyId, MetaAdsRequestFamilyCatalog::supportedFamilies(), true)) {
            return null;
        }

        $ids = MetaAdsRequestFamilyCatalog::definition($familyId)['dataset_ids'] ?? [];
        $primary = $ids[0] ?? null;

        return is_string($primary) && $primary !== '' ? $primary : null;
    }

    /**
     * When registry requirements omit historical_depth but the Meta catalog requires a date range,
     * use the physical dataset history_policy so Insights families are not planned without a window.
     *
     * @return array<string, mixed>|null
     */
    private function catalogCoverageTarget(string $familyId, string $datasetId): ?array
    {
        if (! in_array($familyId, MetaAdsRequestFamilyCatalog::supportedFamilies(), true)) {
            return null;
        }

        $definition = MetaAdsRequestFamilyCatalog::definition($familyId);
        if (! ($definition['requires_date_range'] ?? false)) {
            return null;
        }

        $dataset = $this->registry->dataset($datasetId);
        $history = is_array($dataset['history_policy'] ?? null) ? $dataset['history_policy'] : null;
        if ($history === null) {
            return null;
        }

        $resolved = $this->ranges->resolve($history);

        return ($resolved['kind'] ?? '') === 'historical' ? $resolved : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function requirementsForFamily(string $familyId): array
    {
        $out = [];
        foreach ($this->registry->requirements() as $requirement) {
            if (($requirement['request_family'] ?? null) === $familyId) {
                $out[] = $requirement;
            }
        }

        return $out;
    }

    /**
     * @return array{
     *   executable: bool,
     *   plan_disposition: string,
     *   date_range: ?array{start: string, end: string},
     *   plan_disposition_detail: array<string, mixed>
     * }
     */
    private function planIncrementalDataset(
        StartCollectionRequest $request,
        CoreAssetBinding $binding,
        string $datasetId,
        ?DatasetMaterialization $materialization,
    ): array {
        $planner = $this->incrementalPlanner ?? app(IncrementalCoveragePlanner::class);

        $authMap = $request->context['authorization_ready_by_binding_id'] ?? [];
        $integrityMap = $request->context['integrity_blocked_by_dataset_resource'] ?? [];
        $assetId = (int) ($binding->digital_asset_id ?? $request->digitalAsset->id);
        $resourceId = $binding->external_resource_id !== null ? (int) $binding->external_resource_id : null;
        $integrityKey = $datasetId.'|'.$assetId.'|'.($resourceId ?? 'null');

        $reportingTimezone = null;
        $meta = is_array($binding->externalResource?->metadata) ? $binding->externalResource->metadata : [];
        foreach (['timezone', 'timezone_name', 'timeZone', 'time_zone'] as $key) {
            if (is_string($meta[$key] ?? null) && $meta[$key] !== '') {
                $reportingTimezone = (string) $meta[$key];
                break;
            }
        }

        $decision = $planner->planDataset($datasetId, $materialization, [
            'authorization_ready' => $authMap[(int) $binding->id] ?? true,
            'integrity_blocked' => (bool) ($integrityMap[$integrityKey] ?? false),
            'reporting_timezone' => $reportingTimezone,
        ]);

        return [
            'executable' => $decision->executable,
            'plan_disposition' => $decision->planDisposition->value,
            'date_range' => $decision->dateRange,
            'plan_disposition_detail' => [
                'type' => $decision->planDisposition->value,
                'request_family_id' => null,
                'binding_id' => $binding->id,
                'reason' => $decision->reasonSummary,
                'freshness_state' => $decision->freshnessState->value,
                'incremental_reasons' => $decision->reasons,
                'requested_intervals' => $decision->requestedIntervals,
                'freshness_policy_version' => $decision->policyVersion,
                'details' => $decision->details,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function familyDependencies(string $familyId): array
    {
        $deps = [];
        foreach ($this->registry->requirements() as $requirement) {
            if (($requirement['request_family'] ?? null) !== $familyId) {
                continue;
            }
            foreach ($requirement['dependencies'] ?? [] as $dependency) {
                if (! is_array($dependency)) {
                    continue;
                }
                $reqId = $dependency['requirement_id'] ?? null;
                if (! is_string($reqId) || $reqId === '') {
                    continue;
                }
                foreach ($this->registry->requirements() as $depReq) {
                    if (($depReq['id'] ?? null) === $reqId && is_string($depReq['request_family'] ?? null)) {
                        $deps[] = (string) $depReq['request_family'];
                    }
                }
            }
        }

        return array_values(array_unique($deps));
    }
}
