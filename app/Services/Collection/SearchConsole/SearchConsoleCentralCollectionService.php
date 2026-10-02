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
use App\Services\Collection\Activity\ActivityCollectionPlan;
use App\Services\Collection\Activity\CollectionActivityGate;
use App\Services\Collection\DataContractRegistryLoader;
use App\Services\Collection\Providers\SearchConsole\SearchConsoleApiClient;
use App\Services\Collection\Providers\SearchConsole\SearchConsoleCentralDatasetExecutor;
use App\Services\Collection\Providers\SearchConsole\SearchConsoleProviderCapabilities;
use App\Services\Collection\Providers\SearchConsole\SearchConsoleRequestFamilyCatalog;
use App\Services\Collection\StartCollectionService;
use App\Services\DataPool\DataPoolStorageRegistry;
use App\Services\Integrations\ResourceAutomationService;
use App\Support\Collection\CollectionDatasetCatalog;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Integrations\ProviderRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
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
    /** Initial load: the last 13 months (Search Console keeps 16). */
    public const int INITIAL_DAYS = 395;

    /** Daily re-fetch window: Search Console lags ~2–3 days and restates the most recent days. */
    public const int RESTATEMENT_DAYS = 4;

    public const int FINAL_LAG_DAYS = 3;

    /** Datasets that always hold rows when the property has search traffic (search appearance may be empty). */
    public const array SELF_HEAL_DATASETS = [
        'gsc_query_daily', 'gsc_page_daily', 'gsc_query_page_daily', 'gsc_device_daily', 'gsc_country_daily',
        'gsc_page_device_daily', 'gsc_page_country_daily', 'gsc_query_device_daily', 'gsc_query_country_daily',
    ];

    public function __construct(
        private readonly DataContractRegistryLoader $registry,
        private readonly SearchConsoleApiClient $api,
        private readonly StartCollectionService $starter,
        private readonly CollectionActivityGate $activity,
    ) {}

    /** @param list<int|string> $externalResourceIds */
    /** Datasets collected for a property that serves no operational asset (query pipeline input only). */
    public const array QUERY_DATASETS = ['gsc_query_page_daily'];

    /**
     * @param  list<int|string>  $externalResourceIds
     * @param  bool  $queryOnly  unbound / passive property: only the query dataset (QUERY_DATASETS), never the full set
     */
    public function startSmartUpdate(CoreIntegration $integration, array $externalResourceIds, ?User $requestedBy = null, bool $queryOnly = false): CollectionRun
    {
        return app(ResourceAutomationService::class)->withResourceLocks(
            $externalResourceIds, fn (): CollectionRun => $this->startSmartUpdateLocked($integration, $externalResourceIds, $requestedBy, $queryOnly)
        );
    }

    /**
     * Re-fetches the given Search Analytics datasets over the full history window (or `$days`), with fresh
     * checkpoints — used when facts are missing although earlier runs reported them covered (e.g. writes that failed
     * on a missing partition, or datasets completed with 0 rows). Bypasses the activity tier on purpose.
     *
     * @param  list<int|string>  $externalResourceIds
     * @param  list<string>  $datasetIds
     */
    public function startRefetch(CoreIntegration $integration, array $externalResourceIds, array $datasetIds, ?int $days = null, ?User $requestedBy = null): CollectionRun
    {
        return app(ResourceAutomationService::class)->withResourceLocks(
            $externalResourceIds, function () use ($integration, $externalResourceIds, $datasetIds, $days, $requestedBy): CollectionRun {
                $end = $this->finalDataEnd();
                $days = max(1, min((int) config('moxdop-gsc-central.initial_days', 486), $days ?? self::INITIAL_DAYS));
                $start = $end->subDays($days - 1);
                $plans = [];
                foreach ($this->resolveResources($integration, $externalResourceIds) as $resource) {
                    $searchTypes = CollectionResourceRun::query()->where('provider_or_source', 'SEARCH_CONSOLE')
                        ->where('external_resource_id', $resource->id)->latest('id')->limit(5)->get()
                        ->flatMap(fn (CollectionResourceRun $run): array => (array) data_get($run->metadata, 'active_search_types', []))
                        ->push('web')->filter(fn ($type): bool => is_string($type) && $type !== '')->unique()->values()->all();
                    $datasetPlans = array_values(array_filter(
                        $this->datasetPlans($searchTypes, $start, $end),
                        fn (array $plan): bool => in_array($plan['dataset_id'], $datasetIds, true),
                    ));
                    if ($datasetPlans === []) {
                        continue;
                    }
                    $plans[] = [
                        'resource' => $resource,
                        'mode' => 'repair',
                        'dataset_plans' => $datasetPlans,
                        'days' => $days,
                        'active_search_types' => $searchTypes,
                    ];
                }
                if ($plans === []) {
                    throw new InvalidArgumentException('Yeniden alınacak Search Console veri seti yok.');
                }

                return $this->startPlans($integration, $plans, $requestedBy);
            }
        );
    }

    /**
     * Search Analytics datasets whose facts hold no row for the resource although the property totals show search
     * traffic — their coverage marker is not trustworthy (writes failed / 0 rows) and they need the full window.
     *
     * @return list<string>
     */
    public function datasetsMissingFacts(CoreExternalResource $resource, ?int $minImpressions = null): array
    {
        $minImpressions ??= (int) config('moxdop-gsc-central.refetch_min_property_impressions', 50);
        $impressions = (int) DB::table('gsc_property_daily')->where('external_resource_id', $resource->id)
            ->where('reporting_date', '>=', $this->finalDataEnd()->subDays(self::INITIAL_DAYS - 1)->toDateString())
            ->sum('impressions');
        if ($impressions < $minImpressions) {
            return [];
        }
        $storage = app(DataPoolStorageRegistry::class);
        $missing = [];
        foreach (SearchConsoleRequestFamilyCatalog::centralPerformanceFamilies() as $family) {
            $datasetId = (string) (SearchConsoleRequestFamilyCatalog::definition($family)['dataset_id'] ?? '');
            if (! in_array($datasetId, self::SELF_HEAL_DATASETS, true) || ! $storage->hasPhysicalTable($datasetId)) {
                continue;
            }
            $table = $storage->tableName($datasetId);
            try {
                if (! DB::table($table)->where('external_resource_id', $resource->id)->exists()) {
                    $missing[] = $datasetId;
                }
            } catch (Throwable) {
                // Missing table on a partial install: nothing to heal here.
            }
        }

        return array_values(array_unique($missing));
    }

    private function startSmartUpdateLocked(CoreIntegration $integration, array $externalResourceIds, ?User $requestedBy = null, bool $queryOnly = false): CollectionRun
    {
        $resources = $this->resolveResources($integration, $externalResourceIds);
        $plans = $resources->map(fn (CoreExternalResource $resource): array => $queryOnly
            ? $this->queryOnlyPlan($resource)
            : $this->smartPlan($integration, $resource))->all();

        return $this->startPlans($integration, $plans, $requestedBy);
    }

    /** @param list<int|string> $externalResourceIds
     * @return Collection<int, CoreExternalResource>
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

    /**
     * Query-only plan (property serves no operational asset): the query dataset from its own coverage — the whole
     * history window the first time, then the restatement window — never the other Search Analytics datasets.
     *
     * @return array<string, mixed>
     */
    private function queryOnlyPlan(CoreExternalResource $resource): array
    {
        $end = $this->finalDataEnd();
        $restatementStart = $end->subDays(self::RESTATEMENT_DAYS - 1)->toDateString();
        $plans = [];
        foreach ($this->datasetPlans(['web'], $end->subDays(self::INITIAL_DAYS - 1), $end) as $plan) {
            if (! in_array($plan['dataset_id'], self::QUERY_DATASETS, true)) {
                continue;
            }
            $covered = app(ResourceAutomationService::class)->coverageEnd(
                $resource->id, 'SEARCH_CONSOLE', $plan['request_family_id'], $plan['dataset_id'], 'web'
            );
            if ($covered !== null) {
                $plan['date_range']['start'] = min($restatementStart, CarbonImmutable::parse($covered)->addDay()->toDateString());
            }
            $plans[] = $plan;
        }
        $start = collect($plans)->pluck('date_range.start')->filter()->sort()->first() ?? $restatementStart;

        return [
            'resource' => $resource,
            'mode' => $start < $restatementStart ? 'initial' : 'update',
            'dataset_plans' => $plans,
            'days' => (int) CarbonImmutable::parse($start)->diffInDays($end) + 1,
            'active_search_types' => ['web'],
            'query_only' => true,
        ];
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
            ->limit(50)
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
                ], true) && CollectionDatasetCatalog::keeps('SEARCH_CONSOLE', (string) $dataset->dataset_contract_id))
                ->map(fn (CollectionDatasetRun $dataset): array => [
                    'request_family_id' => (string) $dataset->request_family_id,
                    'dataset_id' => (string) $dataset->dataset_contract_id,
                    'date_range' => data_get($dataset->metadata, 'date_range'),
                    'central_definition' => data_get($dataset->metadata, 'central_definition'),
                    'search_type' => data_get($dataset->metadata, 'search_type'),
                    'source_family_id' => data_get($dataset->metadata, 'source_family_id'),
                    'checkpoint' => $dataset->checkpoint ?? [],
                    'progress_current' => $dataset->progress_current,
                    'progress_total' => $dataset->progress_total,
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
        $activity = $this->activity->plan($resource);
        if ($activity->isFull()) {
            $previousSearchTypes = $runs->flatMap(fn ($run) => $run->datasetRuns)
                ->pluck('metadata.search_type')
                ->filter(fn ($type): bool => is_string($type) && $type !== '')
                ->unique()
                ->values()
                ->all();
            $activeSearchTypes = array_values(array_unique([
                ...$previousSearchTypes,
                ...$this->detectActiveSearchTypes($integration, $resource, $end, $start),
            ]));
        } else {
            // Idle / dormant: web property totals only, without the search-type probes.
            $activeSearchTypes = ['web'];
        }

        $allPlans = $this->datasetPlans($activeSearchTypes, $start, $end);
        $datasetPlans = array_values(array_filter($allPlans, fn (array $plan): bool => $activity->allowsFamily((string) $plan['source_family_id'])));
        $this->activity->recordPass($activity, count($datasetPlans), count($allPlans) - count($datasetPlans));
        // Self-heal: a dataset "covered" by earlier runs but with no stored fact at all (writes failed on a missing
        // partition, or completed with 0 rows) is fetched over the whole window again instead of 4 days forever.
        // At most once a week per dataset, so a property whose query rows are all anonymised is not refetched daily.
        $uncovered = $activity->mode === ActivityCollectionPlan::MODE_CHECK ? [] : array_values(array_filter(
            $this->datasetsMissingFacts($resource),
            fn (string $datasetId): bool => Cache::add('gsc-central-refetch:'.$resource->id.':'.$datasetId, true, now()->addDays(7)),
        ));
        foreach ($datasetPlans as &$datasetPlan) {
            if (! is_array($datasetPlan['date_range'])) {
                continue;
            }
            if ($activity->mode === ActivityCollectionPlan::MODE_CHECK) {
                // Dormant weekly check: property totals for the last few final days only, no gap filling.
                $datasetPlan['date_range']['start'] = $end->subDays($activity->checkDays - 1)->toDateString();

                continue;
            }
            $covered = app(ResourceAutomationService::class)->coverageEnd(
                $resource->id, 'SEARCH_CONSOLE', $datasetPlan['request_family_id'], $datasetPlan['dataset_id'], $datasetPlan['search_type'] ?? ''
            );
            if (in_array($datasetPlan['dataset_id'], $uncovered, true)) {
                $covered = null;
            }
            $datasetPlan['date_range']['start'] = $covered
                ? min($datasetPlan['date_range']['start'], CarbonImmutable::parse($covered)->addDay()->toDateString())
                : $end->subDays(self::INITIAL_DAYS - 1)->toDateString();
        }
        unset($datasetPlan);

        return [
            'resource' => $resource,
            'mode' => 'update',
            'dataset_plans' => $datasetPlans,
            'days' => (int) $start->diffInDays($end) + 1,
            'active_search_types' => $activeSearchTypes,
            'activity' => $activity->toArray(),
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
    private function detectActiveSearchTypes(CoreIntegration $integration, CoreExternalResource $resource, CarbonImmutable $end, ?CarbonImmutable $probeStart = null): array
    {
        $active = ['web'];
        // Probe the same 13-month window as the initial import so an optional surface
        // is not missed merely because it had no traffic during the last few weeks.
        $start = ($probeStart ?? $end->subDays(self::INITIAL_DAYS - 1))->toDateString();
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
     * @param  list<string>  $activeSearchTypes
     * @return list<array<string, mixed>>
     */
    private function datasetPlans(array $activeSearchTypes, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $range = ['start' => $start->toDateString(), 'end' => $end->toDateString()];
        $plans = [];

        $disabled = (array) config('moxdop-gsc-collector.disabled_families', []);
        foreach (SearchConsoleRequestFamilyCatalog::centralPerformanceFamilies() as $familyId) {
            if (in_array($familyId, $disabled, true)) {
                continue;
            }
            $base = SearchConsoleRequestFamilyCatalog::definition($familyId);
            foreach (SearchConsoleRequestFamilyCatalog::compatibleSearchTypes($familyId, $activeSearchTypes) as $searchType) {
                $definition = $base;
                $definition['search_type'] = $searchType;
                $definition['data_state'] = 'final';
                $definition['slice_days'] = match ($familyId) {
                    SearchConsoleRequestFamilyCatalog::FAMILY_PROPERTY_DAILY,
                    SearchConsoleRequestFamilyCatalog::FAMILY_DEVICE_DAILY => 28,
                    SearchConsoleRequestFamilyCatalog::FAMILY_COUNTRY_DAILY,
                    SearchConsoleRequestFamilyCatalog::FAMILY_SEARCH_APPEARANCE_DAILY => 7,
                    default => 1,
                };
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
            'gsc_central_initial' => 'Search Console Merkezi 13 Aylık Aktarım',
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
                        'activity' => $plan['activity'] ?? null,
                        'query_only' => (bool) ($plan['query_only'] ?? false),
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
                        'checkpoint' => $datasetPlan['checkpoint'] ?? [],
                        'progress_current' => $datasetPlan['progress_current'] ?? 0,
                        'progress_total' => $datasetPlan['progress_total'] ?? null,
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
