<?php

namespace App\Services\Website\UrlAudit;

use App\Jobs\RefreshUrlVerdictsJob;
use App\Models\CoreConnection;
use App\Models\DigitalAsset;
use App\Models\IntelligenceProjection\WebsitePageProfile;
use App\Models\Run;
use App\Models\SearchDemandImprovementRun;
use App\Models\SearchDemandPageOwnership;
use App\Models\SeoTask;
use App\Models\ServiceCatalogItem;
use App\Models\SiteFixItem;
use App\Models\User;
use App\Models\WebsiteUrlAudit;
use App\Models\WebsiteUrlVerdict;
use App\Services\Async\AsyncOperationService;
use App\Services\Compliance\ComplianceChecker;
use App\Services\Compliance\SectorPackRegistry;
use App\Services\Integrations\WordPress\WordPressConnectorPairingService;
use App\Services\Measurement\PageScorecardReader;
use App\Services\SeoTasks\BrandLocationWords;
use App\Services\SeoTasks\SeoPlanInputCollector;
use App\Services\SeoTasks\SeoStoredHtmlReader;
use App\Services\SeoTasks\SeoText;
use App\Services\SeoTasks\SiteUrlPattern;
use App\Services\Website\WebsiteAssessmentService;
use App\Support\ServiceScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MoxDop\Website\Standards\PageSignalExtractor;
use MoxDop\Website\Standards\UrlStandardEvaluator;
use MoxDop\Website\Standards\WebsiteStandardCatalog;
use Throwable;

/**
 * Faz 5 URL karnesi: ONE record per document URL of a website — every URL of the page inventory (crawl, sitemap,
 * WordPress), sitemap-only URLs and measured URLs (Search Console, GA4, Google Ads, SEO tasks) — joining crawl /
 * head facts, WordPress object, 28-day Google / GA4 / Ads metrics, inspection verdict, speed, stored standard
 * results, the url_* standards (Faz 6), open site fixes, open SEO tasks, cluster ownership and cannibalization.
 * Each URL gets exactly one primary verdict (UrlVerdictResolver) with a Turkish reason, solution and action.
 * Precomputed into website_url_verdicts; stored data only, no provider or AI call.
 */
final class UrlAuditService
{
    public const string OPERATION = 'website_url_verdicts';

    private const string UTILITY_PATHS = '#/(tesekkur|tesekkurler|thank-you|thanks|cart|sepet|checkout|odeme|my-account|hesabim|login|giris|wp-admin|feed|etiket|tag|author|yazar|gizlilik|gizlilik-politikasi|kvkk|kvkk-aydinlatma-metni|cerez-politikasi|cerez|privacy-policy|privacy|cookie-policy|cookies|kullanim-kosullari|terms|sitemap|arama|search)(/|$)#i';

    public function __construct(
        private readonly SeoPlanInputCollector $inputs,
        private readonly PageScorecardReader $scorecard,
        private readonly SeoStoredHtmlReader $html,
        private readonly WebsiteStandardCatalog $catalog,
        private readonly UrlVerdictResolver $resolver,
        private readonly ServiceScope $scope,
    ) {}

    /**
     * Manual "Yenile": queued, followed in Activity.
     *
     * @return array{ok: bool, queued: bool, message: string}
     */
    public function queue(DigitalAsset $site, ?User $actor = null): array
    {
        $audit = WebsiteUrlAudit::query()->firstOrNew(['digital_asset_id' => $site->id], ['brand_id' => $site->brand_id]);
        $result = app(AsyncOperationService::class)->queue($site, self::OPERATION, 'website', 'Sayfa Karnesi yenileme', $actor,
            function (Run $run) use ($site, $audit): RefreshUrlVerdictsJob {
                // Marked before dispatch: a synchronous worker overwrites it with running / completed.
                $audit->fill(['status' => 'queued', 'last_run_id' => $run->id])->save();

                return new RefreshUrlVerdictsJob($site->id, 'manual', $run->id);
            });

        return ['ok' => $result['ok'], 'queued' => $result['queued'], 'message' => $result['ok']
            ? ($result['queued'] ? 'Sayfa Karnesi yenileniyor; birkaç dakika içinde güncellenir. İlerleme Etkinlik ekranında.' : 'Yenileme zaten sürüyor.')
            : $result['message']];
    }

    /**
     * Automatic refresh (projection rebuilt, SEO plan finished, weekly, pilot chain). Only operational websites.
     * Debounced: the job is unique per website until it starts and waits a short while, so a projection rebuild,
     * an SEO plan and the weekly run arriving together queue ONE refresh, not a storm of them.
     */
    public static function dispatchFor(int $websiteId, string $trigger): bool
    {
        if (! app(ServiceScope::class)->isAssetOperational($websiteId)) {
            return false;
        }
        RefreshUrlVerdictsJob::dispatch($websiteId, $trigger)
            ->delay(now()->addSeconds(max(0, (int) config('moxdop-url-audit.debounce_seconds', 120))))
            ->afterCommit();

        return true;
    }

    public static function lockKey(int $websiteId): string
    {
        return 'website-url-verdicts:'.$websiteId;
    }

    /** @return array{status: 'completed'|'busy'|'not_served', urls: int, counts: array<string, int>} */
    public function refresh(DigitalAsset $site, string $trigger = 'manual'): array
    {
        if ($site->type !== 'website' || ! $this->scope->isAssetOperational($site->id)) {
            return ['status' => 'not_served', 'urls' => 0, 'counts' => []];
        }

        // Never wait for a lock: a refresh already running for this site answers "busy" and the caller decides
        // (the job re-runs once afterwards / retries a manual click later). Blocking waits were LockTimeoutException.
        $lock = Cache::lock(self::lockKey($site->id), 1200);
        if (! $lock->get()) {
            return ['status' => 'busy', 'urls' => 0, 'counts' => []];
        }
        try {
            $audit = WebsiteUrlAudit::query()->updateOrCreate(['digital_asset_id' => $site->id], ['brand_id' => $site->brand_id, 'status' => 'running', 'trigger' => $trigger]);
            try {
                $built = $this->build($site);
            } catch (Throwable $exception) {
                $audit->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 1000)]);

                throw $exception;
            }
            $this->store($site, $audit, $built, $trigger);

            return ['status' => 'completed', 'urls' => count($built['rows']), 'counts' => $built['counts']];
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{rows: list<array<string, mixed>>, counts: array<string, int>, site_checks: array<string, mixed>, sources: array<string, bool>, period: array<string, string>, groups: list<array<string, mixed>>}
     */
    public function build(DigitalAsset $site): array
    {
        $site->loadMissing('brand.customer');
        $now = CarbonImmutable::now();
        $end = $now->subDay()->startOfDay();
        $start = $end->subDays(27);
        $origin = SeoText::origin($site->primary_url ?: ('https://'.$site->domain));
        $host = preg_replace('/^www\./', '', mb_strtolower((string) parse_url($origin, PHP_URL_HOST))) ?? '';
        $homeKey = SeoText::urlKey($origin.'/');

        $inventory = $this->inputs->pages($site);
        $wordpress = $this->wordpressStates($site);
        $card = $this->scorecard->read($site, '', PHP_INT_MAX);
        $metrics = collect($card['rows'])->keyBy('key')->all();
        $traffic = $this->inputs->pageTraffic($site, $end)['pages'];
        $positions = $this->positions($site, $start, $end);
        $inspections = $this->inputs->inspections($site);
        $links = $this->inputs->internalLinks($site);
        $sitemap = $this->sitemapUrls($site, $host);
        $fixes = $this->fixItems($site, $origin);
        $tasks = $this->tasks($site);
        $targets = $this->clusterTargets($site);
        $cannibal = $this->cannibalizations($site);
        [$standardChecks, $standardSite, $standardRunAt] = $this->storedStandardResults($site);
        $offerings = $this->inputs->offerings($site);
        $modifiers = (array) config('moxdop-url-audit.doorway_modifiers', []);

        // Union of every URL we know about on this host (documents only).
        $urls = [];
        foreach ($inventory as $key => $page) {
            $urls[$key] = (string) $page['url'];
        }
        foreach ($sitemap['urls'] as $key => $url) {
            $urls[$key] ??= $url;
        }
        foreach ($metrics as $key => $row) {
            $urls[$key] ??= (string) $row['url'];
        }
        foreach ($fixes as $key => $items) {
            $urls[$key] ??= (string) $items[0]['url'];
        }
        $urls = array_filter($urls, fn (string $url, string $key): bool => $key !== ''
            && (str_starts_with($key, $host.'/') || $key === $host || str_starts_with($key, $host.'?'))
            && (isset($inventory[$key]) || SeoText::isDocumentUrl($url)), ARRAY_FILTER_USE_BOTH);

        $ymylPacks = $site->brand !== null ? array_map(fn ($pack): string => $pack->id(), app(SectorPackRegistry::class)->forBrand($site->brand)) : [];
        $wpById = [];
        foreach ($wordpress as $key => $wp) {
            if ($wp['id'] !== null) {
                $wpById[$wp['id']] = (string) ($wp['permalink'] ?? $urls[$key] ?? '');
            }
        }

        $records = [];
        foreach ($urls as $key => $url) {
            $page = $inventory[$key] ?? null;
            $wp = $wordpress[$key] ?? null;
            $metric = $metrics[$key] ?? null;
            $trafficRow = $traffic[$key] ?? null;
            $path = SeoText::urlPath($url);
            $canonicals = (array) ($page['canonical_hrefs'] ?? []);
            $canonicalKey = count($canonicals) === 1 ? SeoText::urlKey($this->absolute($origin, $url, (string) $canonicals[0])) : null;
            $status = $page['status_code'] ?? null;
            $noindex = ($page['head_observed'] ?? false) ? (bool) ($page['noindex'] ?? false) : null;
            $indexable = $status === null ? null : ($status === 200 && $noindex !== true && ($canonicalKey === null || $canonicalKey === $key));
            if ($status === 200 && $noindex === null) {
                $indexable = $canonicalKey === null || $canonicalKey === $key ? null : false;
            }
            $translations = [];
            foreach ((array) ($wp['translations'] ?? []) as $language => $value) {
                if (is_string($value) && str_starts_with($value, 'http')) {
                    $translations[(string) $language] = $value;
                } elseif (is_scalar($value) && isset($wpById[(string) $value]) && $wpById[(string) $value] !== '') {
                    $translations[(string) $language] = $wpById[(string) $value];
                }
            }
            $service = $this->serviceMatch($key, $path, $targets, $offerings, $modifiers);
            $records[$key] = [
                'key' => $key,
                'url' => $url,
                'path' => $path,
                'profile_id' => $page['profile_id'] ?? null,
                'sources' => array_keys(array_filter([
                    'crawl' => $page !== null && ($page['observed'] ?? false),
                    'inventory' => $page !== null,
                    'sitemap' => isset($sitemap['urls'][$key]),
                    'wordpress' => $wp !== null,
                    'search_console' => $trafficRow !== null,
                    'ga4' => ($metric['sessions'] ?? null) !== null,
                    'google_ads' => ($metric['ads_clicks'] ?? null) !== null,
                ])),
                'crawled' => $page !== null && ($page['observed'] ?? false),
                'head_observed' => (bool) ($page['head_observed'] ?? false),
                'status_code' => $status,
                'final_url' => $page['final_url'] ?? null,
                'redirect_count' => $page['redirect_count'] ?? null,
                'noindex' => $noindex,
                'robots' => $page['robots'] ?? null,
                'canonical' => $canonicals[0] ?? null,
                'canonical_key' => $canonicalKey,
                'indexable' => $indexable,
                'title' => $page['title'] ?? ($wp['title'] ?? null),
                'meta_description' => $page['meta_description'] ?? null,
                'h1' => $page['h1'] ?? null,
                'word_count' => $page['word_count'] ?? null,
                'structured_types' => $page['structured_types'] ?? [],
                'cms_type' => $wp['type'] ?? ($page['cms_type'] ?? null),
                'cms_status' => $wp['status'] ?? ($page['cms_status'] ?? null),
                'modified_at' => $wp['modified_at'] ?? null,
                'published_at' => $wp['published_at'] ?? null,
                'wp_language' => $wp['language'] ?? null,
                'wp_translations' => $translations,
                'in_sitemap' => $sitemap['known'] ? isset($sitemap['urls'][$key]) : null,
                'inlinks' => $links['available'] ? count($links['inlinks'][$key] ?? []) : null,
                'inlink_sample' => array_slice($links['inlinks'][$key] ?? [], 0, 5),
                'clicks' => $metric['clicks'] ?? null,
                'clicks_prev' => $metric['clicks_prev'] ?? null,
                'impressions' => $metric['impressions'] ?? null,
                'impr_90' => $trafficRow['impr_90'] ?? null,
                'position' => $positions[$key]['position'] ?? null,
                'top_queries' => $positions[$key]['queries'] ?? [],
                'sessions' => $metric['sessions'] ?? null,
                'key_events' => $metric['key_events'] ?? null,
                'channels' => $metric['channels'] ?? [],
                'ads_clicks' => $metric['ads_clicks'] ?? null,
                'ads_cost' => $metric['ads_cost'] ?? null,
                'ads_conversions' => $metric['ads_conversions'] ?? null,
                'lcp_ms' => $metric['lcp_ms'] ?? null,
                'inspection' => $inspections[$key] ?? null,
                'is_service' => $service !== null,
                'is_priority_service' => (bool) ($service['priority'] ?? false),
                'service' => $service['label'] ?? null,
                'targets' => $targets[$key] ?? [],
                'cannibal' => $cannibal['pages'][$key] ?? null,
                'fixes' => $fixes[$key] ?? [],
                'tasks' => $tasks[$key] ?? [],
                'stored_checks' => $standardChecks[$key] ?? [],
                'signals' => null,
            ];
            $records[$key]['kind'] = $this->kind($records[$key], $homeKey);
        }

        $this->readSignals($site, $records, $homeKey, in_array('health', $ymylPacks, true));

        $siteContext = [
            'robots' => $this->inputs->robots($site),
            'wordpress' => $this->connectorState($site),
            'ai_referrals' => $this->aiReferrals($site, $end),
            'service_similarity' => (float) config('moxdop-url-audit.service_similarity', 0.6),
            'home_key' => $homeKey,
            'ymyl' => $ymylPacks !== [],
            'health' => in_array('health', $ymylPacks, true),
            'link_graph' => $links['available'],
            'sitemap_known' => $sitemap['known'],
            'cannibalization_known' => $cannibal['known'],
            'gbp' => $this->inputs->businessProfile($site),
            'locations' => $this->locationWords($site),
            'modifiers' => $modifiers,
            'now' => $now->getTimestamp(),
            'thin_words' => (int) config('moxdop-url-audit.thin_words', 300),
            'decay_months' => (int) config('moxdop-url-audit.decay_months', 12),
            'decay_min_prev_clicks' => (int) config('moxdop-url-audit.decay_min_prev_clicks', 10),
            'decay_ratio' => (float) config('moxdop-url-audit.decay_ratio', 0.7),
            'service_min_inlinks' => (int) config('moxdop-url-audit.service_min_inlinks', 3),
            'doorway_min_group' => (int) config('moxdop-url-audit.doorway_min_group', 3),
            'title_similarity' => (float) config('moxdop-url-audit.title_similarity', 0.88),
        ];
        $standards = array_filter($this->catalog->all(true), fn (array $standard): bool => str_starts_with((string) $standard['method'], 'url_'));
        $evaluated = (new UrlStandardEvaluator)->evaluate($standards, $records, $siteContext);

        $context = [
            'site_id' => $site->id, 'home_key' => $homeKey, 'standards' => $standards, 'groups' => $evaluated['groups'],
            'gsc_available' => $card['sources']['search_console'], 'ga4_available' => $card['sources']['ga4'],
            'junk_pattern' => (string) config('moxdop-url-audit.junk_path_pattern'), 'utility_pattern' => self::UTILITY_PATHS,
            'strengthen_positions' => (array) config('moxdop-url-audit.strengthen_positions', [5, 20]),
            'strengthen_min_impressions' => (int) config('moxdop-url-audit.strengthen_min_impressions', 30),
        ];
        $rows = [];
        $counts = array_fill_keys(array_keys(WebsiteUrlVerdict::VERDICTS), 0);
        foreach ($records as $key => $record) {
            $verdict = $this->resolver->resolve($record, $evaluated['pages'][$key] ?? [], $context);
            $counts[$verdict['verdict']]++;
            $rows[] = ['record' => $record, 'verdict' => $verdict];
        }

        $siteChecks = [];
        foreach ($evaluated['site'] as $id => $check) {
            $siteChecks[$id] = $check + ['title' => $standards[$id]['title'], 'severity' => $standards[$id]['severity'], 'source' => 'url_standard'];
        }
        foreach ($standardSite as $id => $check) {
            $siteChecks[$id] = $check;
        }

        return [
            'rows' => $rows,
            'counts' => $counts,
            'site_checks' => $siteChecks,
            'sources' => $card['sources'] + [
                'inventory' => $inventory !== [], 'sitemap' => $sitemap['known'], 'wordpress' => $wordpress !== [],
                'links' => $links['available'], 'standards_run' => $standardRunAt !== null, 'cannibalization' => $cannibal['known'],
                'html' => collect($records)->contains(fn (array $r): bool => $r['signals'] !== null),
            ],
            'period' => $card['period'] + ['standards_run_at' => (string) $standardRunAt],
            'groups' => array_map(fn (array $group): array => $group + ['paths' => array_map(fn (string $m): string => (string) ($records[$m]['path'] ?? $m), $group['members'])], $evaluated['groups']),
        ];
    }

    /** @param  array<string, mixed>  $built */
    private function store(DigitalAsset $site, WebsiteUrlAudit $audit, array $built, string $trigger): void
    {
        $now = now();
        DB::transaction(function () use ($site, $audit, $built, $trigger, $now): void {
            WebsiteUrlVerdict::query()->where('digital_asset_id', $site->id)->delete();
            foreach (array_chunk($built['rows'], 200) as $chunk) {
                $insert = [];
                foreach ($chunk as ['record' => $record, 'verdict' => $verdict]) {
                    $insert[] = [
                        'digital_asset_id' => $site->id, 'brand_id' => $site->brand_id,
                        'url_hash' => hash('sha256', $record['key']), 'url_key' => $record['key'],
                        'url' => mb_substr((string) $record['url'], 0, 2000), 'path' => mb_substr((string) $record['path'], 0, 2000),
                        'verdict' => $verdict['verdict'], 'severity' => $verdict['severity'], 'priority' => $verdict['priority'],
                        'reason' => $verdict['reason'], 'solution' => $verdict['solution'],
                        'finding_count' => count(array_filter($verdict['findings'], fn (array $f): bool => in_array($f['state'], ['fail', 'review'], true))),
                        'clicks' => $record['clicks'], 'clicks_prev' => $record['clicks_prev'], 'impressions' => $record['impressions'],
                        'position' => $record['position'], 'sessions' => $record['sessions'], 'key_events' => $record['key_events'],
                        'ads_clicks' => $record['ads_clicks'], 'ads_cost' => $record['ads_cost'], 'ads_conversions' => $record['ads_conversions'],
                        'indexed' => ($record['inspection']['verdict'] ?? null) === null ? null : $record['inspection']['verdict'] === 'PASS',
                        'lcp_ms' => $record['lcp_ms'], 'status_code' => $record['status_code'], 'word_count' => $record['word_count'],
                        'group_key' => $verdict['group_key'],
                        'findings' => json_encode($verdict['findings'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                        'facts' => json_encode($this->facts($record, $verdict), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                        'computed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                    ];
                }
                WebsiteUrlVerdict::query()->insert($insert);
            }
            $audit->update([
                'status' => 'completed', 'counts' => $built['counts'], 'site_checks' => $built['site_checks'], 'sources' => $built['sources'],
                'period' => $built['period'], 'groups' => $built['groups'], 'url_count' => count($built['rows']), 'trigger' => $trigger,
                'error' => null, 'computed_at' => $now,
            ]);
        });
    }

    /**
     * Joined facts shown in the detail drawer (signals trimmed to what the operator reads).
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $verdict
     * @return array<string, mixed>
     */
    private function facts(array $record, array $verdict): array
    {
        $signals = $record['signals'];
        $facts = collect($record)->except(['signals', 'fixes', 'tasks', 'stored_checks', 'profile_id'])->all();
        $facts['signals'] = $signals === null ? null : collect($signals)->only([
            'lang', 'hreflang', 'jsonld_types', 'date_modified', 'author', 'medical_review', 'visible_date', 'faq_content', 'question_count', 'phones',
            'main_words', 'images', 'spam_terms', 'promotion_hits',
        ])->all();
        $facts['checked'] = $verdict['checked'];
        $facts['missing'] = $verdict['missing'];
        $facts['group'] = $verdict['group'];

        return $facts;
    }

    /** @return array<string, array<string, mixed>> url key => WordPress object */
    private function wordpressStates(DigitalAsset $site): array
    {
        $out = [];
        WebsitePageProfile::query()->where('website_asset_id', $site->id)->orderBy('id')->select(['id', 'preferred_url', 'source_states'])
            ->chunk(500, function ($profiles) use (&$out): void {
                foreach ($profiles as $profile) {
                    $object = data_get($profile->source_states, 'wordpress.object');
                    if (! is_array($object)) {
                        continue;
                    }
                    $url = (string) (data_get($profile->source_states, 'website.url') ?? $profile->preferred_url);
                    $out[SeoText::urlKey($url)] = [
                        'id' => isset($object['id']) ? (string) $object['id'] : null,
                        'type' => $object['type'] ?? null, 'status' => $object['status'] ?? null, 'title' => $object['title'] ?? null,
                        'permalink' => $object['permalink'] ?? null, 'modified_at' => $object['modified_at'] ?? null,
                        'published_at' => $object['published_at'] ?? null, 'language' => $object['language'] ?? data_get($profile->source_states, 'wordpress.seo.language'),
                        'translations' => is_array($object['translations'] ?? null) ? $object['translations'] : [],
                    ];
                }
            });

        return $out;
    }

    /** @return array<string, array{position: ?float, queries: list<string>}> */
    private function positions(DigitalAsset $site, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $out = [];
        foreach ($this->inputs->gsc($site, $start, $end)['rows'] as $row) {
            $entry = $out[$row['url_key']] ?? ['sum' => 0.0, 'weight' => 0, 'queries' => []];
            if ($row['position'] !== null) {
                $entry['sum'] += $row['position'] * $row['impressions'];
                $entry['weight'] += $row['impressions'];
            }
            if (count($entry['queries']) < 5) {
                $entry['queries'][] = (string) $row['query'];
            }
            $out[$row['url_key']] = $entry;
        }

        return array_map(fn (array $e): array => ['position' => $e['weight'] > 0 ? round($e['sum'] / $e['weight'], 1) : null, 'queries' => $e['queries']], $out);
    }

    /** @return array{known: bool, urls: array<string, string>} */
    private function sitemapUrls(DigitalAsset $site, string $host): array
    {
        $urls = [];
        $known = false;
        if (Schema::hasTable('website_sitemap_watch')) {
            $pages = DB::table('website_sitemap_watch')->where('digital_asset_id', $site->id)->value('pages');
            $decoded = is_string($pages) ? (json_decode($pages, true) ?: []) : [];
            foreach (array_keys($decoded) as $url) {
                $urls[SeoText::urlKey((string) $url)] = (string) $url;
            }
            $known = $decoded !== [];
        }
        if (Schema::hasTable('website_url')) {
            DB::table('website_url')->where('digital_asset_id', $site->id)->where('metadata->source', 'sitemap')->limit(20000)->pluck('normalized_url')
                ->each(function ($url) use (&$urls, &$known): void {
                    $urls[SeoText::urlKey((string) $url)] = (string) $url;
                    $known = true;
                });
        }
        $urls = array_filter($urls, fn (string $url, string $key): bool => str_starts_with($key.'/', $host.'/') && SeoText::isCrawlablePage($url), ARRAY_FILTER_USE_BOTH);

        return ['known' => $known, 'urls' => $urls];
    }

    /** @return array<string, list<array<string, mixed>>> url key => fix items (open, failed, queued) */
    private function fixItems(DigitalAsset $site, string $origin): array
    {
        $out = [];
        SiteFixItem::query()->where('digital_asset_id', $site->id)->whereIn('status', ['open', 'failed', 'queued'])->whereNotNull('url')
            ->where('type', '!=', 'new_page')->orderBy('id')->limit(5000)->get()
            ->each(function (SiteFixItem $item) use (&$out, $origin): void {
                $url = str_starts_with((string) $item->url, '/') ? $origin.$item->url : (string) $item->url;
                $out[SeoText::urlKey($url)][] = ['id' => $item->id, 'type' => $item->type, 'label' => $item->typeLabel(), 'status' => $item->status,
                    'reason' => (string) $item->reason, 'phase' => (int) (SiteFixItem::TYPES[$item->type][1] ?? 1), 'url' => $url];
            });

        return $out;
    }

    /** @return array<string, list<array{id: int, title: string, type: string, severity: ?string}>> */
    private function tasks(DigitalAsset $site): array
    {
        $out = [];
        SeoTask::query()->where('digital_asset_id', $site->id)->where('status', 'open')->whereNotNull('target_url')
            ->orderByDesc('priority_score')->limit(2000)->get(['id', 'target_url', 'title', 'type', 'severity'])
            ->each(function (SeoTask $task) use (&$out): void {
                $out[SeoText::urlKey((string) $task->target_url)][] = ['id' => $task->id, 'title' => (string) $task->title,
                    'type' => $task->type instanceof \BackedEnum ? (string) $task->type->value : (string) $task->type, 'severity' => is_string($task->severity) ? $task->severity : null];
            });

        return $out;
    }

    /** @return array<string, list<string>> url key => owned cluster / service labels */
    private function clusterTargets(DigitalAsset $site): array
    {
        $out = [];
        if (Schema::hasTable('library_cluster_targets')) {
            $rows = DB::table('library_cluster_targets as t')->leftJoin('library_query_clusters as c', 'c.id', '=', 't.cluster_key')
                ->where('t.digital_asset_id', $site->id)->limit(2000)->get(['t.url', 't.service_id', 't.cluster_key', 'c.name']);
            $services = ServiceCatalogItem::query()->with('primaryName')->whereIn('id', $rows->pluck('service_id')->unique()->all())->get()->keyBy('id');
            foreach ($rows as $row) {
                $service = (string) ($services->get($row->service_id)?->primaryName?->raw_label ?? 'Hizmet #'.$row->service_id);
                $out[SeoText::urlKey((string) $row->url)][] = $row->name !== null ? $service.' › '.$row->name : $service;
            }
        }
        SearchDemandPageOwnership::query()->where('digital_asset_id', $site->id)->where('status', 'verified_owner')->with('cluster:id,name')
            ->limit(2000)->get()->each(function (SearchDemandPageOwnership $owner) use (&$out): void {
                if (filled($owner->target_url) && $owner->cluster !== null) {
                    $out[SeoText::urlKey((string) $owner->target_url)][] = (string) $owner->cluster->name;
                }
            });

        return array_map(fn (array $labels): array => array_values(array_unique($labels)), $out);
    }

    /** @return array{known: bool, pages: array<string, array<string, mixed>>} */
    private function cannibalizations(DigitalAsset $site): array
    {
        if (! Schema::hasTable('brain_cannibalizations')) {
            return ['known' => false, 'pages' => []];
        }
        $rows = DB::table('brain_cannibalizations')->where('digital_asset_id', $site->id)->get(['subject', 'pages', 'status']);
        $pages = [];
        foreach ($rows->where('status', 'open') as $row) {
            $members = is_string($row->pages) ? (json_decode($row->pages, true) ?: []) : [];
            if (count($members) < 2) {
                continue;
            }
            $keeper = SeoText::urlKey((string) $members[0]['url']);
            foreach ($members as $member) {
                $key = SeoText::urlKey((string) ($member['url'] ?? ''));
                $pages[$key] ??= ['subject' => (string) $row->subject, 'keeper' => $keeper, 'share' => $member['share'] ?? null,
                    'pages' => array_map(fn (array $m): array => ['url' => (string) $m['url'], 'share' => $m['share'] ?? null, 'position' => $m['position'] ?? null], $members)];
            }
        }
        $known = $rows->isNotEmpty() || DB::table('brain_cannibalizations')->where('digital_asset_id', $site->id)->exists()
            || DB::table('brand_offerings')->where('brand_id', $site->brand_id)->where('status', 'active')->exists();

        return ['known' => $known, 'pages' => $pages];
    }

    /**
     * Latest stored standards assessment (Website › Standartlar): page-level fail/review results by URL and site checks.
     *
     * @return array{0: array<string, list<array<string, mixed>>>, 1: array<string, array<string, mixed>>, 2: ?string}
     */
    private function storedStandardResults(DigitalAsset $site): array
    {
        $run = SearchDemandImprovementRun::query()->where('digital_asset_id', $site->id)->where('route_key', WebsiteAssessmentService::MODE)
            ->whereIn('status', ['completed', 'partial'])->latest('id')->first();
        if ($run !== null && ($cached = data_get($run->response_payload, 'cached_run_id')) !== null) {
            $run = SearchDemandImprovementRun::query()->find($cached) ?? $run;
        }
        if ($run === null) {
            return [[], [], null];
        }
        $standards = (array) data_get($run->input_payload, 'standards', []);
        $pages = [];
        foreach ((array) data_get($run->response_payload, 'pages', []) as $page) {
            foreach ((array) ($page['checks'] ?? []) as $id => $check) {
                if (! in_array($check['state'] ?? null, ['fail', 'review'], true) || ! isset($standards[$id])) {
                    continue;
                }
                $pages[SeoText::urlKey((string) $page['url'])][] = [
                    'id' => $id, 'title' => (string) $standards[$id]['title'], 'state' => $check['state'], 'severity' => (string) $standards[$id]['severity'],
                    'criterion' => (string) ($standards[$id]['criterion'] ?? ''), 'action' => (string) $standards[$id]['action'],
                    'observed' => is_scalar($check['observed'] ?? null) ? (string) $check['observed'] : null,
                ];
            }
        }
        $siteChecks = [];
        foreach ((array) data_get($run->response_payload, 'site_checks', []) as $id => $check) {
            if (isset($standards[$id])) {
                $siteChecks[$id] = ['state' => $check['state'], 'finding' => in_array($check['state'], ['fail', 'review'], true) ? (string) ($standards[$id]['criterion'] ?? $check['reason']) : (string) $check['reason'],
                    'solution' => in_array($check['state'], ['fail', 'review'], true) ? (string) $standards[$id]['action'] : null,
                    'title' => (string) $standards[$id]['title'], 'severity' => (string) $standards[$id]['severity'], 'source' => 'stored_standard'];
            }
        }

        return [$pages, $siteChecks, $run->completed_at?->toIso8601String() ?? $run->updated_at?->toIso8601String()];
    }

    /**
     * The brand's service the URL serves: an explicit cluster target, or the slug naming an active offering.
     *
     * @param  array<string, list<string>>  $targets
     * @param  list<array<string, mixed>>  $offerings
     * @param  list<string>  $modifiers
     * @return array{label: string, priority: bool}|null
     */
    private function serviceMatch(string $key, string $path, array $targets, array $offerings, array $modifiers): ?array
    {
        if (isset($targets[$key])) {
            return ['label' => $targets[$key][0], 'priority' => true];
        }
        $slug = SeoText::fold(str_replace(['-', '_', '/'], ' ', urldecode($path)));
        if ($slug === '') {
            return null;
        }
        $stems = array_map(fn (string $w): string => mb_substr($w, 0, 5), array_filter(explode(' ', $slug)));
        foreach ($offerings as $offering) {
            foreach ((array) $offering['names'] ?: [(string) $offering['name']] as $name) {
                $words = array_values(array_filter(SeoText::tokens((string) $name), fn (string $w): bool => mb_strlen($w) >= 3 && ! in_array($w, $modifiers, true)));
                if ($words !== [] && array_diff(array_map(fn (string $w): string => mb_substr($w, 0, 5), $words), $stems) === []) {
                    return ['label' => (string) $offering['name'], 'priority' => (bool) $offering['is_priority']];
                }
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $record */
    private function kind(array $record, string $homeKey): string
    {
        $path = mb_strtolower((string) $record['path']);
        if ($record['key'] === $homeKey) {
            return 'home';
        }
        if (preg_match(self::UTILITY_PATHS, $path) === 1) {
            return 'utility';
        }
        if (preg_match('#/(iletisim|contact|bize-ulasin|ulasim)(/|$)#', $path) === 1) {
            return 'contact';
        }
        if (preg_match('#/(hakkimizda|hakkinda|about|kurumsal|biz-kimiz|hakkimda)(/|$)#', $path) === 1) {
            return 'about';
        }
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        if ($record['cms_type'] === 'post' || (count($segments) >= 2 && in_array($segments[0], SiteUrlPattern::POST_SECTIONS, true))) {
            return 'post';
        }
        if ($record['is_service'] || (count($segments) >= 2 && in_array($segments[0], SiteUrlPattern::SERVICE_SECTIONS, true))) {
            return 'service';
        }

        return 'page';
    }

    /**
     * Stored HTML of the most important pages (home, services, traffic) parsed into E-E-A-T / schema / hreflang signals.
     *
     * @param  array<string, array<string, mixed>>  $records
     */
    private function readSignals(DigitalAsset $site, array &$records, string $homeKey, bool $health = false): void
    {
        // Sağlık tanıtım yönetmeliği: the health pack's website rules run on each read page's visible text.
        $rules = $health && $site->brand !== null
            ? app(SectorPackRegistry::class)->rulesForBrand($site->brand)->where('pack_id', 'health')->values()
            : collect();
        $checker = new ComplianceChecker;
        $candidates = array_filter($records, fn (array $r): bool => $r['profile_id'] !== null && $r['crawled'] && ($r['status_code'] === null || $r['status_code'] === 200));
        uasort($candidates, fn (array $a, array $b): int => [(int) ($b['key'] === $homeKey), (int) $b['is_service'], (int) ($b['clicks'] ?? 0), (int) ($b['impressions'] ?? 0), (int) ($b['kind'] === 'contact')]
            <=> [(int) ($a['key'] === $homeKey), (int) $a['is_service'], (int) ($a['clicks'] ?? 0), (int) ($a['impressions'] ?? 0), (int) ($a['kind'] === 'contact')]);
        $selected = array_slice($candidates, 0, max(0, (int) config('moxdop-url-audit.html_pages', 400)), true);
        $extractor = new PageSignalExtractor;
        foreach (array_chunk(array_keys($selected), 100) as $chunk) {
            $profiles = WebsitePageProfile::query()->whereIn('id', array_map(fn (string $key): int => (int) $records[$key]['profile_id'], $chunk))->get()->keyBy('id');
            foreach ($chunk as $key) {
                $profile = $profiles->get((int) $records[$key]['profile_id']);
                $html = $profile !== null ? $this->html->html($site, $profile) : null;
                if ($html === null) {
                    continue;
                }
                try {
                    $records[$key]['signals'] = $extractor->extract($records[$key]['url'], $html);
                } catch (Throwable) {
                    $records[$key]['signals'] = null;
                }
                if (is_array($records[$key]['signals'])) {
                    if ($health) {
                        $records[$key]['signals']['promotion_hits'] = array_map(fn (array $hit): array => ['label' => (string) $hit['rule']->label, 'matched' => $hit['matched']],
                            $rules->isEmpty() ? [] : $checker->checkText((string) $records[$key]['signals']['text_folded'], $rules, 'website'));
                    }
                    unset($records[$key]['signals']['text_folded'], $records[$key]['signals']['internal_urls']);
                }
            }
        }
    }

    /**
     * WordPress Connector pairing, version and the capabilities its last signed status reported (IndexNow).
     *
     * @return array{paired: bool, plugin_version: ?string, capabilities: ?list<string>}|null
     */
    private function connectorState(DigitalAsset $site): ?array
    {
        $connection = CoreConnection::query()->where('digital_asset_id', $site->id)->where('type', WordPressConnectorPairingService::CONNECTION_TYPE)->first();
        if ($connection === null) {
            return null;
        }
        $capabilities = data_get($connection->config, 'capabilities');

        return [
            'paired' => $connection->enabled && data_get($connection->config, 'pairing_state') === WordPressConnectorPairingService::PAIRED,
            'plugin_version' => is_string(data_get($connection->config, 'plugin_version')) ? (string) data_get($connection->config, 'plugin_version') : null,
            'capabilities' => is_array($capabilities) ? array_values(array_filter($capabilities, 'is_string')) : null,
        ];
    }

    /**
     * GA4 sessions of the last 90 days from AI answer engines (session source), for the informational standard.
     *
     * @return array{available: bool, sources: array<string, int>}
     */
    private function aiReferrals(DigitalAsset $site, CarbonImmutable $end): array
    {
        if (! Schema::hasTable('ga4_source_medium_daily')) {
            return ['available' => false, 'sources' => []];
        }
        $query = $this->inputs->scopeGa4(DB::table('ga4_source_medium_daily'), $site)->whereBetween('reporting_date', [$end->subDays(89)->toDateString(), $end->toDateString()]);
        if (! (clone $query)->exists()) {
            return ['available' => false, 'sources' => []];
        }
        $source = $query->getGrammar()->wrap('sessionSource');
        $sources = [];
        foreach ($query->selectRaw($source.' as source, sum(sessions) as sessions')->groupBy('sessionSource')->get() as $row) {
            $name = mb_strtolower(trim((string) $row->source));
            foreach ((array) config('moxdop-url-audit.ai_referrers', []) as $label => $pattern) {
                if (preg_match((string) $pattern, $name) === 1) {
                    $sources[(string) $label] = ($sources[(string) $label] ?? 0) + (int) $row->sessions;
                }
            }
        }

        return ['available' => true, 'sources' => $sources];
    }

    /** @return list<string> folded provinces, the brand's service areas and their districts */
    private function locationWords(DigitalAsset $site): array
    {
        return BrandLocationWords::for($site->brand);
    }

    private function absolute(string $origin, string $url, string $href): string
    {
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }
        if (str_starts_with($href, '//')) {
            return 'https:'.$href;
        }
        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }

        return rtrim($url, '/').'/'.$href;
    }
}
