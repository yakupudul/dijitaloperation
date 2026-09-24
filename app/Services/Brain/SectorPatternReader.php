<?php

namespace App\Services\Brain;

use App\Enums\CustomerStatus;
use App\Models\Brand;
use App\Support\Options\IndustryOptions;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Sector patterns across the agency's active brands (ADR-066): demand queries that recur in a sector, problems
 * the advisor keeps finding there, and which rules' done advice helped in that sector. Aggregates only: a sector
 * needs at least `min_brands` active brands and a pattern at least `min_brands` distinct brands, and no other
 * brand's name is ever shown next to a pattern. Agency-internal; never in client reports.
 */
final class SectorPatternReader
{
    public function __construct(private readonly RuleEffectiveness $effectiveness) {}

    public static function minBrands(): int
    {
        return max(2, (int) config('moxdop-advisor.sector_patterns.min_brands', 2));
    }

    /** @return list<array{code: string, label: string, brands: int}> sectors with enough active brands */
    public function sectors(): array
    {
        $rows = $this->activeBrands()
            ->whereNotNull('brands.sector')->where('brands.sector', '!=', '')
            ->selectRaw('brands.sector as code, count(*) as brands')
            ->groupBy('brands.sector')
            ->get();

        return $rows->filter(fn (object $row): bool => (int) $row->brands >= self::minBrands())
            ->map(fn (object $row): array => ['code' => (string) $row->code, 'label' => IndustryOptions::label((string) $row->code), 'brands' => (int) $row->brands])
            ->sortByDesc('brands')->values()->all();
    }

    /**
     * @return array{available: bool, sector: string, label: string, brands: int, queries: list<array<string, mixed>>, problems: list<array<string, mixed>>, rules: list<array<string, mixed>>, gaps: list<array<string, mixed>>}
     */
    public function forSector(string $sector, ?int $brandId = null): array
    {
        $brandIds = $this->activeBrands()->where('brands.sector', $sector)->pluck('brands.id')->map(fn ($id): int => (int) $id)->all();
        $base = ['available' => false, 'sector' => $sector, 'label' => IndustryOptions::label($sector), 'brands' => count($brandIds), 'queries' => [], 'problems' => [], 'rules' => [], 'gaps' => []];
        if (count($brandIds) < self::minBrands()) {
            return $base;
        }
        $limit = (int) config('moxdop-advisor.sector_patterns.max_rows', 30);
        $queries = $this->commonQueries($brandIds, $limit);

        return array_merge($base, [
            'available' => true,
            'queries' => $queries,
            'problems' => $this->commonProblems($brandIds),
            'rules' => $this->ruleResults($sector),
            'gaps' => $brandId !== null && in_array($brandId, $brandIds, true) ? $this->gaps($brandId, $brandIds, $limit) : [],
        ]);
    }

    /** Sector of a brand when that sector has enough brands for patterns, else null. */
    public function sectorFor(Brand $brand): ?string
    {
        $sector = (string) $brand->sector;
        if ($sector === '') {
            return null;
        }

        return in_array($sector, array_column($this->sectors(), 'code'), true) ? $sector : null;
    }

    /**
     * @param  list<int>  $brandIds
     * @return list<array{query: string, brands: int, impressions: int, clicks: int, ads_conversions: float}>
     */
    private function commonQueries(array $brandIds, int $limit, ?array $exceptKeys = null): array
    {
        return DB::table('brand_demand_queries')
            ->whereIn('brand_id', $brandIds)
            ->where('is_branded', false)
            ->when($exceptKeys !== null, fn ($q) => $q->whereNotIn('query_key', $exceptKeys))
            ->selectRaw('query_key, min(query) as query, count(distinct brand_id) as brands, sum(gsc_impressions) as impressions, sum(gsc_clicks) as clicks, sum(ads_conversions) as ads_conversions')
            ->groupBy('query_key')
            ->havingRaw('count(distinct brand_id) >= ?', [self::minBrands()])
            ->orderByDesc('brands')->orderByDesc('impressions')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => ['query' => (string) $row->query, 'brands' => (int) $row->brands, 'impressions' => (int) $row->impressions, 'clicks' => (int) $row->clicks, 'ads_conversions' => round((float) $row->ads_conversions, 1)])
            ->all();
    }

    /**
     * Sector queries (seen in ≥ min_brands other brands) the brand does not have in its demand table yet.
     *
     * @param  list<int>  $brandIds
     * @return list<array<string, mixed>>
     */
    private function gaps(int $brandId, array $brandIds, int $limit): array
    {
        $own = DB::table('brand_demand_queries')->where('brand_id', $brandId)->pluck('query_key')->all();
        $others = array_values(array_diff($brandIds, [$brandId]));
        if (count($others) < self::minBrands()) {
            return [];
        }

        return $this->commonQueries($others, $limit, $own);
    }

    /**
     * Rules with open or done advice in at least min_brands brands of the sector.
     *
     * @param  list<int>  $brandIds
     * @return list<array{rule_id: string, channel: string, brands: int, open: int}>
     */
    private function commonProblems(array $brandIds): array
    {
        return DB::table('advisor_items')
            ->whereIn('brand_id', $brandIds)
            ->whereIn('status', ['open', 'done'])
            ->selectRaw("rule_id, channel, count(distinct brand_id) as brands, sum(case when status = 'open' then 1 else 0 end) as open_count")
            ->groupBy('rule_id', 'channel')
            ->havingRaw('count(distinct brand_id) >= ?', [self::minBrands()])
            ->orderByDesc('brands')->orderByDesc('open_count')
            ->limit(20)
            ->get()
            ->map(fn (object $row): array => ['rule_id' => (string) $row->rule_id, 'channel' => (string) $row->channel, 'brands' => (int) $row->brands, 'open' => (int) $row->open_count])
            ->all();
    }

    /** @return list<array{rule_id: string, done: int, measured: int, improved: int, rate: ?int, global_rate: ?int}> */
    private function ruleResults(string $sector): array
    {
        $global = $this->effectiveness->all();
        $out = [];
        foreach ($this->effectiveness->all($sector) as $rule => $row) {
            if ($row['measured'] === 0) {
                continue;
            }
            $g = $global[$rule] ?? null;
            $out[] = $row + [
                'rule_id' => (string) $rule,
                'rate' => (int) round($row['improved'] / $row['measured'] * 100),
                'global_rate' => $g !== null && $g['measured'] > 0 ? (int) round($g['improved'] / $g['measured'] * 100) : null,
            ];
        }
        usort($out, static fn (array $a, array $b): int => [$b['measured'], $b['rate']] <=> [$a['measured'], $a['rate']]);

        return $out;
    }

    private function activeBrands(): Builder
    {
        return DB::table('brands')
            ->join('customers', 'customers.id', '=', 'brands.customer_id')
            ->where('customers.status', CustomerStatus::Active->value);
    }
}
