<?php

namespace App\Services\Demand;

use App\Models\Brand;
use App\Models\BrandDemandQuery;
use App\Models\BrandDemandQueryAsset;
use App\Models\BrandOffering;
use App\Models\DigitalAsset;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Read (and operator review) API of the brand query hub — one store per brand, built by BrandDemandBuilder. The
 * clustering → content phase consumes rowsFor(); the brand page review table uses query() / serviceCounts() and the
 * review actions (assign / markIrrelevant / confirm), whose decisions win over every rebuild.
 *
 * Filters (all optional):
 *  - source:    one of BrandDemandQuery::SOURCE_BITS keys (search_console, google_ads, google_business_profile,
 *               portfolio, competitor, area_serp)
 *  - offering:  a brand offering id, or 'none' for queries without a service
 *  - relevance: 'relevant' | 'unclear' | 'irrelevant' | 'all'; omitted = everything except 'irrelevant'
 *  - branded:   true / false (or '1' / '0')
 *  - search:    text contained in the query (case-insensitive)
 *
 * With a website ($site, one of the brand's assets) the rows are the queries seen on that site's Search Console plus
 * brand-wide queries with no site-specific data (Ads, Business Profile, portfolio, SERP); queries seen only on another
 * website of the brand are left out, and each row carries the site's own Search Console metrics.
 */
final class BrandQueryHub
{
    /**
     * Hub rows, most valuable first.
     *
     * @param  array{source?: string|null, offering?: int|string|null, relevance?: string|null, branded?: bool|string|null, search?: string|null}  $filters
     * @return list<array{
     *     id: int,
     *     query: string,
     *     query_key: string,
     *     sources: list<string>,
     *     offering_id: int|null,
     *     offering_name: string|null,
     *     catalog_service_id: int|null,
     *     assignment_source: string,
     *     assignment_method: string|null,
     *     assignment_confidence: float|null,
     *     sector: string|null,
     *     intent: string|null,
     *     relevance: string,
     *     is_branded: bool,
     *     gsc: array{clicks: int, impressions: int, position: float|null},
     *     ads: array{impressions: int, clicks: int, cost: float, conversions: float},
     *     gbp_impressions: int,
     *     search_volume: int|null,
     *     serp_rank: int|null,
     *     competitor_count: int,
     *     trend: array{recent_impressions: int, previous_impressions: int, direction: string|null},
     *     first_observed_on: string|null,
     *     last_observed_on: string|null,
     *     value_score: float,
     *     library_item_id: int|null,
     *     portfolio_item_id: int|null,
     *     site: array{digital_asset_id: int, clicks: int, impressions: int, position: float|null}|null
     * }>
     */
    public function rowsFor(Brand $brand, ?DigitalAsset $site = null, array $filters = [], int $limit = 5000): array
    {
        $rows = $this->query($brand, $site, $filters)
            ->with(['offering.primaryName', 'offering.catalogItem.primaryName'])
            ->limit(max(1, $limit))->get();
        $siteMetrics = $site === null ? collect() : BrandDemandQueryAsset::query()
            ->where('digital_asset_id', $site->id)->whereIn('brand_demand_query_id', $rows->pluck('id'))->get()->keyBy('brand_demand_query_id');

        return $rows->map(function (BrandDemandQuery $row) use ($siteMetrics): array {
            $metrics = $siteMetrics->get($row->id);

            return [
                'id' => (int) $row->id,
                'query' => (string) $row->query,
                'query_key' => (string) $row->query_key,
                'sources' => array_values((array) $row->sources),
                'offering_id' => $row->brand_offering_id !== null ? (int) $row->brand_offering_id : null,
                'offering_name' => $row->offering?->displayName(),
                'catalog_service_id' => $row->offering?->service_catalog_item_id !== null ? (int) $row->offering->service_catalog_item_id : null,
                'assignment_source' => (string) $row->assignment_source,
                'assignment_method' => $row->assignment_method,
                'assignment_confidence' => $row->assignment_confidence,
                'sector' => $row->sector,
                'intent' => $row->intent,
                'relevance' => (string) $row->relevance,
                'is_branded' => (bool) $row->is_branded,
                'gsc' => ['clicks' => (int) $row->gsc_clicks, 'impressions' => (int) $row->gsc_impressions, 'position' => $row->gsc_position],
                'ads' => ['impressions' => (int) $row->ads_impressions, 'clicks' => (int) $row->ads_clicks, 'cost' => (float) $row->ads_cost, 'conversions' => (float) $row->ads_conversions],
                'gbp_impressions' => (int) $row->gbp_impressions,
                'search_volume' => $row->search_volume !== null ? (int) $row->search_volume : null,
                'serp_rank' => $row->serp_rank !== null ? (int) $row->serp_rank : null,
                'competitor_count' => (int) $row->competitor_count,
                'trend' => ['recent_impressions' => (int) $row->recent_impressions, 'previous_impressions' => (int) $row->previous_impressions, 'direction' => $row->trend()],
                'first_observed_on' => $row->first_observed_on?->toDateString(),
                'last_observed_on' => $row->last_observed_on?->toDateString(),
                'value_score' => (float) $row->value_score,
                'library_item_id' => $row->search_query_library_item_id !== null ? (int) $row->search_query_library_item_id : null,
                'portfolio_item_id' => $row->brand_query_portfolio_item_id !== null ? (int) $row->brand_query_portfolio_item_id : null,
                'site' => $metrics === null ? null : [
                    'digital_asset_id' => (int) $metrics->digital_asset_id, 'clicks' => (int) $metrics->gsc_clicks,
                    'impressions' => (int) $metrics->gsc_impressions, 'position' => $metrics->gsc_position,
                ],
            ];
        })->values()->all();
    }

    /**
     * Filtered, ordered hub query (for pagination).
     *
     * @param  array{source?: string|null, offering?: int|string|null, relevance?: string|null, branded?: bool|string|null, search?: string|null}  $filters
     * @return Builder<BrandDemandQuery>
     */
    public function query(Brand $brand, ?DigitalAsset $site = null, array $filters = []): Builder
    {
        return $this->filtered($brand, $site, $filters)
            ->orderByDesc('value_score')->orderByDesc('gsc_impressions')->orderByDesc('search_volume')->orderBy('id');
    }

    /**
     * Row count per service under the same filters (except the service filter): offering id => count, 0 = no service.
     *
     * @param  array{source?: string|null, relevance?: string|null, branded?: bool|string|null, search?: string|null}  $filters
     * @return array<int, int>
     */
    public function serviceCounts(Brand $brand, ?DigitalAsset $site = null, array $filters = []): array
    {
        unset($filters['offering']);

        return $this->filtered($brand, $site, $filters)->toBase()
            ->selectRaw('coalesce(brand_offering_id, 0) as offering_id, count(*) as total')
            ->groupByRaw('coalesce(brand_offering_id, 0)')->pluck('total', 'offering_id')
            ->mapWithKeys(fn ($total, $id): array => [(int) $id => (int) $total])->all();
    }

    /**
     * Row count per relevance under the same filters (except the relevance filter).
     *
     * @param  array{source?: string|null, offering?: int|string|null, branded?: bool|string|null, search?: string|null}  $filters
     * @return array<string, int>
     */
    public function relevanceCounts(Brand $brand, ?DigitalAsset $site = null, array $filters = []): array
    {
        $filters['relevance'] = 'all';

        return $this->filtered($brand, $site, $filters)->toBase()
            ->selectRaw('relevance, count(*) as total')->groupBy('relevance')->pluck('total', 'relevance')
            ->map(fn ($total): int => (int) $total)->all();
    }

    /**
     * Operator: put the queries under a service (or take them out of every service with null). Wins over rebuilds.
     *
     * @param  list<int>  $ids
     */
    public function assign(Brand $brand, array $ids, ?BrandOffering $offering, ?User $actor): int
    {
        if ($offering !== null && (int) $offering->brand_id !== (int) $brand->id) {
            throw new InvalidArgumentException('Offering does not belong to the brand.');
        }

        return $this->review($brand, $ids, $actor, $offering === null
            ? ['brand_offering_id' => null, 'assignment_method' => BrandDemandQuery::METHOD_OPERATOR, 'assignment_confidence' => 1.0]
            : [
                'brand_offering_id' => $offering->id, 'assignment_method' => BrandDemandQuery::METHOD_OPERATOR, 'assignment_confidence' => 1.0,
                'relevance' => BrandDemandQuery::RELEVANT, 'relevance_source' => BrandDemandQuery::SOURCE_OPERATOR,
                'sector' => $offering->catalogItem?->sector,
            ]);
    }

    /**
     * Operator: "alakasız" — kept, taken out of every service, excluded from rowsFor() by default.
     *
     * @param  list<int>  $ids
     */
    public function markIrrelevant(Brand $brand, array $ids, ?User $actor): int
    {
        return $this->review($brand, $ids, $actor, [
            'brand_offering_id' => null, 'assignment_method' => BrandDemandQuery::METHOD_OPERATOR, 'assignment_confidence' => 1.0,
            'relevance' => BrandDemandQuery::IRRELEVANT, 'relevance_source' => BrandDemandQuery::SOURCE_OPERATOR,
        ]);
    }

    /**
     * Operator: accept the current service (or "no service but relevant") so rebuilds keep it.
     *
     * @param  list<int>  $ids
     */
    public function confirm(Brand $brand, array $ids, ?User $actor): int
    {
        $count = 0;
        BrandDemandQuery::query()->where('brand_id', $brand->id)->whereIn('id', $ids)->get()
            ->each(function (BrandDemandQuery $row) use ($actor, &$count): void {
                $row->forceFill([
                    'assignment_source' => BrandDemandQuery::SOURCE_OPERATOR,
                    'relevance' => $row->relevance === BrandDemandQuery::UNCLEAR ? BrandDemandQuery::RELEVANT : $row->relevance,
                    'relevance_source' => BrandDemandQuery::SOURCE_OPERATOR,
                    'reviewed_at' => now(), 'reviewed_by' => $actor?->id,
                ])->save();
                $count++;
            });

        return $count;
    }

    /**
     * @param  list<int>  $ids
     * @param  array<string, mixed>  $attributes
     */
    private function review(Brand $brand, array $ids, ?User $actor, array $attributes): int
    {
        return BrandDemandQuery::query()->where('brand_id', $brand->id)->whereIn('id', $ids)->update([
            ...$attributes,
            'assignment_source' => BrandDemandQuery::SOURCE_OPERATOR,
            'reviewed_at' => now(), 'reviewed_by' => $actor?->id, 'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<BrandDemandQuery>
     */
    private function filtered(Brand $brand, ?DigitalAsset $site, array $filters): Builder
    {
        if ($site !== null && (int) $site->brand_id !== (int) $brand->id) {
            throw new InvalidArgumentException('Website does not belong to the brand.');
        }
        $query = BrandDemandQuery::query()->where('brand_id', $brand->id);
        if ($site !== null) {
            $query->where(fn (Builder $q) => $q
                ->whereExists(fn ($e) => $e->from('brand_demand_query_assets as a')->whereColumn('a.brand_demand_query_id', 'brand_demand_queries.id')->where('a.digital_asset_id', $site->id))
                ->orWhereNotExists(fn ($e) => $e->from('brand_demand_query_assets as a')->whereColumn('a.brand_demand_query_id', 'brand_demand_queries.id')));
        }
        $source = (string) ($filters['source'] ?? '');
        if (isset(BrandDemandQuery::SOURCE_BITS[$source])) {
            $query->fromSource($source);
        }
        $offering = $filters['offering'] ?? null;
        if ($offering === 'none') {
            $query->whereNull('brand_offering_id');
        } elseif ($offering !== null && $offering !== '') {
            $query->where('brand_offering_id', (int) $offering);
        }
        $relevance = (string) ($filters['relevance'] ?? '');
        if (in_array($relevance, [BrandDemandQuery::RELEVANT, BrandDemandQuery::UNCLEAR, BrandDemandQuery::IRRELEVANT], true)) {
            $query->where('relevance', $relevance);
        } elseif ($relevance !== 'all') {
            $query->where('relevance', '!=', BrandDemandQuery::IRRELEVANT);
        }
        $branded = $filters['branded'] ?? null;
        if ($branded !== null && $branded !== '') {
            $query->where('is_branded', filter_var($branded, FILTER_VALIDATE_BOOLEAN));
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->whereRaw('lower(query) like ?', ['%'.mb_strtolower($search, 'UTF-8').'%']);
        }

        return $query;
    }
}
