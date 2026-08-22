<?php

namespace App\Services\Collection\SearchConsole;

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
use App\Services\Collection\Providers\SearchConsole\SearchConsoleApiClient;
use App\Services\Collection\Providers\SearchConsole\SearchConsoleCentralDatasetExecutor;
use App\Services\Collection\Providers\SearchConsole\SearchConsoleProviderCapabilities;
use App\Services\Collection\Providers\SearchConsole\SearchConsoleRequestFamilyCatalog;
use App\Services\Collection\StartCollectionService;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Integrations\ProviderRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Starts Search Console collection from discovered Google provider resources before
 * Customer / Brand / Digital Asset binding exists.
 */
final class SearchConsoleCentralCollectionService
{
    public const int INITIAL_DAYS = 486;
    public const int RESTATEMENT_DAYS = 7;
    public const int FINAL_LAG_DAYS = 3;

    public function __construct(
        private readonly DataContractRegistryLoader $registry,
        private readonly SearchConsoleApiClient $api,
        private readonly StartCollectionService $starter,
    ) {}

    /** @param list<int|string> $externalResourceIds */
    public function startSmartUpdate(CoreIntegration $integration, array $externalResourceIds, ?User $requestedBy = null): CollectionRun
    {
        $resources = $this->resolveResources($integration, $externalResourceIds);
        $plans = $resources->map(fn (CoreExternalResource $resource): array => $this->smartPlan($integration, $resource))->all();

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
            throw new InvalidArgumentException('En az bir Search Console mülkü seçin.');
        }

        $resources = CoreExternalResource::query()
            ->where('integration_id', $integration->id)
            ->where('provider', ProviderRegistry::GOOGLE)
            ->where('resource_type', GoogleResourceType::GSC_PROPERTY)
            ->where('status', CoreExternalResource::STATUS_AVAILABLE)
            ->whereIn('id', $ids->all())
            ->orderBy('id')
            ->get();

        if ($resources->count() !== $ids->count()) {
            throw new InvalidArgumentException('Seçilen Search Console mülklerinden biri kullanılamıyor veya bu Google entegrasyonuna ait değil.');
        }

        return $resources;
    }

    /** @return array<string, mixed> */
    private function smartPlan(CoreIntegration $integration, CoreExternalResource $resource): array
    {
        $end = $this->finalDataEnd();
        $runs = CollectionResourceRun::query()
            ->where('provider_or_source', 'SEARCH_CONSOLE')
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
            throw new InvalidArgumentException($resource->display_name.' için Search Console aktarımı zaten devam ediyor.');
        }

        $latest = $runs->first();
        $completed = $runs->first(fn (CollectionResourceRun $run): bool => $run->status === CollectionRunStatus::Completed);

        if ($latest instanceof CollectionResourceRun
            && in_array($latest->status, [CollectionRunStatus::Partial, CollectionRunStatus::Failed, CollectionRunStatus::Cancelled], true)
            && (! $completed instanceof CollectionResourceRun || $latest->id > $completed->id)) {
            $retry = $latest->datasetRuns
                ->filter(fn (CollectionDatasetRun $dataset): bool => ! in_array($dataset->status, [
                    CollectionRunStatus::Completed,
                    CollectionRunStatus::Skipped,
                    CollectionRunStatus::NotEligible,
                ], true))
                ->map(fn (CollectionDatasetRun $dataset): array => [
                    'request_family_id' => (string) $dataset->request_family_id,
                    'dataset_id' => (string) $dataset->dataset_contract_id,
                    'date_range' => data_get($dataset->metadata, 'date_range'),
                    'central_definition' => data_get($dataset->metadata, 'central_definition'),
                    'search_type' => data_get($dataset->metadata, 'search_type', 'web'),
                ])
                ->values()
                ->all();

            if ($retry !== []) {
                return [
                    'resource' => $resource,
                    'mode' => $latest->status === CollectionRunStatus::Cancelled ? 'resume' : 'repair',
                    'dataset_plans' => $retry,
                    'days' => $this->planDays($retry),
                    'active_search_types' => collect($retry)->pluck('search_type')->filter()->unique()->values()->all(),
                ];
            }
        }

        if (! $completed instanceof CollectionResourceRun) {
            $start = $end->subDays(self::INITIAL_DAYS - 1);
            $activeSearchTypes = $this->detectActiveSearchTypes($integration, $resource, $end);

            return [
                'resource' => $resource,
                'mode' => 'initial',
                'dataset_plans' => $this->datasetPlans($activeSearchTypes, $start, $end),
                'days' => self::INITIAL_DAYS,
                'active_search_types' => $activeSearchTypes,
            ];
        }

        $latestCoverageEnd = $completed->datasetRuns
            ->filter(fn (CollectionDatasetRun $dataset): bool => $dataset->request_family_id === SearchConsoleCentralDatasetExecutor::FAMILY_ANALYTICS)
            ->map(fn (CollectionDatasetRun $dataset) => data_get($dataset->metadata, 'date_range.end'))
            ->filter(fn ($date): bool => is_string($date) && $date !== '')
            ->sortDesc()
            ->first();

        $anchor = is_string($latestCoverageEnd) && $latestCoverageEnd !== ''
            ? CarbonImmutable::parse($latestCoverageEnd, SearchConsoleProviderCapabilities::REPORTING_TIMEZONE)->startOfDay()
            : $end;
        if ($anchor->greaterThan($end)) {
            $anchor = $end;
        }

        $start = $anchor->subDays(self::RESTATEMENT_DAYS - 1);
        $activeSearchTypes = collect($completed->datasetRuns)
            ->pluck('metadata.search_type')
            ->filter(fn ($type): bool => is_string($type) && $type !== '')
            ->unique()
            ->values()
            ->all();
        if ($activeSearchTypes === []) {
            $activeSearchTypes = $this->detectActiveSearchTypes($integration, $resource, $end);
        }

        return [
            'resource' => $resource,
            'mode' => 'update',
            'dataset_plans' => $this->datasetPlans($activeSearchTypes, $start, $end),
            'days' => $start->diffInDays($end) + 1,
            'active_search_types' => $activeSearchTypes,
        ];
    }

    private function finalDataEnd(): CarbonImmutable
    {
        $lag = max(2, (int) config('moxdop-gsc-central.final_lag_days', self::FINAL_LAG_DAYS));

        return CarbonImmutable::now(SearchConsoleProviderCapabilities::REPORTING_TIMEZONE)
            ->subDays($lag)
            ->startOfDay();
    }

    /** @return list<string> */
    private function detectActiveSearchTypes(CoreIntegration $integration, CoreExternalResource $resource, CarbonImmutable $end): array
    {
        $active = ['web'];
        $start = $end->subDays(29)->toDateString();
        $endDate = $end->toDateString();

        foreach (['image', 'video', 'news', 'discover', 'googleNews'] as $type) {
            try {
                $response = $this->api->searchAnalyticsQuery($integration, (string) $resource->external_id, [
                    'startDate' => $start,
                    'endDate' => $endDate,
                    'dimensions' => ['date'],
                    'type' => $type,
                    'dataState' => 'final',
                    'aggregationType' => 'byProperty',
                    'rowLimit' => 1,
                    'startRow' => 0,
                ]);
                if (! $response->successful()) {
                    continue;
                }
                $rows = $response->json('rows');
                if (is_array($rows) && $rows !== []) {
                    $active[] = $type;
                }
            } catch (Throwable) {
                // Optional search surfaces must never block the canonical Web import.
            }
        }

        return array_values(array_unique($active));
    }

    /**
     * @param list<string> $activeSearchTypes
     * @return list<array<string, mixed>>
     */
    private function datasetPlans(array $activeSearchTypes, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $range = ['start' => $start->toDateString(), 'end' => $end->toDateString()];
        $plans = [];

        foreach (SearchConsoleRequestFamilyCatalog::centralPerformanceFamilies() as $familyId) {
            $base = SearchConsoleRequestFamilyCatalog::definition($familyId);
            foreach (SearchConsoleRequestFamilyCatalog::compatibleSearchTypes($familyId, $activeSearchTypes) as $searchType) {
                $definition = $base;
                $definition['search_type'] = $searchType;
                $definition['data_state'] = 'final';
                $plans[] = [
                    'request_family_id' => SearchConsoleCentralDatasetExecutor::FAMILY_ANALYTICS,
                    'dataset_id' => (string) $definition['dataset_id'],
                    'date_range' => $range,
                    'central_definition' => $definition,
                    'search_type' => $searchType,
                    'source_family_id' => $familyId,
                ];
            }
        }

        $plans[] = [
            'request_family_id' => SearchConsoleCentralDatasetExecutor::FAMILY_SITEMAPS,
            'dataset_id' => 'gsc_sitemap_snapshot',
            'date_range' => null,
            'central_definition' => ['kind' => 'sitemaps'],
            'search_type' => null,
            'source_family_id' => SearchConsoleRequestFamilyCatalog::FAMILY_SITEMAPS,
        ];
        $plans[] = [
            'request_family_id' => SearchConsoleCentralDatasetExecutor::FAMILY_SITE_METADATA,
            'dataset_id' => 'gsc_site_metadata',
            'date_range' => null,
            'central_definition' => ['kind' => 'site_metadata'],
            'search_type' => null,
            'source_family_id' => SearchConsoleRequestFamilyCatalog::FAMILY_SEARCH_ANALYTICS,
        ];

        return $plans;
    }

    /** @param list<array<string, mixed>> $plans */
    private function planDays(array $plans): int
    {
        $ranges = collect($plans)->pluck('date_range')->filter(fn ($range): bool => is_array($range));
        $start = $ranges->pluck('start')->filter()->sort()->first();
        $end = $ranges->pluck('end')->filter()->sortDesc()->first();
        if (! is_string($start) || ! is_string($end)) {
            return self::RESTATEMENT_DAYS;
        }

        return CarbonImmutable::parse($start)->diffInDays(CarbonImmutable::parse($end)) + 1;
    }

    /** @param list<array<string, mixed>> $plans */
    private function startPlans(CoreIntegration $integration, array $plans, ?User $requestedBy): CollectionRun
    {
        $this->registry->load();
        $version = $this->registry->version();
        $modes = collect($plans)->pluck('mode')->unique()->values();
        $runIntent = match (true) {
            $modes->count() > 1 => 'gsc_central_smart',
            $modes->first() === 'initial' => 'gsc_central_initial',
            $modes->first() === 'repair' => 'gsc_central_repair',
            $modes->first() === 'resume' => 'gsc_central_resume',
            default => 'gsc_central_update',
        };
        $runLabel = match ($runIntent) {
            'gsc_central_initial' => 'Search Console Merkezi 486 Günlük Aktarım',
            'gsc_central_repair' => 'Search Console Eksik Veri Onarımı',
            'gsc_central_resume' => 'Search Console Aktarıma Devam',
            'gsc_central_update' => 'Search Console Akıllı Güncelleme',
            default => 'Search Console Merkezi Akıllı Aktarım',
        };

        $fingerprintPlans = collect($plans)->map(fn (array $plan): array => [
            'resource_id' => (int) $plan['resource']->id,
            'mode' => $plan['mode'],
            'datasets' => collect($plan['dataset_plans'])->map(fn (array $dataset): array => [
                'dataset_id' => $dataset['dataset_id'],
                'search_type' => $dataset['search_type'] ?? null,
                'date_range' => $dataset['date_range'] ?? null,
                'source_family_id' => $dataset['source_family_id'] ?? null,
            ])->all(),
        ])->all();
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

        $run = DB::transaction(function () use ($integration, $plans, $requestedBy, $version, $fingerprint, $runIntent, $runLabel, $modes): CollectionRun {
            $datasetCount = collect($plans)->sum(fn (array $plan): int => count($plan['dataset_plans']));
            $allInitial = collect($plans)->every(fn (array $plan): bool => $plan['mode'] === 'initial');
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
                    'request_family_ids' => [
                        SearchConsoleCentralDatasetExecutor::FAMILY_ANALYTICS,
                        SearchConsoleCentralDatasetExecutor::FAMILY_SITEMAPS,
                        SearchConsoleCentralDatasetExecutor::FAMILY_SITE_METADATA,
                    ],
                    'provider_sources' => ['SEARCH_CONSOLE'],
                    'context' => [
                        'collection_scope' => 'provider_resource_first',
                        'google_integration_id' => $integration->id,
                        'collection_intent' => $runIntent,
                        'history_days' => $maxDays,
                        'final_lag_days' => (int) config('moxdop-gsc-central.final_lag_days', self::FINAL_LAG_DAYS),
                        'restatement_days' => (int) config('moxdop-gsc-central.restatement_days', self::RESTATEMENT_DAYS),
                        'asset_binding_required' => false,
                    ],
                ],
                'plan_snapshot' => [
                    'resources' => [],
                    'datasets' => [],
                    'dispositions' => [],
                    'contract_registry_version' => $version,
                    'planner_version' => 'gsc-central-resource-first-v1-smart',
                ],
                'metadata' => [
                    'plan_fingerprint' => $fingerprint,
                    'collection_intent' => $runIntent,
                    'collection_intent_label' => $runLabel,
                    'collection_scope' => 'provider_resource_first',
                    'collection_modes' => $modes->all(),
                ],
            ]);

            $snapshotResources = [];
            $snapshotDatasets = [];
            foreach ($plans as $plan) {
                /** @var CoreExternalResource $resource */
                $resource = $plan['resource'];
                $datasetPlans = $plan['dataset_plans'];
                $resourceRun = CollectionResourceRun::query()->create([
                    'collection_run_id' => $run->id,
                    'provider_or_source' => 'SEARCH_CONSOLE',
                    'resource_kind' => 'provider_resource',
                    'external_resource_id' => (int) $resource->id,
                    'digital_asset_id' => null,
                    'core_asset_binding_id' => null,
                    'status' => CollectionRunStatus::Queued,
                    'last_activity_at' => now(),
                    'datasets_total' => count($datasetPlans),
                    'metadata' => [
                        'capability' => 'search_console',
                        'collection_scope' => 'provider_resource_first',
                        'collection_mode' => $plan['mode'],
                        'site_url' => (string) $resource->external_id,
                        'active_search_types' => $plan['active_search_types'],
                        'reporting_timezone' => SearchConsoleProviderCapabilities::REPORTING_TIMEZONE,
                    ],
                ]);

                $snapshotResources[] = [
                    'provider_or_source' => 'SEARCH_CONSOLE',
                    'external_resource_id' => (int) $resource->id,
                    'provider_resource_id' => (string) $resource->external_id,
                    'digital_asset_id' => null,
                    'core_asset_binding_id' => null,
                    'collection_mode' => $plan['mode'],
                ];

                foreach ($datasetPlans as $datasetPlan) {
                    $dateRange = is_array($datasetPlan['date_range'] ?? null) ? $datasetPlan['date_range'] : null;
                    CollectionDatasetRun::query()->create([
                        'collection_run_id' => $run->id,
                        'collection_resource_run_id' => $resourceRun->id,
                        'provider_or_source' => 'SEARCH_CONSOLE',
                        'dataset_contract_id' => (string) $datasetPlan['dataset_id'],
                        'request_family_id' => (string) $datasetPlan['request_family_id'],
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
                            'search_type' => $datasetPlan['search_type'] ?? null,
                            'source_family_id' => $datasetPlan['source_family_id'] ?? null,
                            'central_definition' => $datasetPlan['central_definition'] ?? null,
                            'reporting_timezone' => SearchConsoleProviderCapabilities::REPORTING_TIMEZONE,
                        ],
                    ]);

                    $snapshotDatasets[] = [
                        'provider_or_source' => 'SEARCH_CONSOLE',
                        'dataset_contract_id' => (string) $datasetPlan['dataset_id'],
                        'request_family_id' => (string) $datasetPlan['request_family_id'],
                        'source_family_id' => $datasetPlan['source_family_id'] ?? null,
                        'external_resource_id' => (int) $resource->id,
                        'digital_asset_id' => null,
                        'date_range' => $dateRange,
                        'search_type' => $datasetPlan['search_type'] ?? null,
                        'collection_mode' => $plan['mode'],
                    ];
                }
            }

            $snapshot = $run->plan_snapshot ?? [];
            $snapshot['resources'] = $snapshotResources;
            $snapshot['datasets'] = $snapshotDatasets;
            $run->forceFill(['plan_snapshot' => $snapshot])->save();

            return $run->fresh(['resourceRuns', 'datasetRuns']) ?? $run;
        });

        CollectionRunStarted::dispatch($run);
        $this->starter->dispatchEligibleRootJobs($run);

        return $run->fresh() ?? $run;
    }
}