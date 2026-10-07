<?php

namespace App\Services\Ads;

use App\Models\Brand;
use App\Models\BrandServiceArea;
use App\Models\DigitalAsset;
use App\Services\GoogleAds\GoogleAdsScreen;
use App\Services\Meta\MetaCampaignBoard;
use App\Services\Meta\MetaCampaignServices;
use App\Services\Meta\MetaScreen;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cross-brand ad numbers, rebuilt daily by rules (no AI): every brand service's 30-day spend and results per channel
 * and result type (Meta: campaign → hizmet, an ad's numbers split over the services it names; Google Ads: keyword →
 * hizmet, conversions), and every Meta campaign's 30-day row for Meta masası. Comparisons read the median cost per
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

        $campaigns = [];
        foreach ($board['rows'] as $row) {
            if ($row['spend'] <= 0 && $row['status'] !== 'live') {
                continue;
            }
            $campaigns[] = ['channel' => 'meta', 'digital_asset_id' => $asset->id, 'brand_id' => $brand->id, 'campaign_id' => $row['id'], 'name' => mb_substr($row['name'], 0, 300),
                'status' => $row['status'], 'result_type' => $row['type'], 'spend' => $row['spend'], 'results' => $row['results'], 'cpr' => $row['cpr'], 'prev_cpr' => $row['prev_cpr'],
                'service_state' => $row['service_state'], 'services' => json_encode(array_map(fn (array $s): array => ['id' => $s['id'], 'name' => $s['name'], 'status' => $s['status'],
                    'service_id' => $offerings[$s['id']]['service_id'] ?? null], $row['services']), JSON_UNESCAPED_UNICODE),
                'alerts' => json_encode($row['alerts'], JSON_UNESCAPED_UNICODE), 'currency' => $currency !== '' ? mb_substr($currency, 0, 8) : null, 'period_end' => $end];
        }

        // Service numbers: each ad's spend and the results of its campaign's type, split over the ad's services.
        $entities = $this->screen->entities($account);
        $map = $this->services->map($asset);
        $types = array_column($board['rows'], 'type', 'id');
        $ads = $this->screen->adPerformance($account, $board['window']['from'], $end, $entities);
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
            }
        }

        return $this->replace($asset, 'meta', $this->serviceRows($asset, $brand, $city, $end, $sum, $offerings), $campaigns);
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
                $sum[$offerings[$serviceId]['id']]['conversions'] = ['spend' => $row['spend'], 'results' => $row['conversions']];
            }
        }

        return $this->replace($asset, 'google_ads', $this->serviceRows($asset, $brand, self::city($brand), $totals['period_end'], $sum, array_column($offerings, null, 'id')), null);
    }

    /**
     * @param  array<int, array<string, array{spend: float, results: float}>>  $sum  offering id => type => numbers
     * @param  array<int, array<string, mixed>>  $offerings  offering id => offering
     * @return list<array<string, mixed>>
     */
    private function serviceRows(DigitalAsset $asset, Brand $brand, string $city, string $end, array $sum, array $offerings): array
    {
        $rows = [];
        foreach ($sum as $offeringId => $types) {
            foreach ($types as $type => $n) {
                if ($n['spend'] <= 0 && $n['results'] <= 0) {
                    continue;
                }
                $rows[] = ['channel' => $asset->type === 'meta_ads' ? 'meta' : 'google_ads', 'digital_asset_id' => $asset->id, 'brand_id' => $brand->id, 'brand_offering_id' => $offeringId,
                    'service_id' => $offerings[$offeringId]['service_id'] ?? null, 'sector_id' => $brand->sector_id, 'city' => $city, 'result_type' => $type,
                    'spend' => round($n['spend'], 2), 'results' => round($n['results'], 2), 'period_end' => $end];
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
