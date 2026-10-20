<?php

namespace App\Mcp\Tools;

use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Services\Brand\BrandScope;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Read-only Meta performance of one brand from the stored daily results (meta_geo_results_daily country rows,
 * meta_breakdown_results_daily), so Claude answers "how do this brand's Meta ads do" at once instead of asking the
 * operator for screens or SQL. Leads, messages and purchases stay separate (never one mixed "result"); each has its
 * own cost. Ad set targeting and ad statuses come from the latest snapshots of the brand's own ad accounts.
 */
#[Description('Reads one brand\'s Meta ads performance from stored data (no live Meta call): totals and rows grouped by month, campaign, adset, ad, country, region, campaign_country, age_gender, placement, device or hour, with spend, impressions, clicks, CTR, leads, messages, purchases and the cost of each (result types are never added together). Also the ad set targeting (countries, cities, ages, languages, audiences, interests) and ad statuses. Country data starts when the daily geo collection started for the account; `accounts` shows each account\'s first and last day.')]
#[IsReadOnly]
class MetaPerformance extends Tool
{
    /** @var list<string> */
    public const array GROUPS = ['month', 'campaign', 'adset', 'ad', 'country', 'region', 'campaign_country', 'age_gender', 'placement', 'device', 'hour'];

    /** @var list<string> */
    private const array DEFAULT_GROUPS = ['month', 'campaign', 'country', 'age_gender', 'placement'];

    private const string GEO = 'meta_geo_results_daily';

    private const string BREAKDOWN = 'meta_breakdown_results_daily';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'brand_id' => ['required', 'integer'],
            'days' => ['nullable', 'integer', 'min:1', 'max:730'],
            'by' => ['nullable', 'array'],
            'by.*' => ['string', 'in:'.implode(',', self::GROUPS)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'targeting' => ['nullable', 'boolean'],
        ]);
        $brand = Brand::query()->operational()->find($validated['brand_id']);
        if ($brand === null) {
            return Response::error('Marka bulunamadı ya da aktif müşteri değil.');
        }
        if (! Schema::hasTable(self::GEO)) {
            return Response::error('Meta sonuç tablosu yok.');
        }

        $assetIds = BrandScope::assetIds((int) $brand->id);
        $resourceIds = BrandScope::resources((int) $brand->id, 'meta_ads');
        $to = CarbonImmutable::now()->subDay()->toDateString();
        $from = CarbonImmutable::now()->subDays((int) ($validated['days'] ?? 90))->toDateString();
        $limit = (int) ($validated['limit'] ?? 25);
        $groups = array_values(array_unique($validated['by'] ?? self::DEFAULT_GROUPS));

        $geo = fn (): Builder => DB::table(self::GEO)->whereIn('digital_asset_id', $assetIds)->where('level', 'country')->whereBetween('reporting_date', [$from, $to]);

        $result = [
            'brand' => ['id' => (int) $brand->id, 'name' => (string) $brand->name],
            'period' => ['from' => $from, 'to' => $to],
            'currency' => $geo()->whereNotNull('currency')->value('currency'),
            'accounts' => $this->accounts($assetIds),
            'totals' => $this->row((array) ($geo()->selectRaw($this->sums())->first() ?? [])),
            'groups' => [],
            'note' => 'Sonuç türleri ayrı tutulur: form (leads), mesaj (messages), satın alma (purchases). Ülke ve bölge verisi Meta\'nın gösterime göre atadığı yerdir, kişinin uyruğu değil.',
        ];

        foreach ($groups as $group) {
            $result['groups'][$group] = $this->group($group, $geo, $assetIds, $from, $to, $limit);
        }

        if ($validated['targeting'] ?? true) {
            $result['targeting'] = $this->targeting($resourceIds, $limit);
            $result['ad_status'] = $this->adStatus($resourceIds);
        }

        return Response::json($result);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'brand_id' => $schema->integer()->description('Brand id from list-brands.')->required(),
            'days' => $schema->integer()->description('Days back from yesterday (default 90, max 730).'),
            'by' => $schema->array()->items($schema->string()->enum(self::GROUPS))
                ->description('Groupings (default month, campaign, country, age_gender, placement).'),
            'limit' => $schema->integer()->description('Rows per grouping, highest spend first (default 25, max 200).'),
            'targeting' => $schema->boolean()->description('Include ad set targeting and ad statuses (default true).'),
        ];
    }

    /**
     * @param  callable(): Builder  $geo
     * @param  list<int>  $assetIds
     * @return list<array<string, mixed>>
     */
    private function group(string $group, callable $geo, array $assetIds, string $from, string $to, int $limit): array
    {
        if (in_array($group, ['age_gender', 'placement', 'device', 'hour'], true)) {
            if (! Schema::hasTable(self::BREAKDOWN)) {
                return [];
            }
            $query = DB::table(self::BREAKDOWN)->whereIn('digital_asset_id', $assetIds)->where('dimension', $group)
                ->whereBetween('reporting_date', [$from, $to])->groupBy('key1', 'key2')->selectRaw('key1, key2, '.$this->sums());
            $keys = match ($group) {
                'age_gender' => ['age', 'gender'],
                'placement' => ['platform', 'position'],
                default => [$group, null],
            };
            $rows = $group === 'hour' ? $query->orderBy('key1')->get() : $query->orderByRaw('sum(spend) desc')->limit($limit)->get();

            return $rows->map(function (object $r) use ($keys): array {
                $labels = [$keys[0] => (string) $r->key1];
                if ($keys[1] !== null) {
                    $labels[$keys[1]] = (string) $r->key2;
                }

                return $labels + $this->row((array) $r);
            })->all();
        }

        if ($group === 'month') {
            $months = [];
            foreach ($geo()->groupBy('reporting_date')->selectRaw('reporting_date, '.$this->sums())->get() as $day) {
                $month = substr((string) $day->reporting_date, 0, 7);
                foreach (['spend', 'impressions', 'clicks', 'leads', 'messages', 'purchases', 'purchase_value'] as $field) {
                    $months[$month][$field] = ($months[$month][$field] ?? 0) + (float) $day->{$field};
                }
            }
            ksort($months);

            return array_map(fn (string $month, array $sums): array => ['month' => $month] + $this->row($sums), array_keys($months), $months);
        }

        $columns = match ($group) {
            'campaign' => ['campaign_id', 'campaign_name'],
            'adset' => ['adset_id', 'adset_name', 'campaign_name'],
            'ad' => ['ad_id', 'ad_name', 'adset_name', 'campaign_name'],
            'country' => ['country'],
            'campaign_country' => ['campaign_name', 'country'],
            'region' => ['country', 'region'],
        };
        $query = $group === 'region'
            ? DB::table(self::GEO)->whereIn('digital_asset_id', $assetIds)->where('level', 'region')->whereBetween('reporting_date', [$from, $to])
            : $geo();
        $rows = $query->groupBy(...$columns)
            ->selectRaw(implode(', ', $columns).', min(reporting_date) as first_day, max(reporting_date) as last_day, '.$this->sums())
            ->orderByRaw('sum(spend) desc')->limit($limit)->get();

        return $rows->map(function (object $r) use ($columns): array {
            $labels = [];
            foreach ($columns as $column) {
                $labels[$column] = (string) $r->{$column};
            }

            return $labels + ['first_day' => (string) $r->first_day, 'last_day' => (string) $r->last_day] + $this->row((array) $r);
        })->all();
    }

    private function sums(): string
    {
        return 'sum(spend) as spend, sum(impressions) as impressions, sum(clicks) as clicks, sum(leads) as leads, '
            .'sum(messages) as messages, sum(purchases) as purchases, sum(purchase_value) as purchase_value';
    }

    /**
     * @param  array<string, mixed>  $sums
     * @return array<string, int|float|null>
     */
    private function row(array $sums): array
    {
        $spend = round((float) ($sums['spend'] ?? 0), 2);
        $impressions = (int) ($sums['impressions'] ?? 0);
        $clicks = (int) ($sums['clicks'] ?? 0);
        $row = ['spend' => $spend, 'impressions' => $impressions, 'clicks' => $clicks, 'ctr' => $impressions > 0 ? round(100 * $clicks / $impressions, 2) : null];
        foreach (['leads' => 'cost_per_lead', 'messages' => 'cost_per_message', 'purchases' => 'cost_per_purchase'] as $field => $cost) {
            $count = round((float) ($sums[$field] ?? 0), 1);
            $row[$field] = $count;
            $row[$cost] = $count > 0 ? round($spend / $count, 2) : null;
        }
        if ((float) ($sums['purchase_value'] ?? 0) > 0) {
            $row['purchase_value'] = round((float) $sums['purchase_value'], 2);
        }

        return $row;
    }

    /**
     * @param  list<int>  $assetIds
     * @return list<array<string, mixed>>
     */
    private function accounts(array $assetIds): array
    {
        return DigitalAsset::query()->whereIn('id', $assetIds)->where('type', 'meta_ads')->orderBy('id')->get(['id', 'name'])
            ->map(function (DigitalAsset $asset): array {
                $days = DB::table(self::GEO)->where('digital_asset_id', $asset->id)->where('level', 'country')
                    ->selectRaw('min(reporting_date) as first_day, max(reporting_date) as last_day, sum(spend) as spend')->first();

                return [
                    'asset_id' => (int) $asset->id,
                    'name' => (string) $asset->name,
                    'first_day' => $days?->first_day,
                    'last_day' => $days?->last_day,
                    'spend_all_time' => round((float) ($days?->spend ?? 0), 2),
                ];
            })->all();
    }

    /**
     * Latest targeting of the brand's ad sets, ad sets with a running ad first.
     *
     * @param  list<int>  $resourceIds
     * @return list<array<string, mixed>>
     */
    private function targeting(array $resourceIds, int $limit): array
    {
        if ($resourceIds === [] || ! Schema::hasTable('meta_adset_targeting_snapshot')) {
            return [];
        }
        $active = Schema::hasTable('meta_ad_snapshot')
            ? DB::table('meta_ad_snapshot')->whereIn('external_resource_id', $resourceIds)->where('effective_status', 'ACTIVE')->distinct()->pluck('adset_id')->map(fn ($id): string => (string) $id)->all()
            : [];

        return DB::table('meta_adset_targeting_snapshot')->whereIn('external_resource_id', $resourceIds)
            ->orderByDesc('last_collected_at')->limit(300)->get(['adset_id', 'campaign_id', 'adset_name', 'optimization_goal', 'targeting'])
            ->map(function (object $row) use ($active): array {
                $t = is_string($row->targeting) ? (array) json_decode($row->targeting, true) : (array) $row->targeting;
                $geo = (array) ($t['geo_locations'] ?? []);
                $excluded = (array) ($t['excluded_geo_locations'] ?? []);
                $names = fn (array $items): array => array_values(array_filter(array_map(fn ($i): string => is_array($i) ? (string) ($i['name'] ?? $i['key'] ?? '') : (string) $i, $items)));
                $interests = [];
                foreach ((array) ($t['flexible_spec'] ?? []) as $spec) {
                    foreach ((array) $spec as $kind => $items) {
                        foreach ((array) $items as $item) {
                            $interests[] = $kind.': '.(is_array($item) ? (string) ($item['name'] ?? $item['id'] ?? '') : (string) $item);
                        }
                    }
                }

                return [
                    'adset_id' => (string) $row->adset_id,
                    'adset_name' => (string) $row->adset_name,
                    'campaign_id' => (string) $row->campaign_id,
                    'running' => in_array((string) $row->adset_id, $active, true),
                    'optimization_goal' => $row->optimization_goal,
                    'countries' => array_values((array) ($geo['countries'] ?? [])),
                    'regions' => $names((array) ($geo['regions'] ?? [])),
                    'cities' => array_slice($names((array) ($geo['cities'] ?? [])), 0, 20),
                    'location_types' => array_values((array) ($geo['location_types'] ?? [])),
                    'excluded' => array_merge(array_values((array) ($excluded['countries'] ?? [])), $names((array) ($excluded['regions'] ?? [])), $names((array) ($excluded['cities'] ?? []))),
                    'age_min' => $t['age_min'] ?? null,
                    'age_max' => $t['age_max'] ?? null,
                    'age_suggestion' => $t['age_range'] ?? null,
                    'genders' => array_values((array) ($t['genders'] ?? [])),
                    'locales' => array_values((array) ($t['locales'] ?? [])),
                    'custom_audiences' => $names((array) ($t['custom_audiences'] ?? [])),
                    'excluded_audiences' => $names((array) ($t['excluded_custom_audiences'] ?? [])),
                    'interests' => array_slice($interests, 0, 30),
                    'advantage_audience' => data_get($t, 'targeting_automation.advantage_audience'),
                    'platforms' => array_values((array) ($t['publisher_platforms'] ?? [])),
                ];
            })
            ->sortByDesc('running')->take($limit)->values()->all();
    }

    /**
     * @param  list<int>  $resourceIds
     * @return array<string, int>
     */
    private function adStatus(array $resourceIds): array
    {
        if ($resourceIds === [] || ! Schema::hasTable('meta_ad_snapshot')) {
            return [];
        }

        return DB::table('meta_ad_snapshot')->whereIn('external_resource_id', $resourceIds)
            ->groupBy('effective_status')->selectRaw('effective_status, count(*) as ads')->orderByRaw('count(*) desc')->get()
            ->mapWithKeys(fn (object $r): array => [(string) ($r->effective_status ?? 'bilinmiyor') => (int) $r->ads])->all();
    }
}
