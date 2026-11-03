<?php

namespace App\Services\Intel;

use App\Services\Integrations\DataForSeo\DataForSeoApiClient;
use App\Services\Site\SiteDomains;
use App\Support\ServiceScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Top-10 organic SERP for one (query, location, language, device), kept in `serp_results`: a DataForSEO one for 30
 * days, one the operator pasted from Google (source manual, Backlink Faz 0: no paid call needed) for 120 days. A kept
 * one makes no provider call; a miss makes one paid live call only while a DataForSEO connection exists (spend guard +
 * monthly cap apply, cost recorded in dataforseo_tasks). Nothing is paid for a passive customer's brand.
 */
final class SerpResults
{
    public const int CACHE_DAYS = 30;

    /** A pasted top-10 holds a quarter (yakup 2026-10-25: competitors are looked at quarterly). */
    public const int MANUAL_DAYS = 120;

    public const string MANUAL = 'manual';

    /** Most results kept from one paste. */
    public const int MANUAL_MAX = 10;

    /** Endings a pasted address may have ("Dr.Ahmet" in a title is not a site). */
    private const string TLDS = 'com|net|org|tr|info|biz|io|co|me|uk|de|eu|nl|fr|it|es|ru|az|us|ca|au|app|dev|site|online|xyz|health|clinic|dental|shop|store|tv|edu|gov|ai';

    /** Search engines and their own hosts: never a result. */
    private const string SKIP_HOSTS = '/(^|\.)(google\.[a-z.]+|googleusercontent\.com|gstatic\.com|bing\.com|yandex\.[a-z.]+|webcache\.googleusercontent\.com)$/';

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
        $days = ($cached->source ?? null) === self::MANUAL ? self::MANUAL_DAYS : self::CACHE_DAYS;
        if (! $refresh && $cached !== null && CarbonImmutable::parse($cached->fetched_at)->greaterThan(now()->subDays($days))) {
            return $this->shape($cached, true);
        }
        if ($brandId !== null && ! app(ServiceScope::class)->isBrandOperational($brandId)) {
            throw new RuntimeException(ServiceScope::NOT_SERVED);
        }
        $integration = $this->integrations->active() ?? throw new RuntimeException('Bu aramanın sonuçları yok: Google\'da arayıp ilk 10 adresi yapıştırın.');
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
            'source' => 'dataforseo',
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

    /**
     * A top-10 the operator copied from Google (the addresses, or the whole results text): stored like a fetched one
     * (source manual), so the competitor refresh and Anılma fırsatları read it without a paid call.
     *
     * @return int how many results were kept
     */
    public function storeManual(string $query, int $locationCode, string $languageCode, string $device, string $pasted): int
    {
        $query = self::normalize($query);
        $results = self::parsePasted($pasted);
        if ($query === '' || $results === []) {
            return 0;
        }
        $key = ['query_hash' => hash('sha256', $query), 'location_code' => $locationCode, 'language_code' => mb_strtolower(trim($languageCode)),
            'device' => in_array($device, self::DEVICES, true) ? $device : 'desktop'];
        $cached = DB::table('serp_results')->where($key)->first();
        DB::table('serp_results')->updateOrInsert($key, [
            'query' => mb_substr($query, 0, 500),
            'results' => json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'cost_usd' => 0,
            'source' => self::MANUAL,
            'fetched_at' => now(),
            'updated_at' => now(),
            'created_at' => $cached->created_at ?? now(),
        ]);

        return count($results);
    }

    /**
     * Results from pasted Google text, in order: full addresses (https://…) and Google's shown form ("example.com › hizmet
     * › implant"); search engine hosts and repeats are dropped, at most 10.
     *
     * @return list<array{rank: int, url: string, domain: string, title: ?string, type: string}>
     */
    public static function parsePasted(string $text): array
    {
        preg_match_all('#(?:(https?)://)?((?:[a-z0-9\x{00C0}-\x{024F}](?:[a-z0-9\x{00C0}-\x{024F}-]*[a-z0-9\x{00C0}-\x{024F}])?\.)+(?:'.self::TLDS.'))(?![a-z0-9-])((?:/[^\s"<>]*)|(?:\s+›\s+[^\s›]+(?:\s+›\s+[^\s›]+)*))?#iu', $text, $matches, PREG_SET_ORDER);
        $out = [];
        $seen = [];
        foreach ($matches as $match) {
            $host = mb_strtolower($match[2]);
            if (preg_match(self::SKIP_HOSTS, $host) === 1 || preg_match('/\.(png|jpe?g|gif|svg|webp|css|js|php|html?)$/', $host) === 1) {
                continue;
            }
            $tail = (string) ($match[3] ?? '');
            $path = str_starts_with($tail, '/') ? rtrim($tail, '.,;)') : (trim($tail) !== '' ? '/'.implode('/', array_map('trim', array_slice(explode('›', $tail), 1))) : '/');
            if (preg_match('#/.*(\.\.\.|…)#u', $path) === 1) {
                $path = '/'; // Google shortens long shown paths ("› blog › …"): only the site is certain
            }
            $url = ($match[1] !== '' ? mb_strtolower($match[1]) : 'https').'://'.$host.$path;
            $id = SiteDomains::host($url).'|'.rtrim($path, '/');
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = ['rank' => count($out) + 1, 'url' => $url, 'domain' => $host, 'title' => null, 'type' => 'organic'];
            if (count($out) >= self::MANUAL_MAX) {
                break;
            }
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
