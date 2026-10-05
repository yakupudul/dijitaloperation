<?php

namespace App\Services\BrandSetup;

use App\Ai\Agents\BrandSetupAgent;
use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use App\Models\DiscoveryCandidate;
use App\Models\IntelligenceProjection\WebsitePageProfile;
use App\Models\Page;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCatalogName;
use App\Models\ServiceCategory;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Brand\BrandGaps;
use App\Services\Catalog\ServiceKeywordService;
use App\Services\Portfolio\UnassignedWebsites;
use App\Services\SeoTasks\SeoStoredHtmlReader;
use App\Services\SeoTasks\SeoText;
use App\Services\Site\Analysis\SitePagesReader;
use App\Services\Site\PageCategorizer;
use App\Services\Site\SiteText;
use App\Support\Ai\AiRouteKeys;
use App\Support\BrandIntelligence\IdentityLabelNormalizer;
use App\Support\Options\LocationOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Service part of "Otomatik kur": one AI call over the site's stored pages, Search Console queries and
 * crawl candidates, then a deterministic check against the service catalog so nothing is duplicated.
 * Service names, aliases and keywords are kept location-free (reusable for brands elsewhere); the
 * locations Search Console queries mention are reported against the brand's service areas instead.
 */
final class BrandSetupServiceSuggester
{
    /** Folded page titles that are never a service (used when AI is unavailable). */
    /** Non-service pages sent to the AI next to every service page (service pages are never cut). */
    private const int MAX_OTHER_PAGES = 200;

    private const NON_SERVICE_PAGE = '/^(ana ?sayfa|home|hakkimizda|hakkinda|iletisim|blog|sss|sikca sorulan|galeri|ekibimiz|ekip|kariyer|kvkk|gizlilik|cerez|randevu|tesekkur|fiyat|referans|basinda|haber|sepet|hesabim|odeme)/u';

    public function __construct(
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
        private readonly IdentityLabelNormalizer $normalizer,
        private readonly SeoStoredHtmlReader $html,
        private readonly AiTaskQueue $tasks,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $items  matcher output (to find the chosen Search Console property)
     *                                             Delegated to Claude (MCP queue): status "queued" while the answer is awaited (the job runs again with it).
     * @return array{status: string, services: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function suggest(Brand $brand, string $websiteUrl, array $items): array
    {
        $host = BrandSetupMatcher::host($websiteUrl);
        $website = $brand->digitalAssets()->where('type', 'website')->get()
            ->first(fn (DigitalAsset $asset): bool => BrandSetupMatcher::assetSiteKey($asset) === BrandSetupMatcher::siteKey($websiteUrl));
        // A site added under Integrations (no brand yet) may already carry WordPress pages.
        $website ??= ($unassigned = app(UnassignedWebsites::class)->findByHost($websiteUrl)) !== null && $unassigned->brand_id === null ? $unassigned : null;
        $pages = $website !== null ? $this->pages($website) : [];
        $wordpressPages = $website !== null ? $this->wordpressPages($website) : [];
        $queries = $this->queries($items);
        $areas = $brand->serviceAreas()->where('status', 'active')->get(['country_code', 'city_name', 'district_name'])->map(fn ($area): array => $area->only(['country_code', 'city_name', 'district_name']))->all();
        $candidates = $website !== null ? $this->crawlCandidates($website) : [];

        if ($pages === [] && $wordpressPages === [] && $queries === [] && $candidates === []) {
            return ['status' => 'waiting_for_site', 'services' => [], 'summary' => ['reason' => 'no_site_data']];
        }

        $catalog = $this->catalog($brand);
        $sectors = ServiceCategory::options();
        $existing = $this->existingOfferingKeys($brand);

        $context = [
            'brand' => ['name' => $brand->name, 'domain' => $host],
            'brand_service_areas' => array_map(static fn (array $a): string => implode(', ', array_filter([$a['district_name'], $a['city_name'], $a['country_code']])), $areas),
            'wordpress_pages' => $wordpressPages,
            'pages' => $pages,
            'search_console_queries' => array_slice($queries, 0, 150),
            'crawl_service_candidates' => $candidates,
            'CATALOG' => array_map(static fn (array $c): array => ['name' => $c['name'], 'sector_code' => $c['sector']], $catalog),
            'SECTORS' => collect($sectors)->map(fn ($name, $code): array => ['code' => $code, 'name' => $name])->values()->all(),
        ];
        $structured = null;
        $summary = [];
        $delegated = $this->tasks->delegatedCall(new BrandSetupAgent, $context, 'setup', 'CONTEXT_JSON');
        if ($delegated === 'queued') {
            return ['status' => 'queued', 'services' => [], 'summary' => ['waiting' => 'claude']];
        }
        try {
            $route = $delegated === null ? $this->routes->resolve(AiRouteKeys::BRAND_SETUP) : null;
            if (is_array($delegated)) {
                $structured = $delegated;
                $summary = ['provider' => AiTaskQueue::PROVIDER, 'model' => AiTaskQueue::MODEL];
            } elseif ($delegated === 'error') {
                $summary = ['ai_skipped_reason' => 'llm_error', 'ai_error' => 'Claude bu öneriyi hazırlayamadı.'];
            } elseif (! $route->isEmpty()) {
                $this->runtime->prepare(array_keys($route->providerModels));
                $response = (new BrandSetupAgent)->prompt(
                    "CONTEXT_JSON\n".json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    provider: $route->providerModels,
                    timeout: 200,
                );
                $structured = $response->toArray();
                $summary = ['provider' => $route->primaryProvider(), 'model' => $route->primaryModel()];
            } else {
                $summary = ['ai_skipped_reason' => 'no_eligible_provider'];
            }
        } catch (Throwable $exception) {
            Log::warning('Brand setup AI call failed.', ['brand_id' => $brand->id, 'error' => $exception->getMessage()]);
            $summary = ['ai_skipped_reason' => 'llm_error', 'ai_error' => mb_substr($exception::class.': '.$exception->getMessage(), 0, 400)];
        }

        if (! is_array($structured)) {
            // Without AI only the crawl's own service candidates are proposed, all unticked.
            $services = array_map(fn (string $name): array => $this->serviceRow($name, [], null, false, 'Site taramasında bulunan hizmet başlığı', $catalog, $sectors, $existing, 0.5), $candidates);
            foreach ($wordpressPages as $page) {
                if (preg_match(self::NON_SERVICE_PAGE, SeoText::fold($page['title'])) !== 1 && $page['path'] !== '/') {
                    $services[] = $this->serviceRow($page['title'], [], null, false, 'WordPress sayfası', $catalog, $sectors, $existing, 0.5);
                }
            }
            $services = $this->withKeywords($this->dedupe($services), $queries, $brand, $host);

            return ['status' => $services === [] ? 'ai_unavailable' : 'ready', 'services' => $services, 'summary' => $summary + ['locations' => $this->locationReport($queries, $areas)]];
        }

        $catalogNames = array_column($catalog, 'name');
        $services = [];
        // No count limit: every service the data shows. Nothing invented: a service with no trace in the site pages,
        // WordPress pages, crawl candidates or Search Console queries stays unticked and is marked "kanıt yok".
        $evidence = $this->evidenceTexts($pages, $wordpressPages, $candidates, $queries);
        foreach (is_array($structured['services'] ?? null) ? $structured['services'] : [] as $row) {
            if (! is_array($row) || ! is_string($row['name'] ?? null) || mb_strlen(trim($row['name'])) < 2 || mb_strlen($row['name']) > 80) {
                continue;
            }
            $catalogName = is_string($row['catalog_name'] ?? null) && in_array($row['catalog_name'], $catalogNames, true) ? $row['catalog_name'] : null;
            $sector = is_string($row['sector_code'] ?? null) && isset($sectors[$row['sector_code']]) ? $row['sector_code'] : null;
            $aliases = array_values(array_filter((array) ($row['aliases'] ?? []), static fn ($a): bool => is_string($a) && mb_strlen(trim($a)) >= 2 && mb_strlen($a) <= 80));
            $phrases = array_values(array_filter((array) ($row['matching_phrases'] ?? []), 'is_string'));
            $grounded = self::grounded(array_merge([trim($row['name'])], $aliases, $phrases, $catalogName !== null ? [$catalogName] : []), $evidence)
                || isset($existing[$this->normalizer->normalize($catalogName ?? trim($row['name']))]);
            $services[] = $this->serviceRow($catalogName ?? trim($row['name']), array_slice($aliases, 0, 4), $sector, (bool) ($row['is_core'] ?? false),
                ($grounded ? '' : 'Kanıt yok — sitede ve sorgularda karşılığı bulunamadı; elle kontrol edin. ').mb_substr((string) ($row['evidence'] ?? ''), 0, 200),
                $catalog, $sectors, $existing, $grounded ? 0.85 : 0.5, (array) ($row['matching_phrases'] ?? []));
        }
        $brandSector = is_string($structured['sector_code'] ?? null) && isset($sectors[$structured['sector_code']]) ? $structured['sector_code'] : null;
        foreach ($this->uncoveredServicePages($website, $services) as [$title, $path]) {
            $services[] = $this->serviceRow($title, [], $brandSector, false, 'Sitede hizmet sayfası var: '.$path, $catalog, $sectors, $existing, 0.8);
        }

        return [
            'status' => 'ready',
            'services' => $this->withKeywords($this->dedupe($services), $queries, $brand, $host),
            'summary' => $summary + [
                'locations' => $this->locationReport($queries, $areas),
                'brand_summary' => mb_substr(trim((string) ($structured['brand_summary'] ?? '')), 0, 400),
                'sector_code' => $brandSector,
                'sector_label' => $brandSector !== null ? $sectors[$brandSector] : null,
                'business_context' => $this->businessContext($structured['business_context'] ?? null),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function serviceRow(string $name, array $aliases, ?string $sector, bool $core, string $evidence, array $catalog, array $sectors, array $existing, float $confidence, array $matching = []): array
    {
        // Location-free name and aliases ("uyluk germe ankara" → "uyluk germe").
        $name = $this->withoutLocation($name) ?? $name;
        $seenAliases = [SeoText::fold($name) => true];
        $cleanAliases = [];
        foreach ($aliases as $alias) {
            $alias = $this->withoutLocation((string) $alias);
            if ($alias === null || isset($seenAliases[SeoText::fold($alias)])) {
                continue;
            }
            $seenAliases[SeoText::fold($alias)] = true;
            $cleanAliases[] = $alias;
        }
        $aliases = $cleanAliases;

        // Deterministic catalog check over the name and every alias (catches AI misses).
        $match = null;
        foreach (array_merge([$name], $aliases) as $label) {
            $key = $this->normalizer->normalize($label);
            $hit = $key !== '' ? ServiceCatalogName::query()->with('service')->where('normalized_key', $key)->first() : null;
            if ($hit?->service !== null) {
                $match = $hit->service;
                break;
            }
        }
        $label = $match?->primaryName?->raw_label ?? $name;
        $sector = $match?->sector ?? $sector;
        $already = isset($existing[$this->normalizer->normalize($label)]);

        return [
            'name' => $label,
            'aliases' => array_values(array_diff($aliases, [$label])),
            'matching_phrases' => $this->matchingPhrases(array_merge([$label], $aliases, $matching)),
            'catalog_item_id' => $match?->id,
            'is_new' => $match === null,
            'sector_code' => $sector,
            'sector_label' => $sector !== null ? ($sectors[$sector] ?? $sector) : null,
            'is_core' => $core,
            'evidence' => $evidence,
            'status' => $already ? 'already' : 'proposed',
            // A service the brand already has only gains missing matching expressions / keywords (additive).
            'selected' => $already ? $confidence >= 0.8 : ($confidence >= 0.8 && ($match !== null || $sector !== null)),
        ];
    }

    /**
     * "Eşleştirme ifadeleri": location-free, service-specific phrases. Queries containing one are
     * assigned to the service on every import, so generic words would pull unrelated queries in.
     *
     * @param  list<mixed>  $phrases
     * @return list<string>
     */
    private function matchingPhrases(array $phrases): array
    {
        $out = [];
        foreach ($phrases as $phrase) {
            if (! is_string($phrase)) {
                continue;
            }
            $phrase = $this->withoutLocation($phrase);
            $key = $phrase !== null ? LocationOptions::fold($phrase) : '';
            if ($key === '' || mb_strlen($key) < 3 || mb_strlen($phrase) > 60 || ServiceKeywordService::isGeneric($phrase) || isset($out[$key])) {
                continue;
            }
            $out[$key] = mb_strtolower(strtr($phrase, ['I' => 'ı', 'İ' => 'i']), 'UTF-8');
        }

        return array_slice(array_values($out), 0, 12);
    }

    private function withoutLocation(string $text): ?string
    {
        $clean = trim(LocationOptions::strip($text)['text']);

        return mb_strlen($clean) >= 2 ? $clean : null;
    }

    /**
     * Location-free, non-branded Search Console queries per service (longest matching name/alias
     * wins). These become the service's keywords in the query library after approval.
     *
     * @param  list<array<string, mixed>>  $services
     * @param  list<array{query: string, impressions: int}>  $queries
     * @return list<array<string, mixed>>
     */
    private function withKeywords(array $services, array $queries, Brand $brand, string $host): array
    {
        $brandTokens = array_values(array_filter(SeoText::tokens((string) $brand->name), static fn (string $t): bool => mb_strlen($t) >= 3));
        $domainRoot = str_replace(' ', '', SeoText::fold(BrandSetupMatcher::domainRoot($host)));
        $phrases = [];
        foreach ($services as $index => $service) {
            foreach (array_merge([$service['name']], $service['aliases'] ?? [], $service['matching_phrases'] ?? []) as $phrase) {
                $phrases[] = [$index, (string) $phrase, mb_strlen((string) $phrase)];
            }
        }
        usort($phrases, static fn (array $a, array $b): int => $b[2] <=> $a[2]);

        $keywords = [];
        foreach ($queries as $row) {
            $text = $this->withoutLocation($row['query']);
            if ($text === null) {
                continue;
            }
            $tokens = SeoText::tokens($text);
            $compact = str_replace(' ', '', SeoText::fold($text));
            if (($domainRoot !== '' && str_contains($compact, $domainRoot))
                || ($brandTokens !== [] && count(array_intersect($tokens, $brandTokens)) >= min(2, count($brandTokens)))) {
                continue; // branded
            }
            foreach ($phrases as [$index, $phrase]) {
                if (SeoText::containsPhrase($text, $phrase)) {
                    $key = SeoText::fold($text);
                    $keywords[$index][$key] ??= ['query' => $text, 'impressions' => 0];
                    $keywords[$index][$key]['impressions'] += (int) $row['impressions'];
                    break;
                }
            }
        }
        foreach ($services as $index => &$service) {
            $list = array_values($keywords[$index] ?? []);
            usort($list, static fn (array $a, array $b): int => $b['impressions'] <=> $a['impressions']);
            $service['keywords'] = array_slice($list, 0, 30);
        }
        unset($service);

        return $services;
    }

    /**
     * Locations the site's search queries mention, split by the brand's service areas.
     *
     * @param  list<array{query: string, impressions: int}>  $queries
     * @param  list<array<string, mixed>>  $areas
     * @return array<string, mixed>
     */
    private function locationReport(array $queries, array $areas): array
    {
        $buckets = ['in_area' => [], 'out_of_area' => [], 'mentioned' => []];
        foreach ($queries as $row) {
            $result = LocationOptions::classify($row['query'], $areas);
            $groups = $areas === [] ? ['mentioned' => $result['removed']] : ['in_area' => $result['in_area'], 'out_of_area' => $result['out_of_area']];
            foreach ($groups as $group => $names) {
                foreach ($names as $name) {
                    $buckets[$group][$name] ??= ['name' => $name, 'impressions' => 0, 'queries' => []];
                    $buckets[$group][$name]['impressions'] += (int) $row['impressions'];
                    if (count($buckets[$group][$name]['queries']) < 3) {
                        $buckets[$group][$name]['queries'][] = $row['query'];
                    }
                }
            }
        }
        $top = static function (array $rows, int $limit): array {
            usort($rows, static fn (array $a, array $b): int => $b['impressions'] <=> $a['impressions']);

            return array_slice(array_values($rows), 0, $limit);
        };

        return [
            'has_areas' => $areas !== [],
            'areas' => array_map(static fn (array $a): string => implode(', ', array_filter([$a['district_name'] ?? null, $a['city_name'] ?? null, $a['country_code'] ?? null])), $areas),
            'in_area' => $top($buckets['in_area'], 5),
            'out_of_area' => $top($buckets['out_of_area'], 8),
            'mentioned' => $top($buckets['mentioned'], 5),
        ];
    }

    /** @param list<array<string, mixed>> $services @return list<array<string, mixed>> */
    private function dedupe(array $services): array
    {
        $seen = [];
        $out = [];
        foreach ($services as $service) {
            $key = $service['catalog_item_id'] ?? SeoText::fold($service['name']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $service;
        }

        return $out;
    }

    /** @return array<string, true> */
    private function existingOfferingKeys(Brand $brand): array
    {
        $keys = [];
        foreach (BrandOffering::query()->with('names')->where('brand_id', $brand->id)->where('status', 'active')->get() as $offering) {
            foreach ($offering->names as $name) {
                $keys[$this->normalizer->normalize((string) $name->raw_label)] = true;
            }
        }

        return $keys;
    }

    /** @return list<array{name: string, sector: ?string}> */
    private function catalog(Brand $brand): array
    {
        $sectorCodes = method_exists($brand, 'sectorCodes') ? $brand->sectorCodes() : [];

        return ServiceCatalogItem::query()
            ->with('primaryName')
            ->where('status', 'active')
            ->when($sectorCodes !== [], fn ($q) => $q->where(fn ($s) => $s->whereIn('sector', $sectorCodes)->orWhereNull('sector')))
            ->limit(300)
            ->get()
            ->filter(fn (ServiceCatalogItem $item): bool => $item->primaryName !== null)
            ->map(fn (ServiceCatalogItem $item): array => ['name' => (string) $item->primaryName->raw_label, 'sector' => $item->sector])
            ->values()
            ->all();
    }

    /**
     * Published WordPress pages (from the connector): title, path and parent. The agency builds one page per service.
     *
     * @return list<array{title: string, path: string, parent: ?string}>
     */
    private function wordpressPages(DigitalAsset $website): array
    {
        if (! Schema::hasTable('website_cms_object_snapshot')) {
            return [];
        }
        $rows = DB::table('website_cms_object_snapshot')->where('digital_asset_id', $website->id)->where('cms', 'wordpress')
            ->where('object_type', 'page')->where('status', 'publish')->whereNotNull('title')
            ->orderBy('permalink')->limit(150)->get(['object_id', 'title', 'permalink', 'parent_id']);
        $titles = $rows->mapWithKeys(fn (object $row): array => [(string) $row->object_id => trim(html_entity_decode(strip_tags((string) $row->title), ENT_QUOTES | ENT_HTML5))]);

        return $rows->map(fn (object $row): array => [
            'title' => (string) $titles[(string) $row->object_id],
            'path' => SeoText::urlPath((string) $row->permalink),
            'parent' => $row->parent_id !== null && (string) $row->parent_id !== '0' ? ($titles[(string) $row->parent_id] ?? null) : null,
        ])->filter(fn (array $page): bool => $page['title'] !== '')->values()->all();
    }

    /** @return array{business_summary: ?string, business_model: ?string, target_audiences: list<string>, positioning: ?string, differentiators: list<string>}|null */
    private function businessContext(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }
        $text = fn (mixed $v, int $max): ?string => is_string($v) && trim($v) !== '' ? mb_substr(trim($v), 0, $max) : null;
        $list = fn (mixed $v): array => array_values(array_slice(array_filter(array_map(fn ($i): ?string => is_string($i) && trim($i) !== '' ? mb_substr(trim($i), 0, 120) : null, (array) $v)), 0, 5));
        $context = [
            'business_summary' => $text($raw['business_summary'] ?? null, 600),
            'business_model' => $text($raw['business_model'] ?? null, 160),
            'target_audiences' => $list($raw['target_audiences'] ?? []),
            'positioning' => $text($raw['positioning'] ?? null, 300),
            'differentiators' => $list($raw['differentiators'] ?? []),
        ];

        return array_filter($context, fn ($v): bool => $v !== null && $v !== []) === [] ? null : $context;
    }

    /**
     * Every text a proposed service can be found in: page titles / H1s / URL slugs, WordPress page titles, crawl
     * candidates and Search Console queries.
     *
     * @param  list<array<string, mixed>>  $pages
     * @param  list<array<string, mixed>>  $wordpressPages
     * @param  list<string>  $candidates
     * @param  list<array{query: string, impressions: int}>  $queries
     * @return list<string>
     */
    private function evidenceTexts(array $pages, array $wordpressPages, array $candidates, array $queries): array
    {
        $texts = [];
        foreach ($pages as $page) {
            $texts[] = implode(' ', array_filter([(string) ($page['title'] ?? ''), (string) ($page['h1'] ?? ''),
                str_replace(['-', '_', '/'], ' ', SeoText::urlPath((string) ($page['url'] ?? ''))), mb_substr((string) ($page['homepage_text'] ?? ''), 0, 1500)]));
        }
        foreach ($wordpressPages as $page) {
            $texts[] = (string) ($page['title'] ?? '');
        }

        return array_values(array_filter(array_merge($texts, $candidates, array_column($queries, 'query'))));
    }

    /**
     * A service is grounded when all distinctive words of its name (or of one alias) appear in one evidence text.
     *
     * @param  list<string>  $names
     * @param  list<string>  $evidence
     */
    private static function grounded(array $names, array $evidence): bool
    {
        foreach ($names as $name) {
            foreach ($evidence as $text) {
                if (SiteText::serviceScore($text, (string) $name) >= 0.99) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Service pages no proposed service covers (BrandGaps): the safety net for an AI answer that skipped some.
     *
     * @param  list<array<string, mixed>>  $services
     * @return list<array{0: string, 1: string}> [title, path]
     */
    private function uncoveredServicePages(?DigitalAsset $website, array $services): array
    {
        if ($website === null) {
            return [];
        }
        $names = [];
        foreach ($services as $service) {
            foreach (array_merge([(string) $service['name']], (array) $service['aliases']) as $label) {
                $names[] = (string) $label;
            }
        }

        return BrandGaps::uncoveredServicePages($website, $names);
    }

    /**
     * Site pages for the AI: the page inventory first (`pages`: WordPress + sitemap, with titles and categories — the
     * same list the website screen shows), the older crawl projection only for what the inventory lacks, and the
     * homepage text.
     *
     * @return list<array<string, mixed>>
     */
    private function pages(DigitalAsset $website): array
    {
        $rows = [];
        $seen = [];
        // Service pages first: categorized "hizmet" or under the site's service section (/tedavilerimiz/…), so a site
        // with hundreds of uncategorized URLs still sends every service page.
        $bulk = PageCategorizer::bulkSections((int) $website->id);
        $inventory = Page::query()->where('website_asset_id', $website->id)->where(fn ($q) => $q->whereNull('category')->orWhereIn('category', ['hizmet', 'lokasyon', 'diger']))
            ->orderBy('path')->limit(3000)->get(['url', 'path', 'title', 'h1', 'category'])
            ->groupBy(function (Page $page) use ($bulk): string {
                $path = (string) ($page->path ?: SeoText::urlPath((string) $page->url));
                if (PageCategorizer::inTemplateSection($path, $bulk)) {
                    return 'other'; // "/kws/…", hundreds of "/hurda/…" articles: not service pages
                }

                return $page->category === 'hizmet' || SitePagesReader::pathCategory($path) === 'hizmet' ? 'service' : 'other';
            });
        $inventory = collect($inventory->get('service', []))->concat(collect($inventory->get('other', []))->take(self::MAX_OTHER_PAGES));
        foreach ($inventory as $page) {
            $seen[SeoText::urlPath((string) $page->url)] = true;
            $rows[] = ['url' => (string) $page->url, 'title' => $page->title ?? SitePagesReader::slugTitle((string) $page->path), 'h1' => $page->h1];
        }
        $home = null;
        foreach (WebsitePageProfile::query()->where('website_asset_id', $website->id)->limit(400)->get() as $profile) {
            $web = (array) data_get($profile->source_states, 'website', []);
            $url = (string) ($web['url'] ?? $profile->preferred_url);
            if (! SeoText::isDocumentUrl($url, is_string(data_get($web, 'http.content_type')) ? data_get($web, 'http.content_type') : null)) {
                continue;
            }
            if (SeoText::urlPath($url) === '/') {
                $home = $profile;
            }
            if (isset($seen[SeoText::urlPath($url)]) || count($rows) >= self::MAX_OTHER_PAGES) {
                continue;
            }
            $title = data_get($web, 'document_head.title') ?? data_get($profile->source_states, 'wordpress.seo.title') ?? data_get($profile->source_states, 'wordpress.object.title');
            $rows[] = ['url' => $url, 'title' => is_string($title) ? $title : null, 'h1' => data_get($web, 'headings.h1')];
        }
        if ($home !== null) {
            $facts = $this->html->inspect($website, $home, 1500);
            if ($facts !== null && $facts['text_excerpt'] !== '') {
                array_unshift($rows, ['url' => $home->preferred_url, 'homepage_text' => $facts['text_excerpt']]);
            }
        }

        return $rows;
    }

    /** @return list<array{query: string, impressions: int}> */
    private function queries(array $items): array
    {
        $gsc = collect($items)->first(fn (array $item): bool => ($item['capability'] ?? null) === 'search_console' && in_array($item['status'], ['proposed', 'already'], true));
        if ($gsc === null || ! Schema::hasTable('gsc_query_page_daily')) {
            return [];
        }
        $resource = CoreExternalResource::query()->find($gsc['resource_id']);
        if ($resource === null) {
            return [];
        }
        $siteUrl = (string) ($resource->metadata['site_url'] ?? $resource->external_id);

        return DB::table('gsc_query_page_daily')
            ->where('external_resource_id', $resource->id)
            ->where('site_url', $siteUrl)
            ->where('reporting_date', '>=', now()->subDays(90)->toDateString())
            ->selectRaw('query, sum(impressions) as impressions')
            ->groupBy('query')
            ->orderByDesc('impressions')
            ->limit(500)
            ->get()
            ->map(static fn (object $row): array => ['query' => (string) $row->query, 'impressions' => (int) $row->impressions])
            ->all();
    }

    /** @return list<string> */
    private function crawlCandidates(DigitalAsset $website): array
    {
        return DiscoveryCandidate::query()
            ->where('digital_asset_id', $website->id)
            ->where('candidate_type', 'service')
            ->where('status', '!=', DiscoveryCandidate::STATUS_IGNORED)
            ->limit(40)
            ->pluck('proposed_value')
            ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => trim($value))
            ->unique()
            ->values()
            ->all();
    }
}
