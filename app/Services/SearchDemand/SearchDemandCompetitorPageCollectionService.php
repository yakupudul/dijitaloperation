<?php

namespace App\Services\SearchDemand;

use App\Models\Run;
use App\Models\SearchDemandCluster;
use App\Models\SearchDemandCompetitor;
use App\Models\SearchDemandCompetitorPageObservation;
use App\Models\SearchDemandCompetitorPageRunItem;
use App\Models\SearchDemandCompetitorUrl;
use App\Services\Async\AsyncOperationService;
use App\Support\Async\AsyncFailureClassifier;
use App\Support\Async\AsyncOperationTypes;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use MoxDop\Website\Discovery\PublicHttpFetcher;

final class SearchDemandCompetitorPageCollectionService
{
    public const int MAX_URLS_PER_RUN = 20;

    public const int MAX_URLS_PER_COMPETITOR = 3;

    public function __construct(
        private readonly PublicHttpFetcher $fetcher = new PublicHttpFetcher,
        private readonly CompetitorPageContentExtractor $extractor = new CompetitorPageContentExtractor,
    ) {}

    /**
     * @return list<array{competitor_id:int,competitor_name:string,url_id:int,url:string,url_hash:string,best_observed_rank:?int}>
     */
    public function preview(SearchDemandCluster $cluster, int $limit = 10): array
    {
        $limit = max(1, min(self::MAX_URLS_PER_RUN, $limit));
        $competitors = SearchDemandCompetitor::query()
            ->where('brand_id', $cluster->brand_id)
            ->where('status', 'approved')
            ->whereHas('clusters', fn ($query) => $query->whereKey($cluster->id))
            ->with([
                'urls',
                'queries' => fn ($query) => $query->with('clusterMembership'),
            ])
            ->limit(100)
            ->get()
            ->map(function (SearchDemandCompetitor $competitor) use ($cluster): array {
                $ranks = $competitor->queries
                    ->filter(fn ($query): bool => (int) ($query->clusterMembership?->search_demand_cluster_id) === (int) $cluster->id)
                    ->map(fn ($query): mixed => $query->pivot->best_observed_rank)
                    ->filter(fn (mixed $rank): bool => is_numeric($rank))
                    ->map(fn (mixed $rank): int => (int) $rank);

                return [
                    'competitor' => $competitor,
                    'best_rank' => $ranks->isEmpty() ? null : $ranks->min(),
                ];
            })
            ->sort(function (array $left, array $right): int {
                $leftRank = $left['best_rank'] ?? PHP_INT_MAX;
                $rightRank = $right['best_rank'] ?? PHP_INT_MAX;

                return $leftRank <=> $rightRank ?: $left['competitor']->id <=> $right['competitor']->id;
            });

        $rows = [];
        $seen = [];
        foreach ($competitors as $entry) {
            /** @var SearchDemandCompetitor $competitor */
            $competitor = $entry['competitor'];
            $urls = $competitor->urls
                ->sort(function (SearchDemandCompetitorUrl $left, SearchDemandCompetitorUrl $right): int {
                    $leftSource = $left->source_type === 'dataforseo_serp' ? 0 : 1;
                    $rightSource = $right->source_type === 'dataforseo_serp' ? 0 : 1;
                    $sourceComparison = $leftSource <=> $rightSource;
                    if ($sourceComparison !== 0) {
                        return $sourceComparison;
                    }
                    $timeComparison = ($right->last_observed_at?->getTimestamp() ?? 0)
                        <=> ($left->last_observed_at?->getTimestamp() ?? 0);

                    return $timeComparison !== 0 ? $timeComparison : $left->id <=> $right->id;
                })
                ->take(self::MAX_URLS_PER_COMPETITOR);

            foreach ($urls as $url) {
                if (isset($seen[$url->normalized_url_hash])) {
                    continue;
                }
                $seen[$url->normalized_url_hash] = true;
                $rows[] = [
                    'competitor_id' => (int) $competitor->id,
                    'competitor_name' => (string) $competitor->display_name,
                    'url_id' => (int) $url->id,
                    'url' => (string) $url->url,
                    'url_hash' => (string) $url->normalized_url_hash,
                    'best_observed_rank' => $entry['best_rank'],
                ];
                if (count($rows) >= $limit) {
                    break 2;
                }
            }
        }

        return $rows;
    }

    public function execute(int $runId, AsyncOperationService $async): void
    {
        $run = Run::query()->with('digitalAsset')->findOrFail($runId);
        if (data_get($run->metadata, 'operation_type') !== AsyncOperationTypes::SEARCH_DEMAND_COMPETITOR_PAGE_COLLECTION) {
            throw ValidationException::withMessages(['run' => 'Run rakip sayfa toplama işlemi değil.']);
        }
        $asset = $run->digitalAsset;
        if ($asset === null || $asset->type !== 'website') {
            throw ValidationException::withMessages(['website' => 'Rakip sayfa toplama için Website gerekir.']);
        }
        $clusterId = data_get($run->metadata, 'cluster_id');
        $limit = (int) data_get($run->metadata, 'max_urls', 10);
        $cluster = SearchDemandCluster::query()
            ->where('brand_id', $asset->brand_id)
            ->where('status', 'active')
            ->whereNotNull('content_target_cluster')
            ->where('content_target_cluster', '!=', '')
            ->findOrFail((int) $clusterId);

        $async->markRunning($run, 'selecting_urls', 'Selecting bounded competitor URLs');
        $candidates = $this->preview($cluster, $limit);
        if ($candidates === []) {
            $async->markFinished($run->fresh() ?? $run, 'failed', 'No eligible URLs', [
                'result_summary' => 'The selected cluster has no URLs on approved linked competitors.',
                'failure_summary' => 'Approve and link competitors with observed URLs before collecting pages.',
                'failure_category' => AsyncFailureClassifier::VALIDATION,
                'retryable' => false,
                'selected_count' => 0,
            ]);

            return;
        }

        foreach ($candidates as $index => $candidate) {
            SearchDemandCompetitorPageRunItem::query()->firstOrCreate(
                ['run_id' => $run->id, 'normalized_url_hash' => $candidate['url_hash']],
                [
                    'search_demand_cluster_id' => $cluster->id,
                    'search_demand_competitor_id' => $candidate['competitor_id'],
                    'search_demand_competitor_url_id' => $candidate['url_id'],
                    'requested_url' => $candidate['url'],
                    'selection_order' => $index + 1,
                    'best_observed_rank' => $candidate['best_observed_rank'],
                    'status' => 'queued',
                ],
            );
        }

        $items = SearchDemandCompetitorPageRunItem::query()
            ->with([
                'competitor.services.names',
                'competitor.serviceAreas',
                'competitorUrl',
                'cluster.memberships.portfolioItem.services.names',
                'cluster.memberships.portfolioItem.serviceAreas',
            ])
            ->where('run_id', $run->id)
            ->orderBy('selection_order')
            ->get();
        $counts = ['completed' => 0, 'unchanged' => 0, 'failed' => 0];

        foreach ($items as $position => $item) {
            if (in_array($item->status, ['completed', 'unchanged'], true)) {
                $counts[$item->status]++;

                continue;
            }
            $async->setPhase(
                $run->fresh() ?? $run,
                'collecting_pages',
                sprintf('Collecting competitor page %d of %d', $position + 1, $items->count()),
                'running',
            );
            $item->update(['status' => 'running', 'started_at' => now(), 'error_summary' => null]);
            $status = $this->collectItem($item);
            $counts[$status]++;
        }

        $successful = $counts['completed'] + $counts['unchanged'];
        $status = match (true) {
            $counts['failed'] === 0 => 'completed',
            $successful > 0 => 'partial',
            default => 'failed',
        };
        $label = match ($status) {
            'completed' => 'Completed',
            'partial' => 'Completed with gaps',
            default => 'Failed',
        };
        $summary = sprintf(
            '%d page processed, %d unchanged, %d failed. Exact selected URLs only; no site-wide crawl.',
            $counts['completed'],
            $counts['unchanged'],
            $counts['failed'],
        );
        $async->markFinished($run->fresh() ?? $run, $status, $label, [
            'result_summary' => $summary,
            'failure_summary' => $status === 'failed' ? $summary : null,
            'retryable' => $status !== 'completed',
            'cluster_id' => $cluster->id,
            'selected_count' => $items->count(),
            'processed_count' => $counts['completed'],
            'unchanged_count' => $counts['unchanged'],
            'failed_count' => $counts['failed'],
        ]);
    }

    private function collectItem(SearchDemandCompetitorPageRunItem $item): string
    {
        $observedAt = now();
        $previous = SearchDemandCompetitorPageObservation::query()
            ->where('search_demand_competitor_url_id', $item->search_demand_competitor_url_id)
            ->where('run_item_id', '!=', $item->id)
            ->whereIn('status', ['completed', 'unchanged'])
            ->latest('observed_at')
            ->latest('id')
            ->first();
        $fetch = $this->fetcher->fetch($item->requested_url);
        $body = is_string($fetch['body'] ?? null) ? $fetch['body'] : null;

        if (($fetch['ok'] ?? false) !== true || $body === null || ! $this->isHtml($fetch, $body)) {
            $error = (string) ($fetch['error'] ?? ($body === null ? 'empty_response' : 'unsupported_non_html_response'));
            $this->observation($item, $previous, $fetch, [
                'status' => 'failed',
                'fetch_error' => mb_substr($error, 0, 4000),
                'observed_at' => $observedAt,
            ]);
            $item->update(['status' => 'failed', 'error_summary' => mb_substr($error, 0, 4000), 'finished_at' => now()]);

            return 'failed';
        }

        $finalUrl = (string) ($fetch['final_url'] ?? $item->requested_url);
        if ($this->normalizedDomain($finalUrl) !== $item->competitor?->normalized_domain) {
            $error = 'redirect_outside_competitor_domain';
            $this->observation($item, $previous, $fetch, [
                'status' => 'failed',
                'fetch_error' => $error,
                'observed_at' => $observedAt,
            ]);
            $item->update(['status' => 'failed', 'error_summary' => $error, 'finished_at' => now()]);

            return 'failed';
        }

        $rawHash = hash('sha256', $body);
        $contentSource = $previous?->content_source_observation_id ?: $previous?->id;
        if ($previous !== null && is_string($previous->raw_html_hash) && hash_equals($previous->raw_html_hash, $rawHash)) {
            $this->observation($item, $previous, $fetch, [
                'status' => 'unchanged',
                'raw_html_hash' => $rawHash,
                'content_fingerprint' => $previous->content_fingerprint,
                'content_changed' => false,
                'content_source_observation_id' => $contentSource,
                'observed_at' => $observedAt,
            ]);
            $this->completeItem($item, 'unchanged', $observedAt);

            return 'unchanged';
        }

        $competitor = $item->competitor;
        $clusterPortfolioItems = $item->cluster === null
            ? collect()
            : $item->cluster->memberships->pluck('portfolioItem')->filter();
        $services = collect($competitor?->services ?? [])
            ->concat($clusterPortfolioItems->flatMap(fn ($portfolioItem): Collection => $portfolioItem->services))
            ->unique('id');
        $areas = collect($competitor?->serviceAreas ?? [])
            ->concat($clusterPortfolioItems->flatMap(fn ($portfolioItem): Collection => $portfolioItem->serviceAreas))
            ->unique('id');
        $serviceExpressions = $services
            ->flatMap(fn ($service): Collection => $service->names->where('is_active', true)->pluck('raw_label'))
            ->filter()->unique()->values()->all();
        $locationExpressions = $areas
            ->flatMap(fn ($area): array => [$area->country_name, $area->country_code, $area->city_name, $area->district_name])
            ->filter()->unique()->values()->all();
        $content = $this->extractor->extract($finalUrl, $body, $serviceExpressions, $locationExpressions);
        $fingerprint = (string) $content['content_fingerprint'];
        if ($previous !== null && is_string($previous->content_fingerprint) && hash_equals($previous->content_fingerprint, $fingerprint)) {
            $this->observation($item, $previous, $fetch, [
                'status' => 'unchanged',
                'raw_html_hash' => $rawHash,
                'content_fingerprint' => $fingerprint,
                'content_changed' => false,
                'content_source_observation_id' => $contentSource,
                'observed_at' => $observedAt,
            ]);
            $this->completeItem($item, 'unchanged', $observedAt);

            return 'unchanged';
        }

        $this->observation($item, $previous, $fetch, [
            'status' => 'completed',
            'raw_html_hash' => $rawHash,
            'content_fingerprint' => $fingerprint,
            'content_changed' => $previous === null ? null : true,
            'normalized_text' => $content['normalized_text'],
            'title' => $content['title'],
            'meta_description' => $content['meta_description'],
            'h1' => $content['h1'],
            'headings' => $content['headings'],
            'schema_summary' => $content['schema_summary'],
            'internal_links' => $content['internal_links'],
            'external_links' => $content['external_links'],
            'service_expressions' => $content['service_expressions'],
            'location_expressions' => $content['location_expressions'],
            'normalization_version' => CompetitorPageContentExtractor::VERSION,
            'observed_at' => $observedAt,
        ]);
        $this->completeItem($item, 'completed', $observedAt);

        return 'completed';
    }

    /** @param array<string, mixed> $fetch @param array<string, mixed> $payload */
    private function observation(
        SearchDemandCompetitorPageRunItem $item,
        ?SearchDemandCompetitorPageObservation $previous,
        array $fetch,
        array $payload,
    ): SearchDemandCompetitorPageObservation {
        return SearchDemandCompetitorPageObservation::query()->updateOrCreate(
            ['run_item_id' => $item->id],
            array_merge([
                'search_demand_competitor_url_id' => $item->search_demand_competitor_url_id,
                'previous_observation_id' => $previous?->id,
                'content_source_observation_id' => null,
                'requested_url' => $item->requested_url,
                'final_url' => $fetch['final_url'] ?? null,
                'http_status' => $fetch['status_code'] ?? null,
                'content_type' => $fetch['content_type'] ?? null,
                'response_bytes' => $fetch['bytes'] ?? null,
                'redirect_count' => $fetch['redirect_count'] ?? 0,
                'fetch_error' => null,
                'raw_html_hash' => null,
                'content_fingerprint' => null,
                'content_changed' => null,
                'normalized_text' => null,
                'title' => null,
                'meta_description' => null,
                'h1' => null,
                'headings' => null,
                'schema_summary' => null,
                'internal_links' => null,
                'external_links' => null,
                'service_expressions' => null,
                'location_expressions' => null,
                'normalization_version' => CompetitorPageContentExtractor::VERSION,
            ], $payload),
        );
    }

    private function completeItem(SearchDemandCompetitorPageRunItem $item, string $status, mixed $observedAt): void
    {
        $item->update(['status' => $status, 'error_summary' => null, 'finished_at' => now()]);
        $url = $item->competitorUrl;
        if ($url !== null && ($url->last_observed_at === null || $url->last_observed_at->lessThan($observedAt))) {
            $url->update(['last_observed_at' => $observedAt]);
        }
    }

    /** @param array<string, mixed> $fetch */
    private function isHtml(array $fetch, string $body): bool
    {
        $type = mb_strtolower((string) ($fetch['content_type'] ?? ''));

        return str_contains($type, 'text/html')
            || str_contains($type, 'application/xhtml+xml')
            || ($type === '' && preg_match('/<(?:!doctype\s+html|html|head|body)\b/i', $body) === 1);
    }

    private function normalizedDomain(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || trim($host) === '') {
            return null;
        }
        $host = mb_strtolower(trim($host, '.'));

        return str_starts_with($host, 'www.') ? mb_substr($host, 4) : $host;
    }
}
