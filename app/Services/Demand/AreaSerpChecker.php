<?php

namespace App\Services\Demand;

use App\Models\Brand;
use App\Models\BrandDemandQuery;
use App\Models\BrandServiceArea;
use App\Models\SearchDemandCompetitor;
use App\Models\SearchDemandCompetitorSource;
use App\Services\BrandSetup\BrandSetupMatcher;
use App\Services\Integrations\DataForSeo\DataForSeoApiClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Area SERP checks for one brand (opt-in, paid): for each service the most valuable non-branded, in-area
 * queries are checked in Google at the brand's service-area locations (DataForSEO Live Regular, top 10).
 * A result for the same keyword + location + language is reused for freshness_days (any brand), so a
 * weekly run costs money roughly once a month. The brand's monthly USD cap is never exceeded.
 * Domains that rank in the top 10 for at least N of a service's keywords become pending competitors.
 */
final class AreaSerpChecker
{
    public function __construct(
        private readonly DataForSeoApiClient $client,
        private readonly DataForSeoIntegrationLookup $integrations,
        private readonly DataForSeoSerpLocationResolver $locations,
    ) {}

    /**
     * @return array{planned: int, checked: int, reused: int, failed: int, skipped_budget: int, spent_usd: float, competitors: int}
     */
    public function run(Brand $brand): array
    {
        $stats = ['planned' => 0, 'checked' => 0, 'reused' => 0, 'failed' => 0, 'skipped_budget' => 0, 'spent_usd' => 0.0, 'competitors' => 0];
        $integration = $this->integrations->active();
        if (! $brand->demand_serp_enabled || $integration === null) {
            return $stats;
        }

        $cap = (float) ($brand->demand_serp_monthly_usd ?? config('moxdop-demand.serp.monthly_usd_per_brand', 2.0));
        $rate = (float) config('moxdop-demand.serp.cost_per_check_usd', 0.002);
        $freshDays = (int) config('moxdop-demand.serp.freshness_days', 28);
        $language = (string) config('moxdop-demand.serp.language_code', 'tr');
        $ownDomains = $this->ownDomains($brand);
        $spent = $this->spentThisMonth($brand);

        foreach ($this->plan($brand) as $check) {
            $stats['planned']++;
            $fingerprint = hash('sha256', implode('|', [mb_strtolower($check['keyword']), $check['location_code'], $language, 'desktop', 10]));
            $recent = DB::table('demand_serp_checks')->where('fingerprint', $fingerprint)->whereIn('status', ['completed', 'reused'])
                ->where('checked_at', '>=', now()->subDays($freshDays))->orderByDesc('checked_at')->first();
            if ($recent !== null && (int) $recent->brand_id === (int) $brand->id && (int) ($recent->brand_offering_id ?? 0) === (int) ($check['offering_id'] ?? 0)) {
                continue; // this brand already has a fresh result for this check
            }
            if ($recent !== null) {
                $results = (array) json_decode((string) $recent->results, true);
                $this->store($brand, $check, $language, $fingerprint, 'reused', 0.0, $results, $ownDomains);
                $stats['reused']++;

                continue;
            }
            if ($spent + $rate > $cap) {
                $stats['skipped_budget']++;

                continue;
            }
            try {
                $response = $this->client->postSerpGoogleOrganicLiveRegular($integration, [[
                    'keyword' => $check['keyword'], 'location_code' => $check['location_code'], 'language_code' => $language,
                    'device' => 'desktop', 'depth' => 10,
                ]]);
                $cost = $response->cost ?? $rate;
                $spent += $cost;
                $stats['spent_usd'] += $cost;
                $this->store($brand, $check, $language, $fingerprint, 'completed', $cost, $this->organic($response->tasks), $ownDomains);
                $stats['checked']++;
            } catch (Throwable $exception) {
                report($exception);
                $this->store($brand, $check, $language, $fingerprint, 'failed', 0.0, [], $ownDomains, $exception->getMessage());
                $stats['failed']++;
            }
        }
        $stats['competitors'] = $this->proposeCompetitors($brand, $ownDomains);
        $stats['spent_usd'] = round($stats['spent_usd'], 4);

        return $stats;
    }

    /**
     * Which keyword at which location, most valuable services first.
     *
     * @return list<array{keyword: string, location_code: int, offering_id: ?int, area_id: ?int, query_id: int}>
     */
    public function plan(Brand $brand): array
    {
        $perService = (int) config('moxdop-demand.serp.queries_per_service', 3);
        $areasPerQuery = (int) config('moxdop-demand.serp.areas_per_query', 2);
        $areas = BrandServiceArea::query()->where('brand_id', $brand->id)->where('status', 'active')->orderBy('priority_rank')->get()
            ->map(fn (BrandServiceArea $area): array => ['id' => (int) $area->id, 'code' => $this->safeResolve($area)])
            ->filter(fn (array $area): bool => $area['code'] !== null)->values();
        $fallback = $this->fallbackLocation($brand);

        $rows = BrandDemandQuery::query()->where('brand_id', $brand->id)->whereNotNull('brand_offering_id')
            ->where('is_branded', false)->where('location_status', '!=', 'out_of_area')->where('value_score', '>', 0)
            ->orderByDesc('value_score')->get();
        $services = $rows->groupBy('brand_offering_id')
            ->sortByDesc(fn (Collection $group): float => (float) $group->sum('value_score'))
            ->take((int) config('moxdop-demand.serp.max_services', 10));

        $checks = [];
        foreach ($services as $offeringId => $group) {
            foreach ($group->take($perService) as $row) {
                // A query that already names a service area is checked there; otherwise in the top areas.
                $targets = $row->brand_service_area_id !== null
                    ? $areas->where('id', (int) $row->brand_service_area_id)->values()
                    : $areas->take($areasPerQuery);
                if ($targets->isEmpty()) {
                    $targets = collect([['id' => null, 'code' => $fallback]]);
                }
                foreach ($targets as $target) {
                    $checks[] = [
                        'keyword' => (string) $row->query, 'location_code' => (int) $target['code'],
                        'offering_id' => (int) $offeringId, 'area_id' => $target['id'], 'query_id' => (int) $row->id,
                    ];
                }
            }
        }

        return $checks;
    }

    public function spentThisMonth(Brand $brand): float
    {
        return (float) DB::table('demand_serp_checks')->where('brand_id', $brand->id)
            ->where('checked_at', '>=', now()->startOfMonth())->sum('cost_usd');
    }

    /** @return list<array{rank: int, domain: string, url: string, title: string}> */
    private function organic(array $tasks): array
    {
        $out = [];
        foreach ((array) data_get($tasks, '0.result.0.items', []) as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'organic' || count($out) >= 10) {
                continue;
            }
            $url = (string) ($item['url'] ?? '');
            $host = BrandSetupMatcher::host($url);
            if ($host === '') {
                continue;
            }
            $out[] = ['rank' => (int) ($item['rank_group'] ?? count($out) + 1), 'domain' => $host, 'url' => $url, 'title' => mb_substr((string) ($item['title'] ?? ''), 0, 300)];
        }

        return $out;
    }

    /**
     * @param  array{keyword: string, location_code: int, offering_id: ?int, area_id: ?int, query_id: int}  $check
     * @param  list<array<string, mixed>>  $results
     * @param  list<string>  $ownDomains
     */
    private function store(Brand $brand, array $check, string $language, string $fingerprint, string $status, float $cost, array $results, array $ownDomains, ?string $error = null): void
    {
        $ours = collect($results)->first(fn (array $r): bool => $this->isOwn((string) ($r['domain'] ?? ''), $ownDomains));
        DB::table('demand_serp_checks')->insert([
            'brand_id' => $brand->id, 'brand_offering_id' => $check['offering_id'], 'brand_service_area_id' => $check['area_id'],
            'brand_demand_query_id' => $check['query_id'], 'keyword' => $check['keyword'], 'location_code' => $check['location_code'],
            'language_code' => $language, 'fingerprint' => $fingerprint, 'status' => $status, 'cost_usd' => round($cost, 4),
            'our_rank' => $ours['rank'] ?? null, 'our_url' => $ours['url'] ?? null,
            'results' => json_encode($results, JSON_UNESCAPED_UNICODE), 'error' => $error !== null ? mb_substr($error, 0, 1000) : null,
            'checked_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Domains in the top 10 for at least N keywords of the same service (latest checks) → pending competitors.
     *
     * @param  list<string>  $ownDomains
     */
    private function proposeCompetitors(Brand $brand, array $ownDomains): int
    {
        $minimum = max(1, (int) config('moxdop-demand.serp.competitor_min_keywords', 2));
        $checks = DB::table('demand_serp_checks')->where('brand_id', $brand->id)->whereIn('status', ['completed', 'reused'])
            ->where('checked_at', '>=', now()->subDays((int) config('moxdop-demand.serp.freshness_days', 28) * 2))->get();
        $domains = [];
        foreach ($checks as $check) {
            foreach ((array) json_decode((string) $check->results, true) as $result) {
                $domain = (string) ($result['domain'] ?? '');
                if ($domain === '' || $this->isOwn($domain, $ownDomains)) {
                    continue;
                }
                $domains[$domain]['keywords'][mb_strtolower((string) $check->keyword)] = true;
                $domains[$domain]['services'][(int) $check->brand_offering_id] = true;
                $domains[$domain]['urls'][(string) $result['url']] = true;
                $domains[$domain]['best'] = min($domains[$domain]['best'] ?? 99, (int) $result['rank']);
            }
        }
        $known = SearchDemandCompetitor::query()->where('brand_id', $brand->id)->pluck('normalized_domain')->map(fn ($d) => BrandSetupMatcher::host((string) $d))->flip();
        $created = 0;
        foreach ($domains as $domain => $info) {
            if (count($info['keywords']) < $minimum || isset($known[$domain])) {
                continue;
            }
            DB::transaction(function () use ($brand, $domain, $info): void {
                $competitor = SearchDemandCompetitor::query()->create([
                    'uuid' => (string) Str::uuid(), 'brand_id' => $brand->id, 'display_name' => $domain,
                    'normalized_domain' => $domain, 'normalized_domain_hash' => hash('sha256', $domain),
                    'status' => 'pending', 'entity_kind' => 'unknown', 'is_serp_competitor' => true,
                    'first_observed_at' => now(), 'last_observed_at' => now(),
                ]);
                SearchDemandCompetitorSource::query()->create([
                    'search_demand_competitor_id' => $competitor->id, 'source_type' => 'area_serp', 'provider' => 'dataforseo',
                    'source_fingerprint' => hash('sha256', 'area_serp|'.$competitor->id),
                    'evidence_payload' => ['keywords' => array_keys($info['keywords']), 'best_rank' => $info['best'], 'urls' => array_slice(array_keys($info['urls']), 0, 10)],
                    'observed_at' => now(),
                ]);
            });
            $created++;
        }

        return $created;
    }

    /** @return list<string> */
    private function ownDomains(Brand $brand): array
    {
        return $brand->digitalAssets()->where('type', 'website')->get(['primary_url', 'domain'])
            ->map(fn ($site): string => BrandSetupMatcher::host((string) ($site->primary_url ?: $site->domain)))->filter()->values()->all();
    }

    /** @param  list<string>  $ownDomains */
    private function isOwn(string $domain, array $ownDomains): bool
    {
        foreach ($ownDomains as $own) {
            if ($domain === $own || str_ends_with($domain, '.'.$own)) {
                return true;
            }
        }

        return false;
    }

    private function safeResolve(BrandServiceArea $area): ?int
    {
        try {
            return $this->locations->resolve($area);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function fallbackLocation(Brand $brand): int
    {
        $site = $brand->digitalAssets()->where('type', 'website')->whereNotNull('seo_market_location_code')->first();

        return (int) ($site?->seo_market_location_code ?: config('moxdop-demand.serp.fallback_location_code', 2792));
    }
}
