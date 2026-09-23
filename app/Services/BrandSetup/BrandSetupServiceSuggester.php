<?php

namespace App\Services\BrandSetup;

use App\Ai\Agents\BrandSetupAgent;
use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use App\Models\DiscoveryCandidate;
use App\Models\IntelligenceProjection\WebsitePageProfile;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCatalogName;
use App\Models\ServiceCategory;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\SeoTasks\SeoStoredHtmlReader;
use App\Services\SeoTasks\SeoText;
use App\Support\Ai\AiRouteKeys;
use App\Support\BrandIntelligence\IdentityLabelNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Service part of "Otomatik kur": one AI call over the site's stored pages, Search Console queries and
 * crawl candidates, then a deterministic check against the service catalog so nothing is duplicated.
 */
final class BrandSetupServiceSuggester
{
    public function __construct(
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
        private readonly IdentityLabelNormalizer $normalizer,
        private readonly SeoStoredHtmlReader $html,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $items  matcher output (to find the chosen Search Console property)
     * @return array{status: string, services: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function suggest(Brand $brand, string $host, array $items): array
    {
        $website = $brand->digitalAssets()->where('type', 'website')->get()
            ->first(fn (DigitalAsset $asset): bool => BrandSetupMatcher::host((string) ($asset->primary_url ?: $asset->domain)) === $host);
        $pages = $website !== null ? $this->pages($website) : [];
        $queries = $this->queries($items);
        $candidates = $website !== null ? $this->crawlCandidates($website) : [];

        if ($pages === [] && $queries === [] && $candidates === []) {
            return ['status' => 'waiting_for_site', 'services' => [], 'summary' => ['reason' => 'no_site_data']];
        }

        $catalog = $this->catalog($brand);
        $sectors = ServiceCategory::options();
        $existing = $this->existingOfferingKeys($brand);

        $structured = null;
        $summary = [];
        try {
            $route = $this->routes->resolve(AiRouteKeys::BRAND_SETUP);
            if (! $route->isEmpty()) {
                $this->runtime->prepare(array_keys($route->providerModels));
                $response = (new BrandSetupAgent)->prompt(
                    "CONTEXT_JSON\n".json_encode([
                        'brand' => ['name' => $brand->name, 'domain' => $host],
                        'pages' => array_slice($pages, 0, 60),
                        'search_console_queries' => array_slice($queries, 0, 150),
                        'crawl_service_candidates' => $candidates,
                        'CATALOG' => array_map(static fn (array $c): array => ['name' => $c['name'], 'sector_code' => $c['sector']], $catalog),
                        'SECTORS' => collect($sectors)->map(fn ($name, $code): array => ['code' => $code, 'name' => $name])->values()->all(),
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    provider: $route->providerModels,
                    timeout: 120,
                );
                $structured = $response->toArray();
                $summary = ['provider' => $route->primaryProvider(), 'model' => $route->primaryModel()];
            } else {
                $summary = ['ai_skipped_reason' => 'no_eligible_provider'];
            }
        } catch (Throwable $exception) {
            Log::warning('Brand setup AI call failed.', ['brand_id' => $brand->id, 'error' => $exception->getMessage()]);
            $summary = ['ai_skipped_reason' => 'llm_error'];
        }

        if (! is_array($structured)) {
            // Without AI only the crawl's own service candidates are proposed, all unticked.
            $services = array_map(fn (string $name): array => $this->serviceRow($name, [], null, false, 'Site taramasında bulunan hizmet başlığı', $catalog, $sectors, $existing, 0.5), $candidates);

            return ['status' => $services === [] ? 'ai_unavailable' : 'ready', 'services' => $this->dedupe($services), 'summary' => $summary];
        }

        $catalogNames = array_column($catalog, 'name');
        $services = [];
        foreach (array_slice(is_array($structured['services'] ?? null) ? $structured['services'] : [], 0, 12) as $row) {
            if (! is_array($row) || ! is_string($row['name'] ?? null) || mb_strlen(trim($row['name'])) < 2 || mb_strlen($row['name']) > 80) {
                continue;
            }
            $catalogName = is_string($row['catalog_name'] ?? null) && in_array($row['catalog_name'], $catalogNames, true) ? $row['catalog_name'] : null;
            $sector = is_string($row['sector_code'] ?? null) && isset($sectors[$row['sector_code']]) ? $row['sector_code'] : null;
            $aliases = array_values(array_filter((array) ($row['aliases'] ?? []), static fn ($a): bool => is_string($a) && mb_strlen(trim($a)) >= 2 && mb_strlen($a) <= 80));
            $services[] = $this->serviceRow($catalogName ?? trim($row['name']), array_slice($aliases, 0, 4), $sector, (bool) ($row['is_core'] ?? false), mb_substr((string) ($row['evidence'] ?? ''), 0, 200), $catalog, $sectors, $existing, 0.85);
        }
        $brandSector = is_string($structured['sector_code'] ?? null) && isset($sectors[$structured['sector_code']]) ? $structured['sector_code'] : null;

        return [
            'status' => 'ready',
            'services' => $this->dedupe($services),
            'summary' => $summary + [
                'brand_summary' => mb_substr(trim((string) ($structured['brand_summary'] ?? '')), 0, 400),
                'sector_code' => $brandSector,
                'sector_label' => $brandSector !== null ? $sectors[$brandSector] : null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function serviceRow(string $name, array $aliases, ?string $sector, bool $core, string $evidence, array $catalog, array $sectors, array $existing, float $confidence): array
    {
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
            'catalog_item_id' => $match?->id,
            'is_new' => $match === null,
            'sector_code' => $sector,
            'sector_label' => $sector !== null ? ($sectors[$sector] ?? $sector) : null,
            'is_core' => $core,
            'evidence' => $evidence,
            'status' => $already ? 'already' : 'proposed',
            'selected' => ! $already && $confidence >= 0.8 && ($match !== null || $sector !== null),
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

    /** @return list<array<string, mixed>> */
    private function pages(DigitalAsset $website): array
    {
        $rows = [];
        $home = null;
        foreach (WebsitePageProfile::query()->where('website_asset_id', $website->id)->limit(400)->get() as $profile) {
            $web = (array) data_get($profile->source_states, 'website', []);
            $url = (string) ($web['url'] ?? $profile->preferred_url);
            if (! SeoText::isDocumentUrl($url, is_string(data_get($web, 'http.content_type')) ? data_get($web, 'http.content_type') : null)) {
                continue;
            }
            $title = data_get($web, 'document_head.title') ?? data_get($profile->source_states, 'wordpress.seo.title') ?? data_get($profile->source_states, 'wordpress.object.title');
            $rows[] = ['url' => $url, 'title' => is_string($title) ? $title : null, 'h1' => data_get($web, 'headings.h1')];
            if (SeoText::urlPath($url) === '/') {
                $home = $profile;
            }
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
            ->limit(150)
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
