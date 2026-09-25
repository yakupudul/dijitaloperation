<?php

namespace App\Services\Brain\Success;

use App\Models\DigitalAsset;
use App\Services\Brain\Clustering\ServiceClusters;
use App\Services\Brain\ServiceSites;
use App\Services\SeoTasks\SeoPlanInputCollector;
use App\Services\SeoTasks\SeoText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Success of each brand's page for each topic cluster (last 90 days), made comparable across brands:
 *  - impressions → share of the cluster's search demand (when search volumes are known), else impressions;
 *  - clicks → CTR against the CTR expected at that position (position effect removed), shrunk toward the cohort
 *    average when data is thin (empirical Bayes, PRIOR_IMPRESSIONS);
 *  - visits and conversions of the page (GA4 landing page) → conversion rate, shrunk the same way (PRIOR_SESSIONS).
 * The score (0–100) is the page's average percentile on those measures inside its cohort — same service, same page
 * type, same market tier (metro / regional); a cohort smaller than MIN_COHORT falls back to service × page type.
 */
final class SuccessScorer
{
    public const int MIN_COHORT = 3;

    private const int PRIOR_IMPRESSIONS = 200;

    private const int PRIOR_SESSIONS = 50;

    private const array METROS = ['istanbul', 'ankara', 'izmir', 'bursa', 'antalya', 'adana', 'konya', 'gaziantep', 'kocaeli', 'mersin'];

    /** Typical organic CTR by position 1–10 (beyond: 1%). */
    private const array EXPECTED_CTR = [1 => 0.28, 2 => 0.15, 3 => 0.10, 4 => 0.07, 5 => 0.05, 6 => 0.04, 7 => 0.03, 8 => 0.025, 9 => 0.02, 10 => 0.018];

    public function __construct(
        private readonly ServiceClusters $clusters,
        private readonly ServiceSites $sites,
        private readonly SeoPlanInputCollector $collector,
        private readonly PageFeatureExtractor $features,
    ) {}

    /** @return int snapshots written */
    public function run(?int $serviceId = null): int
    {
        $serviceIds = $serviceId !== null ? [$serviceId] : DB::table('library_query_clusters')->where('status', 'active')->distinct()->pluck('service_id')->map('intval')->all();
        $count = 0;
        foreach ($serviceIds as $id) {
            try {
                $count += $this->service($id);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $count;
    }

    public static function expectedCtr(?float $position): float
    {
        if ($position === null) {
            return 0.01;
        }

        return self::EXPECTED_CTR[max(1, (int) round($position))] ?? 0.01;
    }

    private function service(int $serviceId): int
    {
        $clusters = array_filter($this->clusters->all([$serviceId]), fn (array $c): bool => $c['page_type'] !== 'faq' && $c['keys'] !== []);
        $sites = $this->sites->websites($serviceId);
        if ($clusters === [] || $sites->isEmpty()) {
            return 0;
        }
        $end = CarbonImmutable::now()->subDays(3);
        $start = $end->subDays(89);
        $volumes = $this->volumes(array_keys($clusters));
        $rows = [];
        foreach ($sites as $site) {
            $gsc = $this->sites->gscRows($site);
            $ga4 = $this->ga4($site, $start, $end);
            $targets = DB::table('library_cluster_targets')->where('digital_asset_id', $site->id)->where('url', '!=', '')->pluck('url', 'cluster_key');
            $tier = $this->tier((int) $site->brand_id);
            $places = PageFeatureExtractor::places((int) $site->brand_id);
            foreach ($clusters as $cluster) {
                $pages = ServiceClusters::pages($gsc, $cluster['keys']);
                $impressions = (int) array_sum(array_column($pages, 'impressions'));
                $clicks = (int) array_sum(array_column($pages, 'clicks'));
                $posW = 0.0;
                $posN = 0;
                foreach ($pages as $page) {
                    if ($page['position'] !== null) {
                        $posW += $page['position'] * $page['impressions'];
                        $posN += $page['impressions'];
                    }
                }
                $url = $targets[$cluster['id']] ?? (array_values($pages)[0]['url'] ?? null);
                $landing = $url !== null ? ($ga4[SeoText::urlKey($url)] ?? null) : null;
                if ($url !== null) {
                    $this->features->extract($site, $url, $cluster['keys'], $places);
                }
                $rows[] = [
                    'site' => $site, 'cluster' => $cluster, 'tier' => $tier, 'url' => $url,
                    'impressions' => $impressions, 'clicks' => $clicks, 'position' => $posN > 0 ? round($posW / $posN, 2) : null,
                    'sessions' => (int) ($landing['sessions'] ?? 0), 'engaged' => (int) ($landing['engaged_sessions'] ?? 0), 'conversions' => (float) ($landing['key_events'] ?? 0),
                    'demand' => ($volumes[$cluster['id']] ?? 0) > 0 ? $impressions / ($volumes[$cluster['id']] * 3) : null,
                ];
            }
        }

        return $this->score($rows);
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function score(array $rows): int
    {
        // Cohorts: service × page type × tier, falling back to service × page type when too small.
        $groups = [];
        foreach ($rows as $i => $row) {
            $groups[$row['cluster']['service_id'].':'.$row['cluster']['page_type'].':'.$row['tier']][] = $i;
        }
        $cohort = [];
        foreach ($rows as $i => $row) {
            $specific = $row['cluster']['service_id'].':'.$row['cluster']['page_type'].':'.$row['tier'];
            $cohort[$i] = count($groups[$specific]) >= self::MIN_COHORT ? $specific : $row['cluster']['service_id'].':'.$row['cluster']['page_type'].':all';
        }
        $members = [];
        foreach ($cohort as $i => $key) {
            $members[$key][] = $i;
        }
        $period = CarbonImmutable::now()->startOfMonth()->toDateString();
        $written = 0;
        foreach ($members as $key => $indexes) {
            $withData = array_values(array_filter($indexes, fn (int $i): bool => $rows[$i]['impressions'] > 0));
            $impr = array_sum(array_map(fn (int $i): int => $rows[$i]['impressions'], $withData));
            $prior = $impr > 0 ? array_sum(array_map(fn (int $i): int => $rows[$i]['clicks'], $withData)) / $impr : 0.02;
            $sessions = array_sum(array_map(fn (int $i): int => $rows[$i]['sessions'], $withData));
            $cvrPrior = $sessions > 0 ? array_sum(array_map(fn (int $i): float => $rows[$i]['conversions'], $withData)) / $sessions : 0.0;
            $measures = [];
            foreach ($withData as $i) {
                $row = $rows[$i];
                $ctrShrunk = ($row['clicks'] + $prior * self::PRIOR_IMPRESSIONS) / ($row['impressions'] + self::PRIOR_IMPRESSIONS);
                $rows[$i]['ctr_shrunk'] = $ctrShrunk;
                $rows[$i]['ctr_index'] = $ctrShrunk / self::expectedCtr($row['position']);
                $rows[$i]['cvr_shrunk'] = $row['sessions'] > 0 || $sessions > 0 ? ($row['conversions'] + $cvrPrior * self::PRIOR_SESSIONS) / ($row['sessions'] + self::PRIOR_SESSIONS) : null;
                $measures['visibility'][$i] = $row['demand'] ?? log(1 + $row['impressions']);
                $measures['position'][$i] = $row['position'] !== null ? -$row['position'] : null;
                $measures['ctr'][$i] = $rows[$i]['ctr_index'];
                $measures['visits'][$i] = log(1 + $row['sessions']);
                $measures['cvr'][$i] = $sessions > 0 ? $rows[$i]['cvr_shrunk'] : null;
            }
            $percentiles = [];
            foreach ($measures as $name => $values) {
                $values = array_filter($values, fn ($v): bool => $v !== null);
                if (count($values) < 2) {
                    continue;
                }
                foreach ($values as $i => $value) {
                    $below = count(array_filter($values, fn ($v): bool => $v < $value));
                    $equal = count(array_filter($values, fn ($v): bool => $v == $value)) - 1;
                    $percentiles[$i][$name] = ($below + 0.5 * $equal) / (count($values) - 1);
                }
            }
            foreach ($indexes as $i) {
                $row = $rows[$i];
                $components = $percentiles[$i] ?? [];
                $score = $components !== [] ? round(100 * array_sum($components) / count($components), 1) : null;
                DB::table('brain_success_snapshots')->updateOrInsert(
                    ['period' => $period, 'digital_asset_id' => $row['site']->id, 'cluster_id' => $row['cluster']['id']],
                    [
                        'brand_id' => $row['site']->brand_id, 'service_id' => $row['cluster']['service_id'], 'page_type' => $row['cluster']['page_type'],
                        'market_tier' => $row['tier'], 'cohort_key' => $key, 'cohort_size' => count($withData), 'url' => $row['url'],
                        'impressions' => $row['impressions'], 'clicks' => $row['clicks'], 'position' => $row['position'],
                        'ctr' => $row['impressions'] > 0 ? round($row['clicks'] / $row['impressions'], 5) : null,
                        'ctr_index' => isset($row['ctr_index']) ? round($row['ctr_index'], 3) : null,
                        'ctr_shrunk' => isset($row['ctr_shrunk']) ? round($row['ctr_shrunk'], 5) : null,
                        'demand_share' => $row['demand'] !== null ? round(min(1, $row['demand']), 5) : null,
                        'sessions' => $row['sessions'], 'engaged_rate' => $row['sessions'] > 0 ? round($row['engaged'] / $row['sessions'], 4) : null,
                        'conversions' => round($row['conversions'], 2), 'cvr_shrunk' => isset($row['cvr_shrunk']) ? round((float) $row['cvr_shrunk'], 5) : null,
                        'score' => $row['impressions'] > 0 ? $score : null,
                        'components' => json_encode(array_map(fn (float $v): float => round($v, 3), $components)),
                        'created_at' => now(), 'updated_at' => now(),
                    ],
                );
                $written++;
            }
        }

        return $written;
    }

    /** @return array<string, array{sessions: int, engaged_sessions: int, key_events: int}> */
    private function ga4(DigitalAsset $site, CarbonImmutable $start, CarbonImmutable $end): array
    {
        try {
            return $this->collector->ga4($site, $start, $end)['landing'] ?? [];
        } catch (Throwable) {
            return [];
        }
    }

    private function tier(int $brandId): string
    {
        $cities = DB::table('brand_service_areas')->where('brand_id', $brandId)->where('status', 'active')->pluck('city_name')->filter()->map(fn ($c): string => SeoText::fold((string) $c))->all();
        if ($cities === []) {
            return 'unknown';
        }

        return array_intersect($cities, self::METROS) !== [] ? 'metro' : 'regional';
    }

    /**
     * Monthly search volume of each cluster (sum of its queries' stored volumes).
     *
     * @param  list<int>  $clusterIds
     * @return array<int, float>
     */
    private function volumes(array $clusterIds): array
    {
        $perQuery = DB::table('search_query_library_item_service as s')
            ->join('search_query_library_source_records as r', 'r.search_query_library_item_id', '=', 's.search_query_library_item_id')
            ->whereIn('s.library_cluster_id', $clusterIds)->whereNotNull('r.search_volume')
            ->groupBy('s.library_cluster_id', 's.search_query_library_item_id')
            ->selectRaw('s.library_cluster_id as id, max(r.search_volume) as v')->get();
        $out = [];
        foreach ($perQuery as $row) {
            $out[(int) $row->id] = ($out[(int) $row->id] ?? 0.0) + (float) $row->v;
        }

        return $out;
    }
}
