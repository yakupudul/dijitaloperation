<?php

namespace App\Services\Demand;

use App\Models\Brand;
use App\Services\Measurement\BrandMeasurementScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Branded vs non-branded demand per month: Search Console clicks and Google Ads cost / conversions, split by
 * the brand's branded-query test. Non-branded growth is what SEO and generic ads create; branded demand is
 * people who already know the brand. Search Console hides rare queries, so shares are of the queries it
 * reports.
 */
final class BrandedSplitReader
{
    /**
     * @return list<array{month: string, gsc_branded: int, gsc_non_branded: int, ads_cost_branded: float, ads_cost_non_branded: float, ads_conv_branded: float, ads_conv_non_branded: float}>
     */
    public function monthly(Brand $brand, int $months = 6): array
    {
        $scope = BrandMeasurementScope::for($brand);
        if ($scope->isEmpty()) {
            return [];
        }
        $matcher = BrandedQueryMatcher::for($brand);
        $from = now()->startOfMonth()->subMonths($months - 1)->toDateString();
        $month = DB::connection()->getDriverName() === 'pgsql' ? "to_char(reporting_date, 'YYYY-MM')" : 'substr(reporting_date, 1, 7)';
        $out = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $key = now()->startOfMonth()->subMonths($i)->format('Y-m');
            $out[$key] = ['month' => $key, 'gsc_branded' => 0, 'gsc_non_branded' => 0, 'ads_cost_branded' => 0.0, 'ads_cost_non_branded' => 0.0, 'ads_conv_branded' => 0.0, 'ads_conv_non_branded' => 0.0];
        }
        $cache = [];
        $isBranded = function (string $query) use ($matcher, &$cache): bool {
            return $cache[$query] ??= $matcher->isBranded($query);
        };

        if (Schema::hasTable('gsc_query_daily')) {
            $rows = $scope->apply(DB::table('gsc_query_daily'))->where('reporting_date', '>=', $from)
                ->groupByRaw($month.', query')->selectRaw($month.' as m, query, sum(clicks) as clicks')->havingRaw('sum(clicks) > 0')->cursor();
            foreach ($rows as $row) {
                if (isset($out[$row->m])) {
                    $out[$row->m][$isBranded((string) $row->query) ? 'gsc_branded' : 'gsc_non_branded'] += (int) $row->clicks;
                }
            }
        }
        if (Schema::hasTable('google_ads_search_term_daily')) {
            $rows = $scope->apply(DB::table('google_ads_search_term_daily'))->where('reporting_date', '>=', $from)
                ->groupByRaw($month.', search_term')->selectRaw($month.' as m, search_term, sum(cost_amount) as cost, sum(conversions) as conversions')->cursor();
            foreach ($rows as $row) {
                if (isset($out[$row->m])) {
                    $suffix = $isBranded((string) $row->search_term) ? 'branded' : 'non_branded';
                    $out[$row->m]['ads_cost_'.$suffix] += round((float) $row->cost, 2);
                    $out[$row->m]['ads_conv_'.$suffix] += round((float) $row->conversions, 2);
                }
            }
        }

        return array_values($out);
    }
}
