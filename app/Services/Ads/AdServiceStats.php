<?php

namespace App\Services\Ads;

use App\Models\Brand;
use App\Models\BrandServiceArea;
use App\Models\Cluster;
use App\Models\DigitalAsset;
use App\Models\OfferingPage;
use App\Services\Gbp\GbpDailyWorkspace;
use App\Services\Gbp\GbpStandardInput;
use App\Services\GoogleAds\GoogleAdsScreen;
use App\Services\Meta\MetaCampaignBoard;
use App\Services\Meta\MetaCampaignServices;
use App\Services\Meta\MetaScreen;
use App\Services\SeoTasks\SeoText;
use App\Services\Site\Analysis\SiteAnalysisReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cross-brand ad numbers, rebuilt daily by rules (no AI): every brand service's 30-day spend and results per channel
 * and result type (Meta: campaign → hizmet, an ad's numbers split over the services it names; Google Ads: keyword →
 * hizmet, conversions), and every Meta campaign's 30-day row for Meta masası and Strateji öner (with its recipe: settings, targeting, best ad). Comparisons read the median cost per
 * result of the same catalog service and result type over the other brands, in the brand's city when enough brands
 * are there. Result types are never mixed.
 */
class AdServiceStats
{
    public const int DAYS = 30;

    /** A brand counts in an average only with at least this many results of the type. */
    public const int MIN_RESULTS = 3;

    /** The city average is used when at least this many other brands have numbers there; else all cities. */
    public const int MIN_CITY_BRANDS = 3;

    /** Accounts whose last collected day is older than this are left out (stale numbers). */
    public const int STALE_DAYS = 45;

    /** Average "around": within this many percent of the median. */
    public const float AROUND_PCT = 15.0;

    /** @var array<string, string> */
    public const array TYPE_LABELS = ['leads' => 'form', 'messages' => 'mesaj', 'purchases' => 'satış', 'conversions' => 'dönüşüm'];

    public function __construct(
        private readonly MetaScreen $screen,
        private readonly MetaCampaignBoard $board,
        private readonly MetaCampaignServices $services,
        private readonly GoogleAdsScreen $googleAds,
        private readonly SiteAnalysisReader $site,
        private readonly GbpDailyWorkspace $gbp,
        private readonly GbpStandardInput $gbpInput,
    ) {}

    /** Rebuilds the numbers of one ad account. @return int service rows stored */
    public function refresh(DigitalAsset $asset): int
    {
        $asset->loadMissing('brand');
        $brand = $asset->brand;
        if ($brand === null) {
            return 0;
        }

        return match ($asset->type) {
            'meta_ads' => $this->refreshMeta($asset, $brand),
            'google_ads' => $this->refreshGoogleAds($asset, $brand),
            'website' => $this->refreshWebsite($asset, $brand),
            'google_business_profile' => $this->refreshProfile($asset, $brand),
            default => 0,
        };
    }

    private function refreshMeta(DigitalAsset $asset, Brand $brand): int
    {
        $account = $this->screen->account($asset);
        $board = $account !== null ? $this->board->board($asset, self::DAYS) : null;
        $end = $board['window']['to'] ?? null;
        if ($board === null || $end === null || CarbonImmutable::parse($end)->lt(now()->subDays(self::STALE_DAYS)->startOfDay())) {
            return $this->replace($asset, 'meta', [], []);
        }
        $offerings = array_column($this->services->offerings($brand), null, 'id');
        $city = self::city($brand);
        $currency = (string) ($account['currency'] ?? '');
        $entities = $this->screen->entities($account);
        $ads = $this->screen->adPerformance($account, $board['window']['from'], $end, $entities);
        $hasProfile = Schema::hasColumn('ad_campaign_stats', 'profile');

        $campaigns = [];
        foreach ($board['rows'] as $row) {
            if ($row['spend'] <= 0 && $row['status'] !== 'live') {
                continue;
            }
            $campaigns[] = ['channel' => 'meta', 'digital_asset_id' => $asset->id, 'brand_id' => $brand->id, 'campaign_id' => $row['id'], 'name' => mb_substr($row['name'], 0, 300),
                'status' => $row['status'], 'result_type' => $row['type'], 'spend' => $row['spend'], 'results' => $row['results'], 'cpr' => $row['cpr'], 'prev_cpr' => $row['prev_cpr'],
                'service_state' => $row['service_state'], 'services' => json_encode(array_map(fn (array $s): array => ['id' => $s['id'], 'name' => $s['name'], 'status' => $s['status'],
                    'service_id' => $offerings[$s['id']]['service_id'] ?? null], $row['services']), JSON_UNESCAPED_UNICODE),
                'alerts' => json_encode($row['alerts'], JSON_UNESCAPED_UNICODE), 'currency' => $currency !== '' ? mb_substr($currency, 0, 8) : null, 'period_end' => $end]
                + ($hasProfile ? ['profile' => json_encode(MetaCampaignBoard::profile($row['id'], $entities, $ads, $row['type'], $row['budget']), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)] : []);
        }

        // Service numbers: each ad's spend and the results of its campaign's type, split over the ad's services.
        $map = $this->services->map($asset);
        $types = array_column($board['rows'], 'type', 'id');
        $sum = [];
        foreach ($this->services->adShares($asset, $entities, $map) as $adId => $shares) {
            $ad = $ads[$adId] ?? null;
            if ($ad === null) {
                continue;
            }
            $type = $types[$ad['campaign_id']] ?? null;
            if (! in_array($type, ['leads', 'messages', 'purchases'], true)) {
                continue;
            }
            foreach ($shares as $offeringId => $share) {
                $sum[$offeringId][$type]['spend'] = ($sum[$offeringId][$type]['spend'] ?? 0) + $ad['spend'] * $share;
                $sum[$offeringId][$type]['results'] = ($sum[$offeringId][$type]['results'] ?? 0) + $ad[$type] * $share;
                $sum[$offeringId][$type]['by'][$ad['campaign_id']] = ($sum[$offeringId][$type]['by'][$ad['campaign_id']] ?? 0) + $ad[$type] * $share;
            }
        }
        $names = array_column($board['rows'], 'name', 'id');
        foreach ($sum as $offeringId => $types) {
            foreach ($types as $type => $n) {
                arsort($n['by']);
                $sum[$offeringId][$type]['top'] = (reset($n['by']) ?: 0) > 0 ? ($names[array_key_first($n['by'])] ?? null) : null;
            }
        }

        return $this->replace($asset, 'meta', $this->serviceRows($asset, $brand, $city, $end, $sum, $offerings, $currency), $campaigns);
    }

    private function refreshGoogleAds(DigitalAsset $asset, Brand $brand): int
    {
        $totals = $this->googleAds->serviceTotals($asset, self::DAYS);
        if ($totals['period_end'] === null) {
            return $this->replace($asset, 'google_ads', [], null);
        }
        $offerings = [];
        foreach ($this->services->offerings($brand) as $offering) {
            if ($offering['service_id'] !== null) {
                $offerings[$offering['service_id']] ??= $offering;
            }
        }
        $sum = [];
        foreach ($totals['services'] as $serviceId => $row) {
            if (isset($offerings[$serviceId])) {
                $sum[$offerings[$serviceId]['id']]['conversions'] = ['spend' => $row['spend'], 'results' => $row['conversions'], 'top' => $row['campaign'] ?? null];
            }
        }

        return $this->replace($asset, 'google_ads', $this->serviceRows($asset, $brand, self::city($brand), $totals['period_end'], $sum, array_column($offerings, null, 'id'), (string) ($totals['currency'] ?? '')), null);
    }

    /**
     * @param  array<int, array<string, array{spend: float, results: float, top?: ?string}>>  $sum  offering id => type => numbers
     * @param  array<int, array<string, mixed>>  $offerings  offering id => offering
     * @return list<array<string, mixed>>
     */
    private function serviceRows(DigitalAsset $asset, Brand $brand, string $city, string $end, array $sum, array $offerings, string $currency = ''): array
    {
        $rows = [];
        foreach ($sum as $offeringId => $types) {
            foreach ($types as $type => $n) {
                if ($n['spend'] <= 0 && $n['results'] <= 0) {
                    continue;
                }
                $rows[] = ['channel' => $asset->type === 'meta_ads' ? 'meta' : 'google_ads', 'digital_asset_id' => $asset->id, 'brand_id' => $brand->id, 'brand_offering_id' => $offeringId,
                    'service_id' => $offerings[$offeringId]['service_id'] ?? null, 'sector_id' => $brand->sector_id, 'city' => $city, 'result_type' => $type,
                    'spend' => round($n['spend'], 2), 'results' => round($n['results'], 2), 'period_end' => $end]
                    + ($this->hasTopCampaign() ? ['top_campaign' => isset($n['top']) ? mb_substr((string) $n['top'], 0, 300) : null, 'currency' => $currency !== '' ? mb_substr($currency, 0, 8) : null] : []);
            }
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $services
     * @param  list<array<string, mixed>>|null  $campaigns  null: this channel keeps no campaign rows
     */
    private function replace(DigitalAsset $asset, string $channel, array $services, ?array $campaigns): int
    {
        $now = now();
        DB::transaction(function () use ($asset, $channel, $services, $campaigns, $now): void {
            DB::table('ad_service_stats')->where('digital_asset_id', $asset->id)->where('channel', $channel)->delete();
            foreach (array_chunk($services, 200) as $chunk) {
                DB::table('ad_service_stats')->insert(array_map(fn (array $r): array => $r + ['created_at' => $now, 'updated_at' => $now], $chunk));
            }
            if ($campaigns !== null) {
                DB::table('ad_campaign_stats')->where('digital_asset_id', $asset->id)->where('channel', $channel)->delete();
                foreach (array_chunk($campaigns, 200) as $chunk) {
                    DB::table('ad_campaign_stats')->insert(array_map(fn (array $r): array => $r + ['created_at' => $now, 'updated_at' => $now], $chunk));
                }
            }
        });

        return count($services);
    }

    private ?bool $topCampaign = null;

    private function hasTopCampaign(): bool
    {
        return $this->topCampaign ??= Schema::hasColumn('ad_service_stats', 'top_campaign');
    }

    /**
     * Web sitesi per brand service (Kazananlar): search clicks, impressions and impression-weighted position of the
     * service's clusters (SiteAnalysisReader::clusters, cluster → catalog service), the pages linked to the service and
     * its strongest page by search clicks.
     */
    private function refreshWebsite(DigitalAsset $site, Brand $brand): int
    {
        if (! Schema::hasTable('web_service_stats')) {
            return 0;
        }
        $offerings = [];
        foreach ($this->services->offerings($brand) as $offering) {
            if ($offering['service_id'] !== null) {
                $offerings[$offering['service_id']] ??= $offering;
            }
        }
        $clusters = $offerings === [] ? [] : $this->site->clusters($site, self::DAYS);
        $serviceOf = Cluster::query()->whereIn('id', array_column($clusters, 'cluster_id') ?: [0])->pluck('service_id', 'id')->all();
        $sum = [];
        foreach ($clusters as $cluster) {
            $offering = $offerings[$serviceOf[$cluster['cluster_id']] ?? 0] ?? null;
            if ($offering === null) {
                continue;
            }
            $n = &$sum[$offering['id']];
            $n ??= ['clicks' => 0, 'impressions' => 0, 'weighted' => 0.0, 'pages' => 0, 'top_url' => null, 'top_clicks' => null];
            $n['clicks'] += (int) $cluster['clicks'];
            $n['impressions'] += (int) $cluster['impressions'];
            $n['weighted'] += $cluster['position'] !== null ? (float) $cluster['position'] * (int) $cluster['impressions'] : 0.0;
            unset($n);
        }
        $traffic = [];
        foreach ($offerings === [] ? [] : $this->site->pages($site, self::DAYS) as $page) {
            $traffic[$page['path']] = (int) ($page['clicks'] ?? 0);
        }
        $links = OfferingPage::query()->join('pages', 'pages.id', '=', 'offering_pages.page_id')->where('pages.website_asset_id', $site->id)
            ->whereIn('offering_pages.brand_offering_id', array_column($offerings, 'id') ?: [0])->get(['offering_pages.brand_offering_id', 'pages.url']);
        foreach ($links as $link) {
            $n = &$sum[(int) $link->brand_offering_id];
            $n ??= ['clicks' => 0, 'impressions' => 0, 'weighted' => 0.0, 'pages' => 0, 'top_url' => null, 'top_clicks' => null];
            $n['pages']++;
            $clicks = $traffic[SiteAnalysisReader::path((string) $link->url)] ?? 0;
            if ($n['top_clicks'] === null || $clicks > $n['top_clicks']) {
                [$n['top_url'], $n['top_clicks']] = [(string) $link->url, $clicks];
            }
            unset($n);
        }
        $byId = array_column($offerings, null, 'id');
        $city = self::city($brand);
        $end = CarbonImmutable::today()->subDay()->toDateString();
        $now = now();
        $rows = [];
        foreach ($sum as $offeringId => $n) {
            $rows[] = ['digital_asset_id' => $site->id, 'brand_id' => $brand->id, 'brand_offering_id' => $offeringId, 'service_id' => $byId[$offeringId]['service_id'] ?? null,
                'sector_id' => $brand->sector_id, 'city' => $city, 'pages' => $n['pages'], 'clicks' => $n['clicks'], 'impressions' => $n['impressions'],
                'position' => $n['impressions'] > 0 && $n['weighted'] > 0 ? round($n['weighted'] / $n['impressions'], 1) : null,
                'top_url' => $n['top_url'] !== null ? mb_substr($n['top_url'], 0, 600) : null, 'top_clicks' => $n['top_clicks'], 'period_end' => $end,
                'created_at' => $now, 'updated_at' => $now];
        }
        DB::transaction(function () use ($site, $rows): void {
            DB::table('web_service_stats')->where('digital_asset_id', $site->id)->delete();
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('web_service_stats')->insert($chunk);
            }
        });

        return count($rows);
    }

    /** İşletme Profili (Kazananlar): rating, reviews, new reviews in 30 days and the brand services listed on the profile. */
    private function refreshProfile(DigitalAsset $asset, Brand $brand): int
    {
        if (! Schema::hasTable('gbp_profile_stats')) {
            return 0;
        }
        $resource = $this->gbp->resource($asset);
        if ($resource === null) {
            DB::table('gbp_profile_stats')->where('digital_asset_id', $asset->id)->delete();

            return 0;
        }
        $rid = (int) $resource->id;
        $snapshot = DB::table('gbp_location_snapshots')->where('external_resource_id', $rid)->orderByDesc('captured_at')->orderByDesc('id')->first(['average_rating', 'total_review_count']);
        $reviews = DB::table('gbp_reviews')->where('external_resource_id', $rid);
        $labels = $this->gbpInput->services($rid)['labels'];
        $listed = [];
        foreach ($this->services->offerings($brand) as $offering) {
            if ($offering['service_id'] !== null && self::listed($offering['names'], $labels)) {
                $listed[] = (int) $offering['service_id'];
            }
        }
        DB::table('gbp_profile_stats')->updateOrInsert(['digital_asset_id' => $asset->id], [
            'brand_id' => $brand->id, 'name' => mb_substr((string) $asset->name, 0, 300), 'city' => self::city($brand),
            'rating' => $snapshot?->average_rating !== null ? round((float) $snapshot->average_rating, 2) : null,
            'reviews' => $snapshot?->total_review_count !== null ? (int) $snapshot->total_review_count : (clone $reviews)->count(),
            'new_reviews' => (clone $reviews)->where('create_time', '>=', now()->subDays(self::DAYS))->count(),
            'labels' => json_encode(array_values(array_unique($labels)), JSON_UNESCAPED_UNICODE), 'service_ids' => json_encode(array_values(array_unique($listed))),
            'period_end' => CarbonImmutable::today()->subDay()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return 1;
    }

    /**
     * Whether a service (any of its names) is listed among a profile's labels.
     *
     * @param  list<string>  $names
     * @param  list<string>  $labels
     */
    public static function listed(array $names, array $labels): bool
    {
        foreach ($labels as $label) {
            foreach ($names as $name) {
                if (mb_strlen($name) >= 3 && (SeoText::containsPhrase($label, $name) || SeoText::containsPhrase($name, $label))) {
                    return true;
                }
            }
        }

        return false;
    }

    /** The brand's city: its first physical branch, else its first service area. */
    public static function city(Brand $brand): string
    {
        $areas = BrandServiceArea::query()->where('brand_id', $brand->id)->where('status', 'active')->orderByDesc('physical_branch')->orderBy('id')->get(['city_name']);

        return mb_substr((string) ($areas->first(fn (BrandServiceArea $a): bool => trim((string) $a->city_name) !== '')?->city_name ?? ''), 0, 80);
    }

    /* ---------------- reading ---------------- */

    /**
     * Cost per result of every brand for services × types of one channel (brands with at least MIN_RESULTS).
     *
     * @param  list<int>|null  $serviceIds  null = all services
     * @return array<string, list<array{brand_id: int, city: string, cost: float, spend: float, results: float}>> "service_id|type" => brands
     */
    public static function brandCosts(string $channel, ?array $serviceIds = null): array
    {
        $out = [];
        $rows = DB::table('ad_service_stats')->where('channel', $channel)->whereNotNull('service_id')
            ->when($serviceIds !== null, fn ($q) => $q->whereIn('service_id', $serviceIds === [] ? [0] : $serviceIds))
            ->groupBy('service_id', 'result_type', 'brand_id', 'city')
            ->selectRaw('service_id, result_type, brand_id, city, sum(spend) as spend, sum(results) as results')->get();
        foreach ($rows as $r) {
            if ((float) $r->results < self::MIN_RESULTS) {
                continue;
            }
            $out[$r->service_id.'|'.$r->result_type][] = ['brand_id' => (int) $r->brand_id, 'city' => (string) $r->city, 'spend' => round((float) $r->spend, 2),
                'results' => round((float) $r->results, 2), 'cost' => round((float) $r->spend / (float) $r->results, 2)];
        }

        return $out;
    }

    /**
     * The average one brand is compared with: median cost of the other brands (same service and type), in the brand's
     * city when MIN_CITY_BRANDS others are there, else in all cities. Null when fewer than 2 other brands have numbers.
     *
     * @param  array<string, list<array{brand_id: int, city: string, cost: float}>>  $costs  brandCosts()
     * @return array{median: float, brands: int, scope: string, leader: array{brand_id: int, cost: float}}|null
     */
    public static function average(array $costs, int $serviceId, string $type, int $brandId, string $city): ?array
    {
        $others = array_values(array_filter($costs[$serviceId.'|'.$type] ?? [], fn (array $r): bool => $r['brand_id'] !== $brandId));
        $local = $city !== '' ? array_values(array_filter($others, fn (array $r): bool => mb_strtolower($r['city']) === mb_strtolower($city))) : [];
        [$pool, $scope] = count($local) >= self::MIN_CITY_BRANDS ? [$local, $city] : [$others, 'tüm şehirler'];
        if (count($pool) < 2) {
            return null;
        }
        usort($pool, fn (array $a, array $b): int => $a['cost'] <=> $b['cost']);

        return ['median' => self::median(array_column($pool, 'cost')), 'brands' => count($pool), 'scope' => $scope,
            'leader' => ['brand_id' => $pool[0]['brand_id'], 'cost' => $pool[0]['cost']]];
    }

    /** @param  list<float>  $values */
    public static function median(array $values): float
    {
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return round($n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2, 2);
    }

    /** better | around | worse against an average (cost: lower is better). */
    public static function verdict(?float $cost, ?float $median): ?string
    {
        if ($cost === null || $median === null || $median <= 0) {
            return null;
        }
        $diff = ($cost - $median) / $median * 100;

        return $diff <= -self::AROUND_PCT ? 'better' : ($diff >= self::AROUND_PCT ? 'worse' : 'around');
    }
}
