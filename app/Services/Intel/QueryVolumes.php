<?php

namespace App\Services\Intel;

use App\Models\CoreIntegration;
use App\Services\Integrations\DataForSeo\DataForSeoApiClient;
use App\Support\ServiceScope;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * DataForSEO (Google Ads) search volume for a list of queries in one location / language, cached 90 days in
 * `query_volumes`. Only the queries missing from the cache (or expired) are sent, in batches of up to 1000 per paid
 * live call (spend guard + monthly cap apply, cost recorded in dataforseo_tasks). Queries the provider cannot take
 * (over 80 characters or 10 words) get no volume without a call.
 */
final class QueryVolumes
{
    public const int CACHE_DAYS = 90;

    public const int BATCH = 1000;

    public function __construct(
        private readonly DataForSeoApiClient $client,
        private readonly DataForSeoIntegrationLookup $integrations,
        private readonly DataForSeoTaskQueue $tasks,
    ) {}

    /**
     * Monthly refresh (moxdop:intel:query-volumes): volume only for what the product plans with — the main query of
     * each cluster used by an operational brand (queries.volume) and the brands' target queries (query_volumes only).
     * AI-suggested main queries get no volume: nothing unmeasured is shown as measured. The 90-day cache means a
     * month re-sends only expired queries; the DataForSEO spend guard stops the run at the monthly cap.
     *
     * @return array{main_queries: int, target_queries: int}
     */
    public function refreshPlanned(): array
    {
        $brandIds = app(ServiceScope::class)->operationalBrandIds();
        $rows = $brandIds === [] ? collect() : DB::table('brand_cluster_pages')->whereIn('brand_id', $brandIds)->get(['cluster_id', 'target_query', 'language']);
        $location = (int) config('moxdop-intel.query_volumes.location_code', 2792);
        $default = (string) config('moxdop-intel.query_volumes.default_language', 'tr');

        $main = $rows->isEmpty() ? collect() : DB::table('clusters')->join('queries', 'queries.id', '=', 'clusters.main_query_id')
            ->whereIn('clusters.id', $rows->pluck('cluster_id')->unique()->values()->all())->where('queries.is_suggested', false)
            ->distinct()->get(['queries.id', 'queries.text']);
        if ($main->isNotEmpty()) {
            $volumes = $this->volumes($main->pluck('text')->all(), $location, $default);
            foreach ($main as $query) {
                $volume = $volumes[SerpResults::normalize((string) $query->text)] ?? null;
                DB::table('queries')->where('id', $query->id)->update(['volume' => $volume, 'updated_at' => now()]);
            }
        }

        $targets = 0;
        foreach ($rows->filter(fn (object $row): bool => filled($row->target_query))->groupBy(fn (object $row): string => mb_strtolower((string) ($row->language ?: $default))) as $language => $group) {
            $targets += count($this->volumes($group->pluck('target_query')->unique()->values()->all(), $location, $language));
        }

        return ['main_queries' => $main->count(), 'target_queries' => $targets];
    }

    /**
     * @param  list<string>  $queries
     * @return array<string, ?int> normalized query => monthly search volume (null = unknown)
     */
    public function volumes(array $queries, int $locationCode, string $languageCode, ?int $brandId = null): array
    {
        $languageCode = mb_strtolower(trim($languageCode));
        $wanted = [];
        foreach ($queries as $query) {
            $normalized = SerpResults::normalize((string) $query);
            if ($normalized !== '') {
                $wanted[hash('sha256', $normalized)] = $normalized;
            }
        }
        if ($wanted === []) {
            return [];
        }
        $fresh = [];
        foreach (array_chunk(array_keys($wanted), 500) as $hashes) {
            DB::table('query_volumes')->where('location_code', $locationCode)->where('language_code', $languageCode)
                ->whereIn('query_hash', $hashes)->where('fetched_at', '>', now()->subDays(self::CACHE_DAYS))
                ->get(['query_hash', 'volume'])
                ->each(function (object $row) use (&$fresh): void {
                    $fresh[(string) $row->query_hash] = $row->volume !== null ? (int) $row->volume : null;
                });
        }
        $missing = array_diff_key($wanted, $fresh);
        $sendable = array_filter($missing, static fn (string $q): bool => mb_strlen($q) <= 80 && count(explode(' ', $q)) <= 10);
        if ($sendable !== []) {
            if ($brandId !== null && ! app(ServiceScope::class)->isBrandOperational($brandId)) {
                throw new RuntimeException(ServiceScope::NOT_SERVED);
            }
            $integration = $this->integrations->active() ?? throw new RuntimeException('DataForSEO bağlantısı yok.');
            foreach (array_chunk(array_values($sendable), self::BATCH) as $batch) {
                $fresh += $this->fetch($integration, $batch, $locationCode, $languageCode, $brandId);
            }
        }

        $out = [];
        foreach ($wanted as $hash => $query) {
            $out[$query] = $fresh[$hash] ?? null;
        }

        return $out;
    }

    /**
     * @param  list<string>  $batch
     * @return array<string, ?int> query hash => volume
     */
    private function fetch(CoreIntegration $integration, array $batch, int $locationCode, string $languageCode, ?int $brandId): array
    {
        $response = $this->client->postGoogleAdsSearchVolumeLive($integration, [[
            'keywords' => $batch, 'location_code' => $locationCode, 'language_code' => $languageCode,
        ]]);
        $task = (array) ($response->tasks[0] ?? []);
        $ok = (int) ($task['status_code'] ?? 0) === 20000;
        $this->tasks->recordLive('query_volume', $brandId, 'keywords_data/google_ads/search_volume/live', (float) ($task['cost'] ?? $response->cost ?? 0),
            error: $ok ? null : (string) ($task['status_message'] ?? 'Arama hacmi alınamadı.'));
        if (! $ok) {
            throw new RuntimeException('DataForSEO arama hacmi alınamadı: '.(string) ($task['status_message'] ?? ''));
        }
        $byQuery = [];
        foreach ((array) ($task['result'] ?? []) as $row) {
            if (is_array($row) && filled($row['keyword'] ?? null)) {
                $byQuery[SerpResults::normalize((string) $row['keyword'])] = $row;
            }
        }
        $out = [];
        foreach ($batch as $query) {
            $row = $byQuery[$query] ?? [];
            $volume = is_numeric($row['search_volume'] ?? null) ? (int) $row['search_volume'] : null;
            $hash = hash('sha256', $query);
            DB::table('query_volumes')->updateOrInsert(
                ['query_hash' => $hash, 'location_code' => $locationCode, 'language_code' => $languageCode],
                [
                    'query' => mb_substr($query, 0, 500),
                    'volume' => $volume,
                    'monthly' => is_array($row['monthly_searches'] ?? null) ? json_encode(array_values(array_map(
                        static fn ($m): array => ['year' => (int) ($m['year'] ?? 0), 'month' => (int) ($m['month'] ?? 0), 'volume' => is_numeric($m['search_volume'] ?? null) ? (int) $m['search_volume'] : null],
                        array_filter($row['monthly_searches'], 'is_array'),
                    ))) : null,
                    'competition' => is_numeric($row['competition_index'] ?? null) ? round((float) $row['competition_index'] / 100, 4) : null,
                    'cpc' => is_numeric($row['cpc'] ?? null) ? (float) $row['cpc'] : null,
                    'fetched_at' => now(),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
            $out[$hash] = $volume;
        }

        return $out;
    }
}
