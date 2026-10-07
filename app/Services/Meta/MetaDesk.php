<?php

namespace App\Services\Meta;

use App\Models\Brand;
use App\Models\ServiceCatalogItem;
use App\Services\Ads\AdServiceStats;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Meta masası: every brand's Meta campaigns in one place, from the daily 30-day rows (AdServiceStats → ad_campaign_stats).
 * Totals, what to look at today, the service averages across brands and the campaign list with each campaign's
 * distance to its service average. Read-only toward Meta.
 */
class MetaDesk
{
    /** "Bugün bakılacaklar": at most this many. */
    public const int TODAY_LIMIT = 7;

    /** A campaign this much (%) dearer than its service average counts as "maliyet yüksek". */
    public const float HIGH_COST_PCT = 30.0;

    /** @var array<string, string> */
    public const array ALERTS = ['high_cost' => 'Maliyet yüksek', 'cost_up' => 'Maliyet arttı', 'stopped' => 'Harcama durdu', 'no_service' => 'Hizmet yok',
        'disapproved' => 'Reddedilen reklam', 'fatigue' => 'Kreatif yoruldu'];

    /** @var array<string, string> */
    public const array SORTS = ['worst' => 'Ortalamadan en kötü', 'spend' => 'Harcama', 'results' => 'Sonuç'];

    /**
     * @param  array{sector?: string, service?: string, brand?: string, alert?: string, sort?: string}  $filters
     * @return array<string, mixed>
     */
    public function desk(array $filters): array
    {
        if (! Schema::hasTable('ad_campaign_stats')) {
            return ['ready' => false];
        }
        $brands = Brand::query()->with('sectorCategory')->whereIn('id', DB::table('ad_campaign_stats')->where('channel', 'meta')->distinct()->pluck('brand_id'))->get()->keyBy('id');
        $costs = AdServiceStats::brandCosts('meta');
        $cities = $brands->map(fn (Brand $b): string => AdServiceStats::city($b))->all();
        $serviceNames = self::serviceNames(array_map(fn (string $k): int => (int) explode('|', $k)[0], array_keys($costs)));

        $rows = [];
        foreach (DB::table('ad_campaign_stats')->where('channel', 'meta')->get() as $r) {
            $brand = $brands[$r->brand_id] ?? null;
            if ($brand === null) {
                continue;
            }
            $services = (array) json_decode((string) $r->services, true);
            $alerts = array_column((array) json_decode((string) $r->alerts, true), 'key');
            $serviceId = isset($services[0]['service_id']) ? (int) $services[0]['service_id'] : null;
            $cpr = $r->cpr !== null ? (float) $r->cpr : null;
            $average = $serviceId !== null && in_array($r->result_type, ['leads', 'messages', 'purchases'], true)
                ? AdServiceStats::average($costs, $serviceId, (string) $r->result_type, (int) $r->brand_id, $cities[$r->brand_id] ?? '') : null;
            $diff = $average !== null ? MetaScreen::change($cpr, $average['median']) : null;
            if ($diff !== null && $diff >= self::HIGH_COST_PCT && (float) $r->results >= AdServiceStats::MIN_RESULTS) {
                array_unshift($alerts, 'high_cost');
            }
            $rows[] = ['brand_id' => (int) $r->brand_id, 'brand' => (string) $brand->name, 'sector_id' => $brand->sector_id, 'asset_id' => (int) $r->digital_asset_id,
                'campaign_id' => (string) $r->campaign_id, 'name' => (string) $r->name, 'status' => (string) $r->status, 'type' => (string) $r->result_type,
                'spend' => (float) $r->spend, 'results' => (float) $r->results, 'cpr' => $cpr, 'currency' => (string) $r->currency,
                'service_state' => (string) $r->service_state, 'services' => $services, 'service_ids' => array_values(array_filter(array_column($services, 'service_id'))),
                'average' => $average, 'diff' => $diff, 'alerts' => array_values(array_unique($alerts))];
        }

        $kpis = ['brands' => count(array_unique(array_column($rows, 'brand_id'))), 'campaigns' => count($rows),
            'live' => count(array_filter($rows, fn (array $r): bool => $r['status'] === 'live')),
            'spend' => array_sum(array_column($rows, 'spend')),
            'leads' => array_sum(array_map(fn (array $r): float => $r['type'] === 'leads' ? $r['results'] : 0.0, $rows)),
            'messages' => array_sum(array_map(fn (array $r): float => $r['type'] === 'messages' ? $r['results'] : 0.0, $rows)),
            'no_service' => count(array_filter($rows, fn (array $r): bool => in_array('no_service', $r['alerts'], true))),
            'currencies' => array_values(array_unique(array_filter(array_column($rows, 'currency'))))];

        $today = array_values(array_filter($rows, fn (array $r): bool => array_intersect($r['alerts'], ['high_cost', 'cost_up', 'stopped', 'disapproved']) !== []));
        usort($today, fn (array $a, array $b): int => $b['spend'] <=> $a['spend']);
        $kpis['today'] = count($today);

        $filtered = array_values(array_filter($rows, function (array $r) use ($filters): bool {
            if (($filters['sector'] ?? '') !== '' && (string) $r['sector_id'] !== $filters['sector']) {
                return false;
            }
            if (($filters['brand'] ?? '') !== '' && (string) $r['brand_id'] !== $filters['brand']) {
                return false;
            }
            if (($filters['service'] ?? '') === 'none' && $r['services'] !== []) {
                return false;
            }
            if (! in_array($filters['service'] ?? '', ['', 'none'], true) && ! in_array((int) $filters['service'], array_map('intval', $r['service_ids']), true)) {
                return false;
            }

            return ($filters['alert'] ?? '') === '' || in_array($filters['alert'], $r['alerts'], true);
        }));
        $sort = $filters['sort'] ?? 'worst';
        usort($filtered, fn (array $a, array $b): int => match ($sort) {
            'spend' => $b['spend'] <=> $a['spend'],
            'results' => $b['results'] <=> $a['results'],
            default => [$b['diff'] === null ? 0 : 1, $b['diff'] ?? 0, $b['spend']] <=> [$a['diff'] === null ? 0 : 1, $a['diff'] ?? 0, $a['spend']],
        });

        return [
            'ready' => true,
            'kpis' => $kpis,
            'today' => array_slice($today, 0, self::TODAY_LIMIT),
            'averages' => $this->averages($costs, $serviceNames, Brand::query()->whereIn('id', array_unique(array_merge(...array_map(fn (array $b): array => array_column($b, 'brand_id'), array_values($costs) ?: [[]]))))->pluck('name', 'id')->all()),
            'rows' => array_slice($filtered, 0, 300),
            'total' => count($filtered),
            'options' => [
                'sectors' => $brands->filter(fn (Brand $b): bool => $b->sectorCategory !== null)->mapWithKeys(fn (Brand $b): array => [(string) $b->sector_id => (string) $b->sectorCategory->name])->sort()->all(),
                'services' => collect($rows)->flatMap(fn (array $r): array => array_map(fn (array $s): array => ['id' => $s['service_id'] ?? null, 'name' => $s['name']], $r['services']))
                    ->filter(fn (array $s): bool => $s['id'] !== null)->unique('id')->sortBy('name')->pluck('name', 'id')->all(),
                'brands' => $brands->sortBy('name')->pluck('name', 'id')->all(),
            ],
            'period_end' => DB::table('ad_campaign_stats')->where('channel', 'meta')->max('period_end'),
        ];
    }

    /**
     * Service averages across brands: per catalog service, the median form / mesaj cost and the cheapest brand.
     *
     * @param  array<string, list<array{brand_id: int, cost: float}>>  $costs
     * @param  array<int, string>  $names
     * @param  array<int, string>  $brandNames
     * @return list<array{service_id: int, name: string, brands: int, leads: ?float, messages: ?float, leader: ?string, leader_cost: ?float}>
     */
    private function averages(array $costs, array $names, array $brandNames): array
    {
        $out = [];
        foreach ($costs as $key => $brands) {
            [$serviceId, $type] = explode('|', $key);
            if (! in_array($type, ['leads', 'messages'], true) || count($brands) < 2) {
                continue;
            }
            $out[$serviceId] ??= ['service_id' => (int) $serviceId, 'name' => $names[(int) $serviceId] ?? 'Hizmet #'.$serviceId, 'brands' => 0, 'leads' => null, 'messages' => null, 'leader' => null, 'leader_cost' => null];
            $out[$serviceId][$type] = AdServiceStats::median(array_column($brands, 'cost'));
            $out[$serviceId]['brands'] = max($out[$serviceId]['brands'], count($brands));
            if ($type === 'leads' || $out[$serviceId]['leader'] === null) {
                usort($brands, fn (array $a, array $b): int => $a['cost'] <=> $b['cost']);
                $out[$serviceId]['leader'] = $brandNames[$brands[0]['brand_id']] ?? null;
                $out[$serviceId]['leader_cost'] = $brands[0]['cost'];
                $out[$serviceId]['leader_type'] = $type;
            }
        }
        usort($out, fn (array $a, array $b): int => [$b['brands'], $a['name']] <=> [$a['brands'], $b['name']]);

        return array_values($out);
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    public static function serviceNames(array $ids): array
    {
        return ServiceCatalogItem::query()->with('primaryName')->whereIn('id', array_values(array_unique($ids)) ?: [0])->get()
            ->mapWithKeys(fn (ServiceCatalogItem $item): array => [(int) $item->id => (string) ($item->primaryName?->raw_label ?? 'Hizmet #'.$item->id)])->all();
    }
}
