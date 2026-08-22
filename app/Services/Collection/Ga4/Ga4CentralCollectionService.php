<?php

namespace App\Services\Collection\Ga4;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Collection\CollectionTriggerType;
use App\Enums\Collection\ProgressMode;
use App\Enums\Collection\RequirementLevel;
use App\Events\Collection\CollectionRunStarted;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\User;
use App\Services\Collection\DataContractRegistryLoader;
use App\Services\Collection\Providers\Ga4\Ga4MetadataCompatibilityService;
use App\Services\Collection\Providers\Ga4\Ga4RequestFamilyCatalog;
use App\Services\Collection\StartCollectionService;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Integrations\ProviderRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Starts GA4 collection from discovered provider resources before any Customer/Brand/Asset binding exists.
 * Facts remain in the canonical Data Pool and can be bound to Digital Assets later without recollection.
 */
final class Ga4CentralCollectionService
{
    public const int INITIAL_DAYS = 486;

    public const int RESTATEMENT_DAYS = 14;

    public function __construct(
        private readonly DataContractRegistryLoader $registry,
        private readonly Ga4MetadataCompatibilityService $metadata,
        private readonly StartCollectionService $starter,
    ) {}

    /** @param list<int|string> $externalResourceIds */
    public function startInitial(CoreIntegration $integration, array $externalResourceIds, ?User $requestedBy = null): CollectionRun
    {
        $resources = $this->resolveResources($integration, $externalResourceIds);
        $plans = $resources->map(fn (CoreExternalResource $resource): array => $this->explicitPlan(
            $integration,
            $resource,
            self::INITIAL_DAYS,
            'initial',
        ))->all();

        return $this->startPlans($integration, $plans, $requestedBy);
    }

    /** @param list<int|string> $externalResourceIds */
    public function startRestatement(CoreIntegration $integration, array $externalResourceIds, ?User $requestedBy = null): CollectionRun
    {
        $resources = $this->resolveResources($integration, $externalResourceIds);
        $plans = $resources->map(fn (CoreExternalResource $resource): array => $this->explicitPlan(
            $integration,
            $resource,
            self::RESTATEMENT_DAYS,
            'update',
        ))->all();

        return $this->startPlans($integration, $plans, $requestedBy);
    }

    /**
     * State-aware operator/scheduler entry point.
     * - No successful central history: initial 486-day import.
     * - Partial/failed/cancelled latest attempt: rerun only unfinished families over their original range.
     * - Healthy history: start 13 days before the latest successful coverage end and fill through yesterday.
     *
     * @param list<int|string> $externalResourceIds
     */
    public function startSmartUpdate(CoreIntegration $integration, array $externalResourceIds, ?User $requestedBy = null): CollectionRun
    {
        $resources = $this->resolveResources($integration, $externalResourceIds);
        $plans = $resources->map(fn (CoreExternalResource $resource): array => $this->smartPlan(
            $integration,
            $resource,
        ))->all();

        return $this->startPlans($integration, $plans, $requestedBy);
    }

    /** @param list<int|string> $externalResourceIds
     *  @return Collection<int, CoreExternalResource>
     */
    private function resolveResources(CoreIntegration $integration, array $externalResourceIds): Collection
    {
        if ($integration->provider !== ProviderRegistry::GOOGLE || ! $integration->isActive()) {
            throw new InvalidArgumentException('Google integration is not active.');
        }

        $ids = collect($externalResourceIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            throw new InvalidArgumentException('Select at least one GA4 property.');
        }

        $resources = CoreExternalResource::query()
            ->where('integration_id', $integration->id)
            ->where('provider', ProviderRegistry::GOOGLE)
            ->where('resource_type', GoogleResourceType::GA4_PROPERTY)
            ->where('status', CoreExternalResource::STATUS_AVAILABLE)
            ->whereIn('id', $ids->all())
            ->orderBy('id')
            ->get();

        if ($resources->count() !== $ids->count()) {
            throw new InvalidArgumentException('One or more selected GA4 properties are unavailable or outside this Google integration.');
        }

        return $resources;
    }

    /** @return array<string, mixed> */
    private function explicitPlan(CoreIntegration $integration, CoreExternalResource $resource, int $days, string $mode): array
    {
        [$timezone, $end] = $this->propertyClock($integration, $resource);
        $start = $end->subDays($days - 1);
        $families = Ga4RequestFamilyCatalog::centralFamilies();

        return [
            'resource' => $resource,
            'mode' => $mode,
            'timezone' => $timezone,
            'families' => $families,
            'family_ranges' => [],
            'default_range' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'days' => $days,
        ];
    }

    /** @return array<string, mixed> */
    private function smartPlan(CoreIntegration $integration, CoreExternalResource $resource): array
    {
        [$timezone, $closedEnd] = $this->propertyClock($integration, $resource);

        $runs = CollectionResourceRun::query()
            ->where('provider_or_source', 'GA4')
            ->where('external_resource_id', $resource->id)
            ->whereNull('digital_asset_id')
            ->where('metadata->collection_scope', 'provider_resource_first')
            ->with('datasetRuns')
            ->orderByDesc('id')
            ->get();

        $active = $runs->first(fn (CollectionResourceRun $run): bool => in_array($run->status, [
            CollectionRunStatus::Queued,
            CollectionRunStatus::Running,
            CollectionRunStatus::Retrying,
            CollectionRunStatus::CancellationRequested,
        ], true));

        if ($active instanceof CollectionResourceRun) {
            throw new InvalidArgumentException($resource->display_name.' already has a GA4 collection in progress.');
        }

        $latest = $runs->first();
        $latestCompleted = $runs->first(
            fn (CollectionResourceRun $run): bool => $run->status === CollectionRunStatus::Completed,
        );

        if ($latest instanceof CollectionResourceRun
            && in_array($latest->status, [CollectionRunStatus::Partial, CollectionRunStatus::Failed, CollectionRunStatus::Cancelled], true)
            && (! $latestCompleted instanceof CollectionResourceRun || $latest->id > $latestCompleted->id)) {
            $retryable = $latest->datasetRuns
                ->filter(fn (CollectionDatasetRun $dataset): bool => ! in_array($dataset->status, [
                    CollectionRunStatus::Completed,
                    CollectionRunStatus::Skipped,
                    CollectionRunStatus::NotEligible,
                ], true));

            if ($retryable->isNotEmpty()) {
                $familyRanges = [];
                foreach ($retryable as $dataset) {
                    $range = data_get($dataset->metadata, 'date_range');
                    $familyRanges[(string) $dataset->request_family_id] = is_array($range) ? $range : null;
                }

                $dateRanges = collect($familyRanges)->filter(fn ($range): bool => is_array($range));
                $start = $dateRanges->pluck('start')->filter()->sort()->first();
                $end = $dateRanges->pluck('end')->filter()->sortDesc()->first();
                $days = ($start && $end)
                    ? CarbonImmutable::parse($start, $timezone)->diffInDays(CarbonImmutable::parse($end, $timezone)) + 1
                    : self::RESTATEMENT_DAYS;

                return [
                    'resource' => $resource,
                    'mode' => $latest->status === CollectionRunStatus::Cancelled ? 'resume' : 'repair',
                    'timezone' => $timezone,
                    'families' => $retryable->pluck('request_family_id')->map(fn ($id): string => (string) $id)->unique()->values()->all(),
                    'family_ranges' => $familyRanges,
                    'default_range' => $start && $end ? ['start' => $start, 'end' => $end] : null,
                    'days' => (int) $days,
                ];
            }
        }

        if (! $latestCompleted instanceof CollectionResourceRun) {
            return $this->explicitPlan($integration, $resource, self::INITIAL_DAYS, 'initial');
        }

        $latestCoverageEnd = $latestCompleted->datasetRuns
            ->map(fn (CollectionDatasetRun $dataset) => data_get($dataset->metadata, 'date_range.end'))
            ->filter(fn ($date): bool => is_string($date) && $date !== '')
            ->sortDesc()
            ->first();

        $anchor = is_string($latestCoverageEnd) && $latestCoverageEnd !== ''
            ? CarbonImmutable::parse($latestCoverageEnd, $timezone)->startOfDay()
            : $closedEnd;

        if ($anchor->greaterThan($closedEnd)) {
            $anchor = $closedEnd;
        }

        $start = $anchor->subDays(self::RESTATEMENT_DAYS - 1);
        $days = $start->diffInDays($closedEnd) + 1;

        return [
            'resource' => $resource,
            'mode' => 'update',
            'timezone' => $timezone,
            'families' => Ga4RequestFamilyCatalog::centralFamilies(),
            'family_ranges' => [],
            'default_range' => ['start' => $start->toDateString(), 'end' => $closedEnd->toDateString()],
            'days' => (int) $days,
        ];
    }

    /** @return array{0:string,1:CarbonImmutable} */
    private function propertyClock(CoreIntegration $integration, CoreExternalResource $resource): array
    {
        $externalId = trim((string) $resource->external_id);
        $propertyResourceName = str_starts_with($externalId, 'properties/') ? $externalId : 'properties/'.$externalId;
        $propertyContext = $this->metadata->propertyContext($integration, $propertyResourceName);

        $timezone = is_array($propertyContext) && filled($propertyContext['timeZone'] ?? null)
            ? (string) $propertyContext['timeZone']
            : 'UTC';

        try {
            $end = CarbonImmutable::now($timezone)->subDay()->startOfDay();
        } catch (\Throwable) {
            $timezone = 'UTC';
            $end = CarbonImmutable::now('UTC')->subDay()->startOfDay();
        }

        return [$timezone, $end];
    }

    /**
     * @param list<array<string, mixed>> $plans
     */
    private function startPlans(CoreIntegration $integration, array $plans, ?User $requestedBy): CollectionRun
    {
        $this->registry->load();
        $version = $this->registry->version();

        $modes = collect($plans)->pluck('mode')->unique()->values();
        $runIntent = match (true) {
            $modes->count() > 1 => 'ga4_central_smart',
            $modes->first() === 'initial' => 'ga4_central_initial',
            $modes->first() === 'repair' => 'ga4_central_repair',
            $modes->first() === 'resume' => 'ga4_central_resume',
            default => 'ga4_central_update',
        };
        $runLabel = match ($runIntent) {
            'ga4_central_initial' => 'GA4 Central 486-Day Import',
            'ga4_central_repair' => 'GA4 Central Repair',
            'ga4_central_resume' => 'GA4 Central Resume',
            'ga4_central_update' => 'GA4 Central Smart Update',
            default => 'GA4 Central Smart Import / Update',
        };

        $fingerprintPlans = collect($plans)->map(function (array $plan): array {
            /** @var CoreExternalResource $resource */
            $resource = $plan['resource'];

            return [
                'resource_id' => (int) $resource->id,
                'mode' => $plan['mode'],
                'families' => $plan['families'],
                'family_ranges' => $plan['family_ranges'],
                'default_range' => $plan['default_range'],
            ];
        })->all();

        $fingerprint = hash('sha256', json_encode([
            'intent' => $runIntent,
            'integration_id' => $integration->id,
            'plans' => $fingerprintPlans,
            'registry_version' => $version,
        ], JSON_THROW_ON_ERROR));

        $active = CollectionRun::query()
            ->where('metadata->plan_fingerprint', $fingerprint)
            ->whereIn('status', [
                CollectionRunStatus::Queued->value,
                CollectionRunStatus::Running->value,
                CollectionRunStatus::Retrying->value,
                CollectionRunStatus::CancellationRequested->value,
            ])
            ->orderByDesc('id')
            ->first();
        if ($active instanceof CollectionRun) {
            return $active;
        }

        $run = DB::transaction(function () use (
            $integration,
            $plans,
            $requestedBy,
            $version,
            $fingerprint,
            $runIntent,
            $runLabel,
        ): CollectionRun {
            $datasetCount = collect($plans)->sum(fn (array $plan): int => count($plan['families']));
            $allInitial = collect($plans)->every(fn (array $plan): bool => $plan['mode'] === 'initial');
            $allFamilies = collect($plans)->flatMap(fn (array $plan): array => $plan['families'])->unique()->values()->all();
            $maxDays = (int) collect($plans)->max('days');

            $run = CollectionRun::query()->create([
                'requested_by_user_id' => $requestedBy?->id,
                'customer_id' => null,
                'brand_id' => null,
                'digital_asset_id' => null,
                'trigger_type' => $allInitial ? CollectionTriggerType::InitialBackfill : CollectionTriggerType::Incremental,
                'status' => CollectionRunStatus::Queued,
                'contract_registry_id' => $this->registry->registryId(),
                'contract_registry_version' => $version,
                'contract_registry_checksum' => $this->registry->checksum(),
                'idempotency_key' => $fingerprint.':'.Str::uuid(),
                'last_activity_at' => now(),
                'resources_total' => count($plans),
                'datasets_total' => $datasetCount,
                'request_context' => [
                    'force_refresh' => ! $allInitial,
                    'date_range' => null,
                    'request_family_ids' => $allFamilies,
                    'provider_sources' => ['GA4'],
                    'context' => [
                        'collection_scope' => 'provider_resource_first',
                        'google_integration_id' => $integration->id,
                        'collection_intent' => $runIntent,
                        'history_days' => $maxDays,
                        'asset_binding_required' => false,
                    ],
                ],
                'plan_snapshot' => [
                    'resources' => collect($plans)->map(function (array $plan): array {
                        /** @var CoreExternalResource $resource */
                        $resource = $plan['resource'];

                        return [
                            'provider_or_source' => 'GA4',
                            'external_resource_id' => (int) $resource->id,
                            'provider_resource_id' => (string) $resource->external_id,
                            'digital_asset_id' => null,
                            'core_asset_binding_id' => null,
                            'collection_mode' => $plan['mode'],
                        ];
                    })->all(),
                    'datasets' => [],
                    'dispositions' => [],
                    'contract_registry_version' => $version,
                    'planner_version' => 'ga4-central-resource-first-v2-smart',
                ],
                'metadata' => [
                    'plan_fingerprint' => $fingerprint,
                    'collection_intent' => $runIntent,
                    'collection_intent_label' => $runLabel,
                    'collection_scope' => 'provider_resource_first',
                    'collection_modes' => $modes = collect($plans)->pluck('mode')->unique()->values()->all(),
                ],
            ]);

            $planDatasets = [];
            foreach ($plans as $plan) {
                /** @var CoreExternalResource $resource */
                $resource = $plan['resource'];
                $families = $plan['families'];
                $resourceRun = CollectionResourceRun::query()->create([
                    'collection_run_id' => $run->id,
                    'provider_or_source' => 'GA4',
                    'resource_kind' => 'provider_resource',
                    'external_resource_id' => (int) $resource->id,
                    'digital_asset_id' => null,
                    'core_asset_binding_id' => null,
                    'status' => CollectionRunStatus::Queued,
                    'last_activity_at' => now(),
                    'datasets_total' => count($families),
                    'metadata' => [
                        'capability' => 'ga4',
                        'collection_scope' => 'provider_resource_first',
                        'collection_mode' => $plan['mode'],
                        'property_id' => preg_replace('/^properties\//', '', (string) $resource->external_id),
                        'property_timezone' => $plan['timezone'],
                    ],
                ]);

                foreach ($families as $familyId) {
                    $definition = Ga4RequestFamilyCatalog::definition($familyId);
                    $datasetId = (string) ($definition['dataset_id'] ?? Ga4RequestFamilyCatalog::primaryDatasetForFamily($familyId));
                    $dateRange = null;
                    if ($definition['requires_date_range'] ?? false) {
                        $familyRange = $plan['family_ranges'][$familyId] ?? null;
                        $dateRange = is_array($familyRange) ? $familyRange : $plan['default_range'];
                    }

                    CollectionDatasetRun::query()->create([
                        'collection_run_id' => $run->id,
                        'collection_resource_run_id' => $resourceRun->id,
                        'provider_or_source' => 'GA4',
                        'dataset_contract_id' => $datasetId,
                        'request_family_id' => $familyId,
                        'requirement_level' => RequirementLevel::Required,
                        'contract_registry_version' => $version,
                        'status' => CollectionRunStatus::Queued,
                        'max_attempts' => (int) config('moxdop-collection.default_max_attempts', 3),
                        'progress_mode' => ProgressMode::Indeterminate,
                        'last_activity_at' => now(),
                        'metadata' => [
                            'date_range' => $dateRange,
                            'coverage_target' => $dateRange === null ? null : [
                                'kind' => $plan['mode'] === 'initial' ? 'historical' : 'incremental_restatement',
                                'start' => $dateRange['start'],
                                'end' => $dateRange['end'],
                                'days' => CarbonImmutable::parse($dateRange['start'])->diffInDays(CarbonImmutable::parse($dateRange['end'])) + 1,
                            ],
                            'collection_scope' => 'provider_resource_first',
                            'collection_mode' => $plan['mode'],
                            'property_timezone' => $plan['timezone'],
                        ],
                    ]);

                    $planDatasets[] = [
                        'provider_or_source' => 'GA4',
                        'dataset_contract_id' => $datasetId,
                        'request_family_id' => $familyId,
                        'external_resource_id' => (int) $resource->id,
                        'digital_asset_id' => null,
                        'core_asset_binding_id' => null,
                        'date_range' => $dateRange,
                        'collection_mode' => $plan['mode'],
                    ];
                }
            }

            $snapshot = $run->plan_snapshot ?? [];
            $snapshot['datasets'] = $planDatasets;
            $run->forceFill(['plan_snapshot' => $snapshot])->save();

            return $run->fresh(['resourceRuns', 'datasetRuns']) ?? $run;
        });

        CollectionRunStarted::dispatch($run);
        $this->starter->dispatchEligibleRootJobs($run);

        return $run->fresh() ?? $run;
    }
}
