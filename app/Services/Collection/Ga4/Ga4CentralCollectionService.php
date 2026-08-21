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

    /**
     * @param list<int|string> $externalResourceIds
     */
    public function startInitial(CoreIntegration $integration, array $externalResourceIds, ?User $requestedBy = null): CollectionRun
    {
        return $this->start($integration, $externalResourceIds, self::INITIAL_DAYS, 'ga4_central_initial', $requestedBy);
    }

    /**
     * @param list<int|string> $externalResourceIds
     */
    public function startRestatement(CoreIntegration $integration, array $externalResourceIds, ?User $requestedBy = null): CollectionRun
    {
        return $this->start($integration, $externalResourceIds, self::RESTATEMENT_DAYS, 'ga4_central_restatement', $requestedBy);
    }

    /**
     * @param list<int|string> $externalResourceIds
     */
    private function start(
        CoreIntegration $integration,
        array $externalResourceIds,
        int $days,
        string $intent,
        ?User $requestedBy,
    ): CollectionRun {
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

        $this->registry->load();
        $version = $this->registry->version();
        $families = Ga4RequestFamilyCatalog::centralFamilies();

        $dateRanges = [];
        foreach ($resources as $resource) {
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

            $dateRanges[(int) $resource->id] = [
                'start' => $end->subDays($days - 1)->toDateString(),
                'end' => $end->toDateString(),
                'timezone' => $timezone,
            ];
        }

        $fingerprint = hash('sha256', json_encode([
            'intent' => $intent,
            'integration_id' => $integration->id,
            'resource_ids' => $resources->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'ranges' => $dateRanges,
            'families' => $families,
        ], JSON_THROW_ON_ERROR));

        $active = CollectionRun::query()
            ->where('metadata->plan_fingerprint', $fingerprint)
            ->whereIn('status', [
                CollectionRunStatus::Queued->value,
                CollectionRunStatus::Running->value,
                CollectionRunStatus::Retrying->value,
            ])
            ->orderByDesc('id')
            ->first();
        if ($active instanceof CollectionRun) {
            return $active;
        }

        $run = DB::transaction(function () use (
            $integration,
            $resources,
            $requestedBy,
            $version,
            $families,
            $dateRanges,
            $fingerprint,
            $intent,
            $days,
        ): CollectionRun {
            $datasetCount = count($families) * $resources->count();

            $run = CollectionRun::query()->create([
                'requested_by_user_id' => $requestedBy?->id,
                'customer_id' => null,
                'brand_id' => null,
                'digital_asset_id' => null,
                'trigger_type' => $intent === 'ga4_central_initial'
                    ? CollectionTriggerType::InitialBackfill
                    : CollectionTriggerType::Incremental,
                'status' => CollectionRunStatus::Queued,
                'contract_registry_id' => $this->registry->registryId(),
                'contract_registry_version' => $version,
                'contract_registry_checksum' => $this->registry->checksum(),
                'idempotency_key' => $fingerprint.':'.Str::uuid(),
                'last_activity_at' => now(),
                'resources_total' => $resources->count(),
                'datasets_total' => $datasetCount,
                'request_context' => [
                    'force_refresh' => $intent !== 'ga4_central_initial',
                    'date_range' => null,
                    'request_family_ids' => $families,
                    'provider_sources' => ['GA4'],
                    'context' => [
                        'collection_scope' => 'provider_resource_first',
                        'google_integration_id' => $integration->id,
                        'collection_intent' => $intent,
                        'history_days' => $days,
                        'asset_binding_required' => false,
                    ],
                ],
                'plan_snapshot' => [
                    'resources' => $resources->map(fn (CoreExternalResource $resource): array => [
                        'provider_or_source' => 'GA4',
                        'external_resource_id' => (int) $resource->id,
                        'provider_resource_id' => (string) $resource->external_id,
                        'digital_asset_id' => null,
                        'core_asset_binding_id' => null,
                    ])->all(),
                    'datasets' => [],
                    'dispositions' => [],
                    'contract_registry_version' => $version,
                    'planner_version' => 'ga4-central-resource-first-v1',
                ],
                'metadata' => [
                    'plan_fingerprint' => $fingerprint,
                    'collection_intent' => $intent,
                    'collection_intent_label' => $intent === 'ga4_central_initial'
                        ? 'GA4 Central 486-Day Collection'
                        : 'GA4 Central 14-Day Restatement',
                    'collection_scope' => 'provider_resource_first',
                ],
            ]);

            $planDatasets = [];
            foreach ($resources as $resource) {
                $range = $dateRanges[(int) $resource->id];
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
                        'property_id' => preg_replace('/^properties\//', '', (string) $resource->external_id),
                        'property_timezone' => $range['timezone'],
                    ],
                ]);

                foreach ($families as $familyId) {
                    $definition = Ga4RequestFamilyCatalog::definition($familyId);
                    $datasetId = (string) ($definition['dataset_id'] ?? Ga4RequestFamilyCatalog::primaryDatasetForFamily($familyId));
                    $dateRange = ($definition['requires_date_range'] ?? false)
                        ? ['start' => $range['start'], 'end' => $range['end']]
                        : null;

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
                                'kind' => 'historical',
                                'start' => $dateRange['start'],
                                'end' => $dateRange['end'],
                                'days' => $days,
                            ],
                            'collection_scope' => 'provider_resource_first',
                            'property_timezone' => $range['timezone'],
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
