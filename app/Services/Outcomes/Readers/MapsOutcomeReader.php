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

    /**
     * lastDay() for several profiles in one query; a profile without rows is left out.
     *
     * @param  list<int>  $resourceIds
     * @return array<int, string> external resource id => last day (Y-m-d)
     */
    public function lastDays(array $resourceIds): array
    {
        if ($resourceIds === []) {
            return [];
        }

        return DB::table('gbp_performance_daily')->whereIn('external_resource_id', $resourceIds)->groupBy('external_resource_id')
            ->selectRaw('external_resource_id, MAX(reporting_date) as last_day')->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->external_resource_id => substr((string) $row->last_day, 0, 10)])->all();
    }

    public function read(array $scope, string $from, string $to): ?array
    {
        $resourceId = (int) ($scope['resource_id'] ?? 0);

        return $this->readMany([$resourceId], $from, $to)[$resourceId] ?? null;
    }

    /**
     * read() for several profiles over the same period in one query; a profile without rows is left out.
     *
     * @param  list<int>  $resourceIds
     * @return array<int, array{views: int, calls: int, directions: int, website_clicks: int, actions: int}> external resource id => sums
     */
    public function readMany(array $resourceIds, string $from, string $to): array
    {
        if ($resourceIds === []) {
            return [];
        }
        $sums = [];
        DB::table('gbp_performance_daily')->whereIn('external_resource_id', $resourceIds)
            ->whereIn('metric', array_keys(GbpScreen::DAILY_METRICS))->whereBetween('reporting_date', [$from, $to.' 23:59:59'])
            ->groupBy('external_resource_id', 'metric')->selectRaw('external_resource_id, metric, SUM(value) as total')->get()
            ->each(function (object $row) use (&$sums): void {
                $id = (int) $row->external_resource_id;
                $sums[$id] ??= ['search_views' => 0, 'maps_views' => 0, 'calls' => 0, 'directions' => 0, 'website_clicks' => 0];
                $sums[$id][GbpScreen::DAILY_METRICS[(string) $row->metric]] += (int) $row->total;
            });

        return array_map(fn (array $sum): array => ['views' => $sum['search_views'] + $sum['maps_views'], 'calls' => $sum['calls'], 'directions' => $sum['directions'],
            'website_clicks' => $sum['website_clicks'], 'actions' => $sum['calls'] + $sum['directions'] + $sum['website_clicks']], $sums);
    }
}
