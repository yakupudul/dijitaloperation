<?php

namespace App\Services\Outcomes\Readers;

use App\Models\Suggestion;
use App\Services\Gbp\GbpDailyWorkspace;
use App\Services\Gbp\GbpScreen;
use App\Services\Outcomes\OutcomeMetricReader;
use Illuminate\Support\Facades\DB;

/** İşletme Profili (channel `maps`): views, calls, directions, website clicks and their sum (actions) of the profile. */
final class MapsOutcomeReader implements OutcomeMetricReader
{
    public function __construct(private readonly GbpDailyWorkspace $daily) {}

    public function scope(Suggestion $suggestion): ?array
    {
        $resource = $suggestion->target_id !== null ? $this->daily->resource((int) $suggestion->target_id) : null;

        return $resource !== null ? ['asset_id' => (int) $suggestion->target_id, 'resource_id' => (int) $resource->id] : null;
    }

    public function lastDay(array $scope): ?string
    {
        $last = DB::table('gbp_performance_daily')->where('external_resource_id', (int) ($scope['resource_id'] ?? 0))->max('reporting_date');

        return $last !== null ? substr((string) $last, 0, 10) : null;
    }

    public function read(array $scope, string $from, string $to): ?array
    {
        $rows = DB::table('gbp_performance_daily')->where('external_resource_id', (int) ($scope['resource_id'] ?? 0))
            ->whereIn('metric', array_keys(GbpScreen::DAILY_METRICS))->whereBetween('reporting_date', [$from, $to.' 23:59:59'])
            ->groupBy('metric')->selectRaw('metric, SUM(value) as total')->get();
        if ($rows->isEmpty()) {
            return null;
        }
        $sum = ['search_views' => 0, 'maps_views' => 0, 'calls' => 0, 'directions' => 0, 'website_clicks' => 0];
        foreach ($rows as $row) {
            $sum[GbpScreen::DAILY_METRICS[(string) $row->metric]] += (int) $row->total;
        }

        return ['views' => $sum['search_views'] + $sum['maps_views'], 'calls' => $sum['calls'], 'directions' => $sum['directions'],
            'website_clicks' => $sum['website_clicks'], 'actions' => $sum['calls'] + $sum['directions'] + $sum['website_clicks']];
    }
}
