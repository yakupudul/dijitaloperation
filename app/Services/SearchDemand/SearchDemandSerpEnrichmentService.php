<?php

namespace App\Services\SearchDemand;

use App\Contracts\SearchDemand\SearchDemandSerpEnrichmentAdapter;
use App\Jobs\Async\SearchDemandSerpEnrichmentJob;
use App\Models\BrandQueryPortfolioItem;
use App\Models\DigitalAsset;
use App\Models\SearchDemandEnrichmentRun;
use App\Models\SearchDemandEnrichmentRunItem;
use App\Models\SearchDemandExpansionCandidate;
use App\Models\SearchDemandKeywordMetricSnapshot;
use App\Models\SearchDemandProviderPayload;
use App\Models\SearchDemandSerpClusterReview;
use App\Models\SearchDemandSerpSnapshot;
use App\Models\User;
use App\Services\Integrations\DataForSeo\DataForSeoException;
use App\Services\Integrations\PaidRequestFingerprint;
use App\Support\Integrations\ProviderRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class SearchDemandSerpEnrichmentService
{
    public const string SCOPE_CLUSTER = 'cluster';

    public const string SCOPE_SERVICE = 'service';

    public function __construct(
        private readonly SearchDemandSerpEnrichmentAdapter $adapter,
        private readonly SearchDemandClusteringService $clustering,
        private readonly BrandQueryPortfolioService $portfolios,
    ) {}

    /** @return array<string, mixed> */
    public function plan(DigitalAsset $website, string $scopeType, int $scopeId, int $depth, string $device, bool $includeExpansion = false): array
    {
        $this->assertContext($website, $scopeType, $scopeId, $depth, $device);
        $items = $this->scopedItems($website, $scopeType, $scopeId);
        if ($items->isEmpty()) {
            throw ValidationException::withMessages([
                'scopeId' => 'Seçilen kapsamda bu Website için etkin portföy sorgusu yok.',
            ]);
        }

        $ttlDays = max(1, (int) config('moxdop.search_demand_enrichment.freshness_days', 7));
        $queries = $items->map(function (BrandQueryPortfolioItem $item) use ($website, $depth, $device, $ttlDays): array {
            $assetState = $item->assetStates->firstWhere('digital_asset_id', $website->id);
            $queryText = trim((string) ($assetState?->query_text_override ?: $item->effectiveQueryText()));
            $clusterId = $item->clusterMembership?->search_demand_cluster_id;
            $serpFingerprint = $this->serpFingerprint($website, $queryText, $depth, $device);
            $metricFingerprint = $this->metricFingerprint($website, $queryText);
            $metricEligible = $this->isKeywordMetricEligible($queryText);

            return [
                'portfolio_item_id' => (int) $item->id,
                'cluster_id' => $clusterId !== null ? (int) $clusterId : null,
                'query_text' => $queryText,
                'serp_fingerprint' => $serpFingerprint,
                'metric_fingerprint' => $metricFingerprint,
                'metric_eligible' => $metricEligible,
                'fresh_serp' => $this->freshSerp($website, $serpFingerprint, $ttlDays),
                'fresh_metric' => $metricEligible ? $this->freshMetric($website, $metricFingerprint, $ttlDays) : null,
            ];
        })->filter(fn (array $query): bool => $query['query_text'] !== '')->values();
        if ($queries->isEmpty()) {
            throw ValidationException::withMessages(['scopeId' => 'Seçilen kapsamdaki etkin sorguların kullanılabilir metni yok.']);
        }

        $serpMisses = $queries->whereNull('fresh_serp')->count();
        $metricMisses = $queries->filter(fn (array $query): bool => $query['metric_eligible'] && $query['fresh_metric'] === null)->count();
        $metricUnsupported = $queries->where('metric_eligible', false)->count();
        $metricHits = $queries->filter(fn (array $query): bool => $query['metric_eligible'] && $query['fresh_metric'] !== null)->count();
        $expansionFingerprint = $this->expansionFingerprint($website, $queries->all());
        $expansionCacheHit = $includeExpansion && $this->freshExpansionPayload($expansionFingerprint, $ttlDays) instanceof SearchDemandProviderPayload;
        $estimate = $this->adapter->estimate($serpMisses, $metricMisses, $includeExpansion && ! $expansionCacheHit, $depth);
        $input = [
            'website_id' => (int) $website->id,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'depth' => $depth,
            'device' => $device,
            'include_query_expansion' => $includeExpansion,
            'location_code' => (int) $website->seo_market_location_code,
            'language_code' => (string) $website->seo_market_language_code,
            'queries' => $queries->map(fn (array $query): array => [
                'portfolio_item_id' => $query['portfolio_item_id'],
                'query_text' => $query['query_text'],
                'serp_fingerprint' => $query['serp_fingerprint'],
                'metric_fingerprint' => $query['metric_fingerprint'],
                'metric_eligible' => $query['metric_eligible'],
            ])->all(),
        ];

        return [
            'queries' => $queries,
            'query_count' => $queries->count(),
            'serp_cache_hits' => $queries->count() - $serpMisses,
            'metric_cache_hits' => $metricHits,
            'serp_misses' => $serpMisses,
            'metric_misses' => $metricMisses,
            'metric_unsupported' => $metricUnsupported,
            'estimate' => $estimate,
            'expansion_fingerprint' => $expansionFingerprint,
            'expansion_cache_hit' => $expansionCacheHit,
            'input_fingerprint' => hash('sha256', json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
            'readiness' => $this->adapter->readiness(),
            'freshness_days' => $ttlDays,
        ];
    }

    /** @return array{run: SearchDemandEnrichmentRun, queued: bool, plan: array<string, mixed>} */
    public function queue(
        DigitalAsset $website,
        string $scopeType,
        int $scopeId,
        int $depth,
        string $device,
        bool $paidConsent,
        bool $includeExpansion = false,
        ?User $actor = null,
    ): array {
        if (! $paidConsent) {
            throw ValidationException::withMessages([
                'paidConsent' => 'Ücretli DataForSEO çağrıları için açık onay vermelisiniz.',
            ]);
        }
        $plan = $this->plan($website, $scopeType, $scopeId, $depth, $device, $includeExpansion);
        if (! $plan['readiness']['configured']) {
            throw ValidationException::withMessages([
                'paidConsent' => $plan['readiness']['message'] ?? 'DataForSEO bağlantısı hazır değil.',
            ]);
        }

        $existing = SearchDemandEnrichmentRun::query()
            ->where('input_fingerprint', $plan['input_fingerprint'])
            ->whereIn('status', ['queued', 'running'])
            ->latest('id')
            ->first();
        if ($existing instanceof SearchDemandEnrichmentRun) {
            return ['run' => $existing, 'queued' => false, 'plan' => $plan];
        }

        $run = DB::transaction(function () use ($website, $scopeType, $scopeId, $depth, $device, $includeExpansion, $actor, $plan): SearchDemandEnrichmentRun {
            $run = SearchDemandEnrichmentRun::query()->create([
                'uuid' => (string) Str::uuid(),
                'brand_id' => $website->brand_id,
                'digital_asset_id' => $website->id,
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'status' => 'queued',
                'provider' => $this->adapter->provider(),
                'depth' => $depth,
                'device' => $device,
                'include_query_expansion' => $includeExpansion,
                'location_code' => $website->seo_market_location_code,
                'location_name' => $website->seo_market_location_name,
                'language_code' => $website->seo_market_language_code,
                'language_name' => $website->seo_market_language_name,
                'query_count' => $plan['query_count'],
                'serp_cache_hits' => $plan['serp_cache_hits'],
                'metric_cache_hits' => $plan['metric_cache_hits'],
                'estimated_cost_usd' => $plan['estimate']['estimated_cost_usd'],
                'cost_estimate_basis' => $plan['estimate']['basis'],
                'request_context' => [
                    'manual' => true,
                    'paid_enrichment_consented' => true,
                    'automatic_brand_creation_call' => false,
                    'query_expansion_requested' => $includeExpansion,
                    'query_expansion_cache_hit' => $plan['expansion_cache_hit'],
                    'freshness_days' => $plan['freshness_days'],
                ],
                'input_fingerprint' => $plan['input_fingerprint'],
                'requested_by' => $actor?->id,
                'paid_consent_recorded_at' => now(),
            ]);

            foreach ($plan['queries'] as $query) {
                $run->items()->create([
                    'brand_query_portfolio_item_id' => $query['portfolio_item_id'],
                    'search_demand_cluster_id' => $query['cluster_id'],
                    'query_text' => $query['query_text'],
                    'serp_request_fingerprint' => $query['serp_fingerprint'],
                    'metric_request_fingerprint' => $query['metric_fingerprint'],
                ]);
            }

            dispatch(new SearchDemandSerpEnrichmentJob($run->id))->afterCommit();

            return $run;
        });

        return ['run' => $run, 'queued' => true, 'plan' => $plan];
    }

    public function execute(int $runId): void
    {
        $run = SearchDemandEnrichmentRun::query()->with(['digitalAsset', 'items'])->findOrFail($runId);
        if (! in_array($run->status, ['queued', 'running'], true)) {
            return;
        }
        $run->forceFill(['status' => 'running', 'started_at' => $run->started_at ?? now(), 'error_code' => null, 'error_summary' => null])->save();

        $lock = Cache::lock('search-demand-enrichment:'.$run->input_fingerprint, 600);
        if (! $lock->get()) {
            throw new \RuntimeException('An identical Search Demand enrichment is already running.');
        }

        try {
            $this->executeLocked($run->fresh(['digitalAsset', 'items']));
        } finally {
            $lock->release();
        }
    }

    public function markFailed(int $runId, Throwable $exception): void
    {
        $run = SearchDemandEnrichmentRun::query()->find($runId);
        if (! $run instanceof SearchDemandEnrichmentRun || $run->status === 'completed') {
            return;
        }
        $openSerpAttempt = $run->items()
            ->whereNotNull('serp_paid_attempt_started_at')
            ->whereNull('serp_committed_at')
            ->exists();
        $openPaidAttempt = $openSerpAttempt
            || ($run->metric_paid_attempt_started_at !== null && $run->metric_committed_at === null)
            || ($run->expansion_paid_attempt_started_at !== null && $run->expansion_committed_at === null);
        $chargeUnknown = $openPaidAttempt || ($exception instanceof DataForSeoException && $exception->kind === DataForSeoException::KIND_AMBIGUOUS_PAID);
        $run->forceFill([
            'status' => $chargeUnknown ? 'charge_unknown' : 'failed',
            'failed_at' => now(),
            'error_code' => $chargeUnknown ? 'CHARGE_UNKNOWN' : ($exception instanceof DataForSeoException ? strtoupper($exception->kind) : 'ENRICHMENT_FAILED'),
            'error_summary' => Str::limit($exception->getMessage(), 1000),
        ])->save();
    }

    public function reviewClusterEvidence(SearchDemandSerpClusterReview $review, string $decision, ?User $actor = null): void
    {
        if (! in_array($decision, ['approve', 'reject'], true) || $review->status !== 'pending') {
            throw ValidationException::withMessages(['review' => 'Bu SERP kanıt kararı uygulanamaz.']);
        }
        if ($review->cluster?->status !== 'active' || (int) ($review->cluster?->brand_id) !== (int) ($review->run?->brand_id)) {
            throw ValidationException::withMessages(['review' => 'Küme artık bu çalışmanın etkin markasına ait değil.']);
        }
        if ($decision === 'approve') {
            $this->clustering->setValidationStatus($review->cluster, $review->recommended_status, $actor);
        }
        $review->forceFill([
            'status' => $decision === 'approve' ? 'approved' : 'rejected',
            'reviewed_by' => $actor?->id,
            'reviewed_at' => now(),
        ])->save();
    }

    public function reviewExpansionCandidate(SearchDemandExpansionCandidate $candidate, string $decision, ?User $actor = null): void
    {
        if (! in_array($decision, ['approve', 'reject'], true) || $candidate->status !== 'pending') {
            throw ValidationException::withMessages(['candidate' => 'Bu sorgu genişletme adayı artık beklemiyor.']);
        }
        if ($decision === 'reject') {
            $candidate->forceFill(['status' => 'rejected', 'reviewed_by' => $actor?->id, 'reviewed_at' => now()])->save();

            return;
        }

        $run = $candidate->run()->with(['brand', 'digitalAsset'])->firstOrFail();
        $existing = BrandQueryPortfolioItem::query()
            ->with('libraryItem')
            ->where('brand_id', $run->brand_id)
            ->get()
            ->first(fn (BrandQueryPortfolioItem $item): bool => $this->fold($item->effectiveQueryText()) === $this->fold($candidate->keyword));
        if ($existing instanceof BrandQueryPortfolioItem) {
            $this->portfolios->setWebsiteStatus($existing, (int) $run->digital_asset_id, 'active', $actor);
            $candidate->forceFill([
                'status' => 'existing', 'approved_portfolio_item_id' => $existing->id,
                'reviewed_by' => $actor?->id, 'reviewed_at' => now(),
            ])->save();

            return;
        }

        $result = $this->portfolios->addBrandQuery($run->brand, $candidate->keyword, [
            'language_code' => $run->language_code,
            'market_code' => data_get($run->digitalAsset?->target_countries, 0),
            'service_catalog_item_id' => $run->scope_type === self::SCOPE_SERVICE ? $run->scope_id : null,
            'location_scope' => 'none',
            'is_branded' => false,
        ], $actor);
        $this->portfolios->setWebsiteStatus($result['item'], (int) $run->digital_asset_id, 'active', $actor);
        $candidate->forceFill([
            'status' => 'approved', 'approved_portfolio_item_id' => $result['item']->id,
            'reviewed_by' => $actor?->id, 'reviewed_at' => now(),
        ])->save();
    }

    private function executeLocked(SearchDemandEnrichmentRun $run): void
    {
        $website = $run->digitalAsset;
        $ttlDays = max(1, (int) data_get($run->request_context, 'freshness_days', 7));
        $serpMisses = [];
        $metricMisses = [];
        $serpHits = 0;
        $metricHits = 0;

        foreach ($run->items as $item) {
            $freshSerp = $this->freshSerp($website, $item->serp_request_fingerprint, $ttlDays);
            if ($freshSerp instanceof SearchDemandSerpSnapshot) {
                $item->forceFill(['serp_status' => 'cache_hit', 'serp_snapshot_id' => $freshSerp->id])->save();
                $serpHits++;
            } else {
                $serpMisses[] = ['portfolio_item_id' => (int) $item->brand_query_portfolio_item_id, 'query_text' => $item->query_text];
            }
            $freshMetric = $this->freshMetric($website, $item->metric_request_fingerprint, $ttlDays);
            if (! $this->isKeywordMetricEligible($item->query_text)) {
                $item->forceFill([
                    'metric_status' => 'unsupported',
                    'error_summary' => 'Google Ads Search Volume accepts at most 80 characters and 10 words per keyword.',
                ])->save();
            } elseif ($freshMetric instanceof SearchDemandKeywordMetricSnapshot) {
                $item->forceFill(['metric_status' => 'cache_hit', 'keyword_metric_snapshot_id' => $freshMetric->id])->save();
                $metricHits++;
            } else {
                $metricMisses[] = ['portfolio_item_id' => (int) $item->brand_query_portfolio_item_id, 'query_text' => $item->query_text];
            }
        }
        $run->forceFill(['serp_cache_hits' => $serpHits, 'metric_cache_hits' => $metricHits])->save();

        if ($serpMisses !== []) {
            $this->collectSerps($run, $website, $serpMisses);
        }
        if ($metricMisses !== []) {
            $this->collectMetrics($run->fresh(['items']), $website, $metricMisses);
        }
        if ($run->include_query_expansion) {
            $this->collectOrReuseExpansions($run->fresh(['items']), $website);
        }

        $this->buildClusterReviews($run->fresh(['items.serpSnapshot.results']));
        $costs = SearchDemandProviderPayload::query()
            ->where('search_demand_enrichment_run_id', $run->id)
            ->whereNotNull('reported_cost_usd')
            ->pluck('reported_cost_usd');
        $run->refresh();
        $failedItems = $run->items()->where(fn ($query) => $query->where('serp_status', 'failed')->orWhere('metric_status', 'failed'))->count();
        $partialErrorCode = $failedItems > 0 ? 'PARTIAL_TASK_ERRORS' : $run->error_code;
        $partialErrorSummary = $failedItems > 0
            ? $failedItems.' query item(s) had provider task errors; missing values remain unknown.'
            : $run->error_summary;
        $run->forceFill([
            'status' => 'completed',
            'completed_at' => now(),
            'reported_cost_usd' => $run->provider_request_count > 0 && $costs->isEmpty() ? null : $costs->sum(),
            'error_code' => $partialErrorCode,
            'error_summary' => $partialErrorSummary,
        ])->save();
    }

    /** @param list<array{portfolio_item_id: int, query_text: string}> $queries */
    private function collectSerps(SearchDemandEnrichmentRun $run, DigitalAsset $website, array $queries): void
    {
        $batchFingerprint = PaidRequestFingerprint::make(ProviderRegistry::DATAFORSEO, 'search_demand_serp_enrichment_batch', 'serp/google/organic/live/regular', [
            'keywords' => collect($queries)->pluck('query_text')->values()->all(), 'location_code' => $run->location_code, 'language_code' => $run->language_code,
            'device' => $run->device, 'depth' => $run->depth,
        ]);
        $run->forceFill(['serp_batch_fingerprint' => $batchFingerprint])->save();

        foreach ($queries as $query) {
            $runItem = $run->items->firstWhere('brand_query_portfolio_item_id', $query['portfolio_item_id']);
            if (! $runItem instanceof SearchDemandEnrichmentRunItem) {
                continue;
            }
            $fingerprint = $runItem->serp_request_fingerprint;
            $paidLock = Cache::lock('paid-request:'.$fingerprint, 180);
            if (! $paidLock->get()) {
                throw new \RuntimeException('An identical paid SERP request is already in progress; no duplicate request was sent.');
            }

            try {
                $attemptedAt = now();
                $run->forceFill([
                    'serp_paid_attempt_started_at' => $attemptedAt,
                    'provider_request_count' => $run->provider_request_count + 1,
                ])->save();
                $runItem->forceFill(['serp_status' => 'running', 'serp_paid_attempt_started_at' => $attemptedAt])->save();
                $batch = $this->adapter->collectSerps($website, [$query], (int) $run->depth, (string) $run->device);
                $this->persistPayload($run, $fingerprint, $batch);
                $observation = $batch['observations'][$query['portfolio_item_id']] ?? null;
                if (! is_array($observation) || ($observation['status'] ?? null) !== 'completed') {
                    $runItem->forceFill([
                        'serp_status' => 'failed',
                        'serp_committed_at' => now(),
                        'serp_reported_cost_usd' => $batch['reported_cost_usd'],
                        'error_summary' => is_array($observation) ? ($observation['error'] ?? 'No SERP task result returned.') : 'No SERP task result returned.',
                    ])->save();

                    continue;
                }
                $retrievedAt = now();
                DB::transaction(function () use ($run, $runItem, $batch, $observation, $retrievedAt): void {
                    $snapshot = SearchDemandSerpSnapshot::query()->create([
                        'search_demand_enrichment_run_id' => $run->id,
                        'brand_query_portfolio_item_id' => $runItem->brand_query_portfolio_item_id,
                        'digital_asset_id' => $run->digital_asset_id,
                        'search_demand_cluster_id' => $runItem->search_demand_cluster_id,
                        'query_text' => $runItem->query_text,
                        'provider' => $run->provider,
                        'endpoint' => $batch['endpoint'],
                        'request_fingerprint' => $runItem->serp_request_fingerprint,
                        'provider_task_id' => $observation['provider_task_id'] ?? null,
                        'location_code' => $run->location_code,
                        'location_name' => $run->location_name,
                        'language_code' => $run->language_code,
                        'language_name' => $run->language_name,
                        'device' => $run->device,
                        'depth' => $run->depth,
                        'result_count' => $observation['result_count'] ?? null,
                        'serp_features' => $observation['serp_features'] ?? [],
                        'brand_rank' => $observation['brand_rank'] ?? null,
                        'brand_url' => $observation['brand_url'] ?? null,
                        'retrieved_at' => $retrievedAt,
                    ]);
                    foreach ($observation['organic_results'] ?? [] as $result) {
                        $snapshot->results()->create($result);
                    }
                    $runItem->forceFill([
                        'serp_status' => 'completed',
                        'serp_snapshot_id' => $snapshot->id,
                        'serp_committed_at' => now(),
                        'serp_reported_cost_usd' => $batch['reported_cost_usd'],
                        'error_summary' => null,
                    ])->save();
                });
            } finally {
                $paidLock->release();
            }
        }

        $run->forceFill(['serp_committed_at' => now()])->save();
    }

    /** @param list<array{portfolio_item_id: int, query_text: string}> $queries */
    private function collectMetrics(SearchDemandEnrichmentRun $run, DigitalAsset $website, array $queries): void
    {
        $fingerprint = PaidRequestFingerprint::make(ProviderRegistry::DATAFORSEO, 'search_demand_keyword_metrics', 'keywords_data/google_ads/search_volume/live', [
            'keywords' => collect($queries)->pluck('query_text')->values()->all(), 'location_code' => $run->location_code, 'language_code' => $run->language_code,
        ]);
        $paidLock = Cache::lock('paid-request:'.$fingerprint, 180);
        if (! $paidLock->get()) {
            throw new \RuntimeException('An identical paid keyword-metrics request is already in progress; no duplicate request was sent.');
        }
        try {
            $run->forceFill([
                'metric_batch_fingerprint' => $fingerprint,
                'metric_paid_attempt_started_at' => now(),
                'provider_request_count' => $run->provider_request_count + 1,
            ])->save();
            $run->items()->whereIn('brand_query_portfolio_item_id', collect($queries)->pluck('portfolio_item_id'))->update(['metric_status' => 'running']);
            $batch = $this->adapter->collectKeywordMetrics($website, $queries);
            $this->persistPayload($run, $fingerprint, $batch);
            if (is_string($batch['task_error'] ?? null)) {
                $run->items()->whereIn('brand_query_portfolio_item_id', collect($queries)->pluck('portfolio_item_id'))->update([
                    'metric_status' => 'failed',
                    'error_summary' => $batch['task_error'],
                ]);
                $run->forceFill(['metric_committed_at' => now()])->save();

                return;
            }
            $retrievedAt = now();

        DB::transaction(function () use ($run, $queries, $batch, $retrievedAt): void {
            foreach ($queries as $query) {
                $runItem = $run->items->firstWhere('brand_query_portfolio_item_id', $query['portfolio_item_id']);
                if (! $runItem instanceof SearchDemandEnrichmentRunItem) {
                    continue;
                }
                $metric = $batch['metrics'][$query['portfolio_item_id']] ?? [];
                $snapshot = SearchDemandKeywordMetricSnapshot::query()->create([
                    'search_demand_enrichment_run_id' => $run->id,
                    'brand_query_portfolio_item_id' => $runItem->brand_query_portfolio_item_id,
                    'digital_asset_id' => $run->digital_asset_id,
                    'query_text' => $runItem->query_text,
                    'provider' => $run->provider,
                    'endpoint' => $batch['endpoint'],
                    'request_fingerprint' => $runItem->metric_request_fingerprint,
                    'provider_task_id' => $batch['provider_task_id'] ?? null,
                    'location_code' => $run->location_code,
                    'language_code' => $run->language_code,
                    'search_volume' => $metric['search_volume'] ?? null,
                    'cpc' => $metric['cpc'] ?? null,
                    'competition' => $metric['competition'] ?? null,
                    'competition_index' => $metric['competition_index'] ?? null,
                    'monthly_searches' => $metric['monthly_searches'] ?? null,
                    'measurement_type' => 'provider_estimate',
                    'retrieved_at' => $retrievedAt,
                ]);
                $runItem->forceFill(['metric_status' => 'completed', 'keyword_metric_snapshot_id' => $snapshot->id])->save();
            }
        });
            $run->forceFill(['metric_committed_at' => now()])->save();
        } finally {
            $paidLock->release();
        }
    }

    /** @param array<string, mixed> $batch */
    private function persistPayload(SearchDemandEnrichmentRun $run, string $fingerprint, array $batch): void
    {
        SearchDemandProviderPayload::query()->create([
            'search_demand_enrichment_run_id' => $run->id,
            'provider' => $run->provider,
            'endpoint' => $batch['endpoint'],
            'request_fingerprint' => $fingerprint,
            'request_payload' => $batch['request_payload'],
            'response_payload' => $batch['response_payload'],
            'reported_cost_usd' => $batch['reported_cost_usd'],
            'captured_at' => now(),
        ]);
    }

    private function collectOrReuseExpansions(SearchDemandEnrichmentRun $run, DigitalAsset $website): void
    {
        $queries = $run->items->map(fn (SearchDemandEnrichmentRunItem $item): array => [
            'portfolio_item_id' => (int) $item->brand_query_portfolio_item_id,
            'query_text' => $item->query_text,
        ])->values()->all();
        $fingerprint = $this->expansionFingerprint($website, $queries);
        $ttlDays = max(1, (int) data_get($run->request_context, 'freshness_days', 7));
        $cachedPayload = $this->freshExpansionPayload($fingerprint, $ttlDays);
        if ($cachedPayload instanceof SearchDemandProviderPayload) {
            $sourceCandidates = SearchDemandExpansionCandidate::query()
                ->where('search_demand_enrichment_run_id', $cachedPayload->search_demand_enrichment_run_id)
                ->get();
            foreach ($sourceCandidates as $source) {
                $run->expansionCandidates()->firstOrCreate(
                    ['candidate_fingerprint' => $source->candidate_fingerprint],
                    [
                        'source_request_fingerprint' => $fingerprint,
                        'keyword' => $source->keyword,
                        'search_volume' => $source->search_volume,
                        'cpc' => $source->cpc,
                        'competition' => $source->competition,
                        'competition_index' => $source->competition_index,
                        'monthly_searches' => $source->monthly_searches,
                        'measurement_type' => 'provider_estimate',
                        'status' => 'pending',
                    ],
                );
            }
            $run->forceFill(['expansion_batch_fingerprint' => $fingerprint, 'expansion_committed_at' => now()])->save();

            return;
        }

        $paidLock = Cache::lock('paid-request:'.$fingerprint, 180);
        if (! $paidLock->get()) {
            throw new \RuntimeException('An identical paid query-expansion request is already in progress; no duplicate request was sent.');
        }
        try {
        $run->forceFill([
            'expansion_batch_fingerprint' => $fingerprint,
            'expansion_paid_attempt_started_at' => now(),
            'provider_request_count' => $run->provider_request_count + 1,
        ])->save();
        $batch = $this->adapter->collectQueryExpansions($website, $queries);
        $this->persistPayload($run, $fingerprint, $batch);
        if (is_string($batch['task_error'] ?? null)) {
            $run->forceFill([
                'expansion_committed_at' => now(),
                'error_code' => 'PARTIAL_EXPANSION_TASK_ERROR',
                'error_summary' => $batch['task_error'],
            ])->save();

            return;
        }
        $seedFolds = collect($queries)->pluck('query_text')->map(fn (string $query): string => $this->fold($query))->flip();
        foreach ($batch['candidates'] as $candidate) {
            $keyword = trim((string) ($candidate['keyword'] ?? ''));
            if ($keyword === '' || $seedFolds->has($this->fold($keyword))) {
                continue;
            }
            $candidateFingerprint = hash('sha256', $run->brand_id.'|'.$run->language_code.'|'.$this->fold($keyword));
            $run->expansionCandidates()->firstOrCreate(
                ['candidate_fingerprint' => $candidateFingerprint],
                [
                    'source_request_fingerprint' => $fingerprint,
                    'keyword' => $keyword,
                    'search_volume' => $candidate['search_volume'] ?? null,
                    'cpc' => $candidate['cpc'] ?? null,
                    'competition' => $candidate['competition'] ?? null,
                    'competition_index' => $candidate['competition_index'] ?? null,
                    'monthly_searches' => $candidate['monthly_searches'] ?? null,
                    'measurement_type' => 'provider_estimate',
                    'status' => 'pending',
                ],
            );
        }
        $run->forceFill(['expansion_committed_at' => now()])->save();
        } finally {
            $paidLock->release();
        }
    }

    private function buildClusterReviews(SearchDemandEnrichmentRun $run): void
    {
        $top = max(1, min(20, (int) config('moxdop.search_demand_enrichment.validation_top_results', 10)));
        $validated = (float) config('moxdop.search_demand_enrichment.validated_overlap_threshold', 0.30);
        $conflict = (float) config('moxdop.search_demand_enrichment.conflict_overlap_threshold', 0.10);
        $groups = $run->items->filter(fn (SearchDemandEnrichmentRunItem $item): bool => $item->search_demand_cluster_id !== null && $item->serpSnapshot !== null)
            ->groupBy('search_demand_cluster_id');

        foreach ($groups as $clusterId => $items) {
            $sets = $items->map(fn (SearchDemandEnrichmentRunItem $item): array => $item->serpSnapshot->results
                ->take($top)->pluck('url')->filter()->unique()->values()->all())->values();
            $scores = [];
            for ($left = 0; $left < $sets->count(); $left++) {
                for ($right = $left + 1; $right < $sets->count(); $right++) {
                    $union = array_unique(array_merge($sets[$left], $sets[$right]));
                    if ($union === []) {
                        continue;
                    }
                    $scores[] = count(array_intersect($sets[$left], $sets[$right])) / count($union);
                }
            }
            $mean = $scores === [] ? null : array_sum($scores) / count($scores);
            $recommendation = $mean === null
                ? 'review_required'
                : ($mean >= $validated ? 'serp_validated' : ($mean <= $conflict ? 'serp_conflict' : 'review_required'));
            $rationale = $mean === null
                ? 'Karşılaştırılabilir en az iki organik sonuç seti yok; insan incelemesi gerekir.'
                : sprintf('%d sorgu ve %d sorgu çifti için ilk %d organik URL Jaccard örtüşme ortalaması %.1f%%.', $sets->count(), count($scores), $top, $mean * 100);

            SearchDemandSerpClusterReview::query()->updateOrCreate(
                ['search_demand_enrichment_run_id' => $run->id, 'search_demand_cluster_id' => (int) $clusterId],
                [
                    'evidence_query_count' => $sets->count(),
                    'compared_pair_count' => count($scores),
                    'mean_url_overlap' => $mean,
                    'recommended_status' => $recommendation,
                    'threshold_basis' => [
                        'method' => 'mean_pairwise_jaccard_exact_url',
                        'top_organic_results' => $top,
                        'serp_validated_at_or_above' => $validated,
                        'serp_conflict_at_or_below' => $conflict,
                        'automatic_application' => false,
                    ],
                    'rationale' => $rationale,
                    'status' => 'pending',
                ],
            );
        }
    }

    private function assertContext(DigitalAsset $website, string $scopeType, int $scopeId, int $depth, string $device): void
    {
        if ((string) $website->type !== 'website') {
            throw ValidationException::withMessages(['selectedWebsiteId' => 'SERP zenginleştirmesi yalnız Website varlıklarında çalışır.']);
        }
        if (! $website->hasSeoMarketConfigured()) {
            throw ValidationException::withMessages(['selectedWebsiteId' => 'Ücretli çağrıdan önce Website SEO lokasyonu ve dili seçilmelidir.']);
        }
        if (! in_array($scopeType, [self::SCOPE_CLUSTER, self::SCOPE_SERVICE], true)) {
            throw ValidationException::withMessages(['scopeType' => 'Kapsam küme veya hizmet olmalıdır.']);
        }
        if ($scopeId <= 0 || ! in_array($depth, [10, 20], true) || ! in_array($device, ['desktop', 'mobile'], true)) {
            throw ValidationException::withMessages(['scopeId' => 'Geçerli kapsam, cihaz ve SERP derinliği seçin.']);
        }
        if ($scopeType === self::SCOPE_SERVICE && ! $website->brand->offerings()
            ->where('status', 'active')->where('service_catalog_item_id', $scopeId)->exists()) {
            throw ValidationException::withMessages(['scopeId' => 'Seçilen hizmet Website markasının etkin hizmetlerinden biri değil.']);
        }
    }

    /** @return Collection<int, BrandQueryPortfolioItem> */
    private function scopedItems(DigitalAsset $website, string $scopeType, int $scopeId): Collection
    {
        $query = BrandQueryPortfolioItem::query()
            ->with(['libraryItem', 'assetStates' => fn ($query) => $query->where('digital_asset_id', $website->id), 'clusterMembership'])
            ->where('brand_id', $website->brand_id)
            ->where('status', 'active')
            ->whereHas('assetStates', fn ($query) => $query->where('digital_asset_id', $website->id)->where('status', 'active'));
        if ($scopeType === self::SCOPE_CLUSTER) {
            $query->whereHas('clusterMembership.cluster', fn ($query) => $query->whereKey($scopeId)->where('brand_id', $website->brand_id)->where('status', 'active'));
        } else {
            $query->whereHas('services', fn ($query) => $query->where('service_catalog_items.id', $scopeId));
        }
        $max = max(1, min(20, (int) config('moxdop.search_demand_enrichment.max_queries_per_run', 20)));
        $items = $query->orderBy('id')->limit(200)->get();
        $representatives = $website->brand->searchDemandClusters()->where('status', 'active')->pluck('representative_portfolio_item_id')->filter()->flip();

        return $items->sortByDesc(fn (BrandQueryPortfolioItem $item): int => $representatives->has($item->id) ? 1 : 0)->take($max)->values();
    }

    private function freshSerp(DigitalAsset $website, string $fingerprint, int $ttlDays): ?SearchDemandSerpSnapshot
    {
        return SearchDemandSerpSnapshot::query()
            ->with('results')
            ->where('digital_asset_id', $website->id)
            ->where('request_fingerprint', $fingerprint)
            ->where('retrieved_at', '>', now()->subDays($ttlDays))
            ->latest('retrieved_at')->first();
    }

    private function freshMetric(DigitalAsset $website, string $fingerprint, int $ttlDays): ?SearchDemandKeywordMetricSnapshot
    {
        return SearchDemandKeywordMetricSnapshot::query()
            ->where('digital_asset_id', $website->id)
            ->where('request_fingerprint', $fingerprint)
            ->where('retrieved_at', '>', now()->subDays($ttlDays))
            ->latest('retrieved_at')->first();
    }

    private function serpFingerprint(DigitalAsset $website, string $queryText, int $depth, string $device): string
    {
        return PaidRequestFingerprint::make(ProviderRegistry::DATAFORSEO, 'search_demand_serp_enrichment', 'serp/google/organic/live/regular', [
            'keyword' => $queryText, 'location_code' => (int) $website->seo_market_location_code,
            'language_code' => (string) $website->seo_market_language_code, 'device' => $device, 'depth' => $depth,
        ]);
    }

    private function metricFingerprint(DigitalAsset $website, string $queryText): string
    {
        return PaidRequestFingerprint::make(ProviderRegistry::DATAFORSEO, 'search_demand_keyword_metrics', 'keywords_data/google_ads/search_volume/live', [
            'keyword' => $queryText, 'location_code' => (int) $website->seo_market_location_code,
            'language_code' => (string) $website->seo_market_language_code,
        ]);
    }

    /** @param list<array<string, mixed>> $queries */
    private function expansionFingerprint(DigitalAsset $website, array $queries): string
    {
        return PaidRequestFingerprint::make(ProviderRegistry::DATAFORSEO, 'search_demand_query_expansion', 'dataforseo_labs/google/keyword_ideas/live', [
            'keywords' => collect($queries)->pluck('query_text')->map(fn (mixed $query): string => (string) $query)->values()->all(),
            'location_code' => (int) $website->seo_market_location_code,
            'language_code' => (string) $website->seo_market_language_code,
            'limit' => max(1, min(100, (int) config('moxdop.search_demand_enrichment.max_expansion_candidates', 50))),
        ]);
    }

    private function freshExpansionPayload(string $fingerprint, int $ttlDays): ?SearchDemandProviderPayload
    {
        return SearchDemandProviderPayload::query()
            ->where('endpoint', 'dataforseo_labs/google/keyword_ideas/live')
            ->where('request_fingerprint', $fingerprint)
            ->where('captured_at', '>', now()->subDays($ttlDays))
            ->whereHas('run', fn ($query) => $query
                ->where('status', 'completed')
                ->whereNotNull('expansion_committed_at')
                ->where(fn ($errors) => $errors->whereNull('error_code')->orWhere('error_code', '!=', 'PARTIAL_EXPANSION_TASK_ERROR')))
            ->latest('captured_at')->first();
    }

    private function fold(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value), 'UTF-8');
    }

    private function isKeywordMetricEligible(string $query): bool
    {
        $words = preg_split('/\s+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return mb_strlen($query, 'UTF-8') <= 80 && count($words) <= 10;
    }
}
