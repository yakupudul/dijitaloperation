<?php

namespace App\Services\Queries;

use App\Models\CoreAssetBinding;
use App\Services\BrandSetup\BrandSetupMatcher;
use App\Services\Demand\DataForSeoIntegrationLookup;
use App\Services\Integrations\DataForSeo\DataForSeoApiClient;
use App\Services\SeoTasks\SeoText;
use App\Support\ServiceScope;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Page-type research of a cluster: Google's top 10 for the cluster's head query (DataForSEO SERP, one query per
 * cluster, paid — the DataForSEO global spend guard applies) is classified per result (service / landing page,
 * article / blog, forum / FAQ, comparison, directory) and the majority decides where the cluster belongs:
 * hizmet sayfası, blog, SSS or karşılaştırma. The result is cached 30 days per head query (reused by every cluster
 * with the same head). Only clusters of services seen on an operational brand's accounts are researched. Without
 * DataForSEO the AI's guess stays, marked "kanıt yok". An operator decision (decision_source = manual) is never
 * overwritten.
 */
final class ClusterPageResearch
{
    /** Hosts whose pages are directories / marketplaces (the searcher wants a provider → service page). */
    private const array DIRECTORY_HOSTS = ['doktortakvimi.com', 'doktorsitesi.com', 'armut.com', 'sahibinden.com', 'yelp.com', 'tripadvisor.com',
        'hangikredi.com', 'hastanerandevu.gov.tr', 'mhrs.gov.tr', 'google.com', 'maps.google.com', 'booking.com', 'trendyol.com', 'hepsiburada.com'];

    /** Hosts whose pages are forums / Q&A. */
    private const array FORUM_HOSTS = ['eksisozluk.com', 'quora.com', 'reddit.com', 'donanimhaber.com', 'kadinlarkulubu.com', 'forum.donanimhaber.com',
        'sikayetvar.com', 'kizlarsoruyor.com', 'soruvecevap.com', 'youtube.com', 'instagram.com', 'facebook.com', 'tiktok.com'];

    public function __construct(
        private readonly DataForSeoApiClient $client,
        private readonly DataForSeoIntegrationLookup $integrations,
    ) {}

    /**
     * Weekly: clusters never researched, whose head query changed, or whose evidence is older than the cache window.
     *
     * @return array{checked: int, reused: int, skipped: int, failed: int}
     */
    public function researchDue(?int $limit = null): array
    {
        $stats = ['checked' => 0, 'reused' => 0, 'skipped' => 0, 'failed' => 0];
        if (! config('moxdop-queries.serp.enabled', true) || $this->integrations->active() === null) {
            return $stats;
        }
        $clusters = DB::table('library_query_clusters')->where('status', 'active')->whereNotNull('head_query')
            ->where(fn ($q) => $q->whereNull('decision_source')->orWhere('decision_source', '!=', 'manual'))
            ->whereIn('service_id', $this->relevantServiceIds())
            ->where(fn ($q) => $q->whereNull('researched_at')->orWhere('researched_at', '<', now()->subDays($this->cacheDays())))
            ->orderBy('researched_at')->orderBy('id')->limit($limit ?? (int) config('moxdop-queries.serp.per_run', 40))->get();
        $changed = DB::table('library_query_clusters')->where('status', 'active')->whereNotNull('head_query')->whereNotNull('researched_at')
            ->where(fn ($q) => $q->whereNull('decision_source')->orWhere('decision_source', '!=', 'manual'))
            ->whereIn('service_id', $this->relevantServiceIds())->get()
            ->filter(fn ($c): bool => $c->research_fingerprint !== $this->fingerprint((string) $c->head_query));
        foreach ($clusters->merge($changed)->unique('id') as $cluster) {
            $outcome = $this->research($cluster);
            $stats[$outcome]++;
        }

        return $stats;
    }

    /** @return 'checked'|'reused'|'skipped'|'failed' */
    public function research(object $cluster): string
    {
        $query = trim((string) $cluster->head_query);
        if ($query === '') {
            return 'skipped';
        }
        $fingerprint = $this->fingerprint($query);
        $cached = DB::table('library_query_clusters')->where('research_fingerprint', $fingerprint)->whereNotNull('serp_evidence')
            ->where('researched_at', '>=', now()->subDays($this->cacheDays()))->orderByDesc('researched_at')->first();
        if ($cached !== null) {
            $evidence = json_decode((string) $cached->serp_evidence, true) ?: [];
            $this->store($cluster, $fingerprint, $evidence, $evidence['decision'] ?? null);

            return (int) $cached->id === (int) $cluster->id ? 'skipped' : 'reused';
        }
        $integration = $this->integrations->active();
        if ($integration === null) {
            return 'skipped';
        }
        try {
            $response = $this->client->postSerpGoogleOrganicLiveRegular($integration, [[
                'keyword' => $query, 'location_code' => (int) config('moxdop-queries.serp.location_code', 2792),
                'language_code' => (string) config('moxdop-queries.serp.language_code', 'tr'), 'device' => 'desktop', 'depth' => 10,
            ]]);
        } catch (Throwable $exception) {
            report($exception);

            return 'failed';
        }
        $results = [];
        foreach ((array) data_get($response->tasks, '0.result.0.items', []) as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'organic' || count($results) >= 10) {
                continue;
            }
            $url = (string) ($item['url'] ?? '');
            $title = mb_substr((string) ($item['title'] ?? ''), 0, 200);
            $results[] = ['rank' => (int) ($item['rank_group'] ?? count($results) + 1), 'domain' => BrandSetupMatcher::host($url), 'url' => $url,
                'title' => $title, 'type' => self::classify($url, $title)];
        }
        $counts = array_count_values(array_column($results, 'type'));
        $decision = self::decide($counts);
        $this->store($cluster, $fingerprint, [
            'query' => $query, 'checked_at' => now()->toIso8601String(), 'counts' => $counts, 'results' => $results,
            'decision' => $decision, 'cost_usd' => $response->cost,
        ], $decision);

        return 'checked';
    }

    /** Page type of one organic result: hizmet | blog | sss | karsilastirma | rehber (directory). */
    public static function classify(string $url, string $title): string
    {
        $host = BrandSetupMatcher::host($url);
        $path = mb_strtolower((string) parse_url($url, PHP_URL_PATH));
        $folded = ' '.SeoText::fold($title).' ';
        foreach (self::FORUM_HOSTS as $forum) {
            if ($host === $forum || str_ends_with($host, '.'.$forum)) {
                return 'sss';
            }
        }
        foreach (self::DIRECTORY_HOSTS as $directory) {
            if ($host === $directory || str_ends_with($host, '.'.$directory)) {
                return 'rehber';
            }
        }
        if (preg_match('#/(soru|sorular|sss|faq|forum|questions?|cevap)(/|-|$)#', $path) === 1) {
            return 'sss';
        }
        foreach ([' vs ', ' mi ', ' mu ', ' farki ', ' farklari ', ' karsilastirma', ' hangisi ', ' en iyi '] as $marker) {
            if (str_contains($folded, $marker) && ! str_contains($folded, ' nedir ')) {
                return 'karsilastirma';
            }
        }
        if (preg_match('#/(blog|makale|makaleler|haber|haberler|yazi|yazilar|rehber|bilgi|saglik-rehberi|news|articles?)(/|$)#', $path) === 1
            || SeoText::looksLikeArticleTitle($title)
            || count(array_filter(explode('-', basename($path)))) >= 6) {
            return 'blog';
        }

        return 'hizmet';
    }

    /**
     * Majority of the top 10: directories count for the service page (the searcher wants a provider).
     *
     * @param  array<string, int>  $counts
     */
    public static function decide(array $counts): string
    {
        $score = [
            'hizmet' => ($counts['hizmet'] ?? 0) + ($counts['rehber'] ?? 0),
            'blog' => $counts['blog'] ?? 0,
            'sss' => $counts['sss'] ?? 0,
            'karsilastirma' => $counts['karsilastirma'] ?? 0,
        ];
        arsort($score);
        $top = array_key_first($score);

        return $score[$top] > 0 ? $top : 'hizmet';
    }

    /** @param  array<string, mixed>  $evidence */
    private function store(object $cluster, string $fingerprint, array $evidence, ?string $decision): void
    {
        $update = ['serp_evidence' => json_encode($evidence, JSON_UNESCAPED_UNICODE), 'research_query' => $evidence['query'] ?? $cluster->head_query,
            'research_fingerprint' => $fingerprint, 'researched_at' => now(), 'updated_at' => now()];
        if ($decision !== null && ($cluster->decision_source ?? null) !== 'manual') {
            $update += ['page_decision' => $decision, 'decision_source' => 'serp'];
        }
        DB::table('library_query_clusters')->where('id', $cluster->id)->update($update);
    }

    private function fingerprint(string $query): string
    {
        return hash('sha256', implode('|', [SeoText::fold($query), (int) config('moxdop-queries.serp.location_code', 2792), (string) config('moxdop-queries.serp.language_code', 'tr')]));
    }

    private function cacheDays(): int
    {
        return max(1, (int) config('moxdop-queries.serp.cache_days', 30));
    }

    /** @return list<int> */
    private function relevantServiceIds(): array
    {
        $assetIds = app(ServiceScope::class)->operationalAssetIds();
        $resourceIds = CoreAssetBinding::query()->whereIn('digital_asset_id', $assetIds ?: [0])->where('status', CoreAssetBinding::STATUS_ACTIVE)->pluck('external_resource_id')->all();

        return DB::table('search_query_library_item_service as p')
            ->whereExists(fn ($w) => $w->selectRaw('1')->from('query_variants as v')->whereColumn('v.search_query_library_item_id', 'p.search_query_library_item_id')
                ->where(fn ($s) => $s->whereIn('v.external_resource_id', $resourceIds ?: [0])->orWhereIn('v.digital_asset_id', $assetIds ?: [0])))
            ->distinct()->pluck('p.service_catalog_item_id')->map('intval')->all();
    }
}
