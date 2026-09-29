<?php

namespace App\Services\Intel;

use App\Services\Integrations\DataForSeo\DataForSeoApiClient;
use App\Support\ServiceScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * DataForSEO top-10 organic SERP for one (query, location, language, device), cached 30 days in `serp_results`.
 * A cache hit makes no provider call; a miss makes one paid live call (spend guard + monthly cap apply, cost recorded
 * in dataforseo_tasks). Nothing is paid for a passive customer's brand.
 */
final class SerpResults
{
    public const int CACHE_DAYS = 30;

    public const array DEVICES = ['desktop', 'mobile'];

    public function __construct(
        private readonly DataForSeoApiClient $client,
        private readonly DataForSeoIntegrationLookup $integrations,
        private readonly DataForSeoTaskQueue $tasks,
    ) {}

    /**
     * @return array{query: string, location_code: int, language_code: string, device: string, cached: bool,
     *     fetched_at: string, results: list<array{rank: int, url: string, domain: string, title: ?string, type: string}>}
     */
    public function topTen(string $query, int $locationCode, string $languageCode, string $device = 'desktop', ?int $brandId = null, bool $refresh = false): array
    {
        $query = self::normalize($query);
        $device = in_array($device, self::DEVICES, true) ? $device : 'desktop';
        $languageCode = mb_strtolower(trim($languageCode));
        if ($query === '' || $locationCode < 1 || $languageCode === '') {
            throw new RuntimeException('SERP sorgusu, konum ve dil gerekli.');
        }
        $key = ['query_hash' => hash('sha256', $query), 'location_code' => $locationCode, 'language_code' => $languageCode, 'device' => $device];
        $cached = DB::table('serp_results')->where($key)->first();
        if (! $refresh && $cached !== null && CarbonImmutable::parse($cached->fetched_at)->greaterThan(now()->subDays(self::CACHE_DAYS))) {
            return $this->shape($cached, true);
        }
        if ($brandId !== null && ! app(ServiceScope::class)->isBrandOperational($brandId)) {
            throw new RuntimeException(ServiceScope::NOT_SERVED);
        }
        $integration = $this->integrations->active() ?? throw new RuntimeException('DataForSEO bağlantısı yok.');
        $response = $this->client->postSerpGoogleOrganicLiveAdvanced($integration, [[
            'keyword' => $query, 'location_code' => $locationCode, 'language_code' => $languageCode,
            'device' => $device, 'depth' => 10,
        ]]);
        $task = (array) ($response->tasks[0] ?? []);
        $cost = (float) ($task['cost'] ?? $response->cost ?? 0);
        $this->tasks->recordLive('serp_top10', $brandId, 'serp/google/organic/live/advanced', $cost,
            error: (int) ($task['status_code'] ?? 0) === 20000 ? null : (string) ($task['status_message'] ?? 'SERP alınamadı.'));
        if ((int) ($task['status_code'] ?? 0) !== 20000) {
            throw new RuntimeException('DataForSEO SERP alınamadı: '.(string) ($task['status_message'] ?? ''));
        }
        $results = self::topResults((array) data_get($task, 'result.0.items', []));
        DB::table('serp_results')->updateOrInsert($key, [
            'query' => mb_substr($query, 0, 500),
            'results' => json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'cost_usd' => round($cost, 5),
            'fetched_at' => now(),
            'updated_at' => now(),
            'created_at' => $cached->created_at ?? now(),
        ]);

        return $this->shape(DB::table('serp_results')->where($key)->first(), false);
    }

    /**
     * The first ten organic results plus the SERP features shown above the tenth one.
     *
     * @param  list<mixed>  $items
     * @return list<array{rank: int, url: string, domain: string, title: ?string, type: string}>
     */
    public static function topResults(array $items): array
    {
        $organic = array_values(array_filter($items, fn ($item): bool => is_array($item) && ($item['type'] ?? null) === 'organic' && filled($item['url'] ?? null)));
        usort($organic, fn (array $a, array $b): int => (int) ($a['rank_group'] ?? 0) <=> (int) ($b['rank_group'] ?? 0));
        $organic = array_slice($organic, 0, 10);
        $cutoff = $organic === [] ? PHP_INT_MAX : (int) max(array_map(fn (array $item): int => (int) ($item['rank_absolute'] ?? 0), $organic));
        $out = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! filled($item['url'] ?? null) || (int) ($item['rank_absolute'] ?? PHP_INT_MAX) > $cutoff) {
                continue;
            }
            $type = (string) ($item['type'] ?? 'organic');
            if ($type === 'organic' && ! in_array($item, $organic, true)) {
                continue;
            }
            $url = (string) $item['url'];
            $out[] = [
                'rank' => (int) ($type === 'organic' ? ($item['rank_group'] ?? 0) : ($item['rank_absolute'] ?? 0)),
                'url' => $url,
                'domain' => (string) ($item['domain'] ?? parse_url($url, PHP_URL_HOST) ?? ''),
                'title' => filled($item['title'] ?? null) ? (string) $item['title'] : null,
                'type' => $type,
            ];
        }

        return $out;
    }

    /** Lower case with Turkish dotted İ → i (mb_strtolower would leave a combining dot), single spaces. */
    public static function normalize(string $query): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower(str_replace('İ', 'i', $query))) ?? '');
    }

    /** @return array{query: string, location_code: int, language_code: string, device: string, cached: bool, fetched_at: string, results: list<array{rank: int, url: string, domain: string, title: ?string, type: string}>} */
    private function shape(object $row, bool $cached): array
    {
        return [
            'query' => (string) $row->query,
            'location_code' => (int) $row->location_code,
            'language_code' => (string) $row->language_code,
            'device' => (string) $row->device,
            'cached' => $cached,
            'fetched_at' => (string) $row->fetched_at,
            'results' => (array) json_decode((string) $row->results, true),
        ];
    }
}
