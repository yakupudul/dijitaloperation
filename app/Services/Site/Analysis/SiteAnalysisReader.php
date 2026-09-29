<?php

namespace App\Services\Site\Analysis;

use App\Models\BrandClusterPage;
use App\Models\BrandServiceArea;
use App\Models\ClusterQuery;
use App\Models\DigitalAsset;
use App\Services\Queries\QuerySourceAggregator;
use App\Services\Site\Competitors\CompetitorTargets;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Analiz (Search Console + GA4 of one website): the site's bound Search Console properties (`gsc_query_page_daily`,
 * raw queries) and GA4 properties (`ga4_landing_source_daily`) over a period ending on the last Search Console day,
 * compared with the previous period of the same length. Clusters use the raw Search Console queries linked to the
 * cluster's queries through `query_sources`, split by the brand's target areas the raw query names. Results are cached
 * per site × period × last data day (1 hour).
 */
final class SiteAnalysisReader
{
    public const array PERIODS = [7 => '7 gün', 28 => '28 gün', 90 => '90 gün'];

    private const int MAX_ROWS = 5000;

    private const int CHUNK = 500;

    public function __construct(private readonly CompetitorTargets $targets) {}

    /** @return array{end: string, start: string, prev_start: string, prev_end: string, gsc: list<int>, ga4: list<int>} */
    public function window(DigitalAsset $site, int $days): array
    {
        $days = array_key_exists($days, self::PERIODS) ? $days : 28;
        $gsc = $this->resources($site, 'search_console');
        $ga4 = $this->resources($site, 'ga4');
        $last = $gsc !== [] ? DB::table('gsc_query_page_daily')->whereIn('external_resource_id', $gsc)->where('search_type', 'web')->max('reporting_date') : null;
        $last ??= $ga4 !== [] ? DB::table('ga4_landing_source_daily')->whereIn('external_resource_id', $ga4)->max('reporting_date') : null;
        $end = $last !== null ? CarbonImmutable::parse($last) : CarbonImmutable::yesterday();
        $start = $end->subDays($days - 1);

        return [
            'end' => $end->toDateString(), 'start' => $start->toDateString(),
            'prev_end' => $start->subDay()->toDateString(), 'prev_start' => $start->subDays($days)->toDateString(),
            'gsc' => $gsc, 'ga4' => $ga4,
        ];
    }

    /** @return array{current: array{clicks: int, impressions: int, position: ?float, sessions: int, key_events: float}, previous: array{clicks: int, impressions: int, position: ?float, sessions: int, key_events: float}} */
    public function totals(DigitalAsset $site, int $days): array
    {
        $w = $this->window($site, $days);

        return $this->cached($site, 'totals', $w, function () use ($w): array {
            $out = [];
            foreach (['current' => [$w['start'], $w['end']], 'previous' => [$w['prev_start'], $w['prev_end']]] as $key => [$from, $to]) {
                $gsc = $this->gscBase($w['gsc'], $from, $to)->selectRaw($this->metricSql())->first();
                $ga4 = $w['ga4'] === [] ? null : DB::table('ga4_landing_source_daily')->whereIn('external_resource_id', $w['ga4'])
                    ->whereBetween('reporting_date', [$from, $to])->selectRaw('sum(sessions) as sessions, sum("keyEvents") as key_events')->first();
                $out[$key] = [
                    'clicks' => (int) ($gsc->clicks ?? 0), 'impressions' => (int) ($gsc->impressions ?? 0), 'position' => self::position($gsc),
                    'sessions' => (int) ($ga4->sessions ?? 0), 'key_events' => round((float) ($ga4->key_events ?? 0), 1),
                ];
            }

            return $out;
        });
    }

    /** @return list<array{query: string, clicks: int, impressions: int, position: ?float, prev_clicks: int}> */
    public function queries(DigitalAsset $site, int $days): array
    {
        $w = $this->window($site, $days);

        return $this->cached($site, 'queries', $w, function () use ($w): array {
            $previous = $this->byColumn($w['gsc'], 'query', $w['prev_start'], $w['prev_end']);
            $rows = [];
            foreach ($this->byColumn($w['gsc'], 'query', $w['start'], $w['end']) as $query => $m) {
                $rows[] = ['query' => (string) $query, 'clicks' => $m['clicks'], 'impressions' => $m['impressions'], 'position' => $m['position'], 'prev_clicks' => $previous[$query]['clicks'] ?? 0];
            }

            return $rows;
        });
    }

    /** @return list<array{url: string, path: string, clicks: int, impressions: int, position: ?float, sessions: int, key_events: float, prev_clicks: int}> */
    public function pages(DigitalAsset $site, int $days): array
    {
        $w = $this->window($site, $days);

        return $this->cached($site, 'pages', $w, function () use ($w): array {
            $previous = [];
            foreach ($this->byColumn($w['gsc'], 'page', $w['prev_start'], $w['prev_end']) as $url => $m) {
                $previous[self::path((string) $url)] = ($previous[self::path((string) $url)] ?? 0) + $m['clicks'];
            }
            $landing = $this->landing($w['ga4'], $w['start'], $w['end']);
            $rows = [];
            foreach ($this->byColumn($w['gsc'], 'page', $w['start'], $w['end']) as $url => $m) {
                $path = self::path((string) $url);
                $rows[$path] ??= ['url' => (string) $url, 'path' => $path, 'clicks' => 0, 'impressions' => 0, 'weighted' => 0.0, 'weight' => 0,
                    'sessions' => (int) ($landing[$path]['sessions'] ?? 0), 'key_events' => (float) ($landing[$path]['key_events'] ?? 0), 'prev_clicks' => $previous[$path] ?? 0];
                $rows[$path]['clicks'] += $m['clicks'];
                $rows[$path]['impressions'] += $m['impressions'];
                $rows[$path]['weighted'] += $m['weighted'];
                $rows[$path]['weight'] += $m['weight'];
            }
            foreach ($landing as $path => $m) {
                $rows[$path] ??= ['url' => $path, 'path' => $path, 'clicks' => 0, 'impressions' => 0, 'weighted' => 0.0, 'weight' => 0,
                    'sessions' => $m['sessions'], 'key_events' => $m['key_events'], 'prev_clicks' => $previous[$path] ?? 0];
            }
            $out = array_map(fn (array $r): array => [
                'url' => $r['url'], 'path' => $r['path'], 'clicks' => $r['clicks'], 'impressions' => $r['impressions'],
                'position' => $r['weight'] > 0 ? round($r['weighted'] / $r['weight'], 1) : null,
                'sessions' => $r['sessions'], 'key_events' => round($r['key_events'], 1), 'prev_clicks' => $r['prev_clicks'],
            ], array_values($rows));
            usort($out, fn (array $a, array $b): int => [$b['clicks'], $b['sessions'], $b['impressions']] <=> [$a['clicks'], $a['sessions'], $a['impressions']]);

            return $out;
        });
    }

    /** @return list<array{landing: string, source: string, medium: string, sessions: int, key_events: float}> */
    public function conversions(DigitalAsset $site, int $days): array
    {
        $w = $this->window($site, $days);

        return $this->cached($site, 'conversions', $w, function () use ($w): array {
            if ($w['ga4'] === []) {
                return [];
            }

            return DB::table('ga4_landing_source_daily')->whereIn('external_resource_id', $w['ga4'])
                ->whereBetween('reporting_date', [$w['start'], $w['end']])
                ->groupBy('landingPage', 'sessionSource', 'sessionMedium')
                ->selectRaw('"landingPage" as landing, "sessionSource" as source, "sessionMedium" as medium, sum(sessions) as sessions, sum("keyEvents") as key_events')
                ->havingRaw('sum("keyEvents") > 0')
                ->orderByDesc('key_events')->orderByDesc('sessions')->limit(self::MAX_ROWS)->get()
                ->map(fn (object $r): array => ['landing' => (string) $r->landing, 'source' => (string) $r->source, 'medium' => (string) $r->medium,
                    'sessions' => (int) $r->sessions, 'key_events' => round((float) $r->key_events, 1)])->all();
        });
    }

    /**
     * @return list<array{cluster_id: int, name: string, clicks: int, impressions: int, position: ?float, prev_clicks: int,
     *     url: ?string, sessions: ?int, key_events: ?float, areas: list<array{area: string, clicks: int, impressions: int, position: ?float}>}>
     */
    public function clusters(DigitalAsset $site, int $days): array
    {
        $brand = $site->brand;
        if ($brand === null) {
            return [];
        }
        $w = $this->window($site, $days);

        return $this->cached($site, 'clusters', $w, function () use ($site, $brand, $w): array {
            $clusters = $this->targets->clusters($brand);
            if ($clusters->isEmpty()) {
                return [];
            }
            $queryCluster = ClusterQuery::query()->whereIn('cluster_id', $clusters->pluck('id'))->pluck('cluster_id', 'query_id')->all();
            $rawCluster = [];
            if ($w['gsc'] !== [] && $queryCluster !== []) {
                foreach (array_chunk(array_keys($queryCluster), self::CHUNK) as $ids) {
                    DB::table('query_sources')->whereIn('external_resource_id', $w['gsc'])->whereIn('query_id', $ids)
                        ->select(['raw_query', 'query_id'])->distinct()->get()
                        ->each(function (object $r) use (&$rawCluster, $queryCluster): void {
                            $rawCluster[(string) $r->raw_query] = (int) $queryCluster[(int) $r->query_id];
                        });
                }
            }
            $current = $this->byRawQueries($w['gsc'], array_keys($rawCluster), $w['start'], $w['end']);
            $previous = $this->byRawQueries($w['gsc'], array_keys($rawCluster), $w['prev_start'], $w['prev_end']);
            $areas = $this->areaTerms((int) $brand->id);
            $landing = $this->landing($w['ga4'], $w['start'], $w['end']);
            $mapped = BrandClusterPage::query()->with('page:id,url')->where('brand_id', $brand->id)->where('website_asset_id', $site->id)
                ->whereNotNull('page_id')->orderBy('id')->get()->groupBy('cluster_id');

            $out = [];
            foreach ($clusters as $cluster) {
                $sum = ['clicks' => 0, 'impressions' => 0, 'weighted' => 0.0, 'weight' => 0];
                $byArea = [];
                $prevClicks = 0;
                foreach ($current as $raw => $m) {
                    $key = QuerySourceAggregator::cleanRaw((string) $raw);
                    if (($rawCluster[$key] ?? null) !== $cluster->id) {
                        continue;
                    }
                    foreach (['clicks', 'impressions', 'weighted', 'weight'] as $f) {
                        $sum[$f] += $m[$f];
                    }
                    $area = self::areaOf($key, $areas);
                    $byArea[$area] ??= ['clicks' => 0, 'impressions' => 0, 'weighted' => 0.0, 'weight' => 0];
                    foreach (['clicks', 'impressions', 'weighted', 'weight'] as $f) {
                        $byArea[$area][$f] += $m[$f];
                    }
                }
                foreach ($previous as $raw => $m) {
                    if (($rawCluster[QuerySourceAggregator::cleanRaw((string) $raw)] ?? null) === $cluster->id) {
                        $prevClicks += $m['clicks'];
                    }
                }
                $page = $mapped->get($cluster->id)?->first()?->page;
                $path = $page !== null ? self::path((string) $page->url) : null;
                uksort($byArea, fn ($a, $b): int => $a === '—' ? 1 : ($b === '—' ? -1 : strcmp((string) $a, (string) $b)));
                $out[] = [
                    'cluster_id' => (int) $cluster->id, 'name' => (string) $cluster->name,
                    'clicks' => $sum['clicks'], 'impressions' => $sum['impressions'],
                    'position' => $sum['weight'] > 0 ? round($sum['weighted'] / $sum['weight'], 1) : null, 'prev_clicks' => $prevClicks,
                    'url' => $page?->url, 'sessions' => $path !== null ? (int) ($landing[$path]['sessions'] ?? 0) : null,
                    'key_events' => $path !== null ? round((float) ($landing[$path]['key_events'] ?? 0), 1) : null,
                    'areas' => array_map(fn (string $area, array $m): array => ['area' => $area, 'clicks' => $m['clicks'], 'impressions' => $m['impressions'],
                        'position' => $m['weight'] > 0 ? round($m['weighted'] / $m['weight'], 1) : null], array_keys($byArea), array_values($byArea)),
                ];
            }
            usort($out, fn (array $a, array $b): int => [$b['clicks'], $b['impressions']] <=> [$a['clicks'], $a['impressions']]);

            return $out;
        });
    }

    /** URL path without query string / fragment and trailing slash ("/" for the home page). */
    public static function path(string $url): string
    {
        $path = str_contains($url, '://') ? (string) parse_url($url, PHP_URL_PATH) : (string) strtok($url, '?#');
        $path = '/'.trim(rawurldecode($path), '/');

        return $path;
    }

    /**
     * The brand area a raw query names: district / operator names first (more specific), then cities; "—" for none.
     *
     * @param  array<string, array{specific: list<string>, city: list<string>}>  $areas
     */
    public static function areaOf(string $rawQuery, array $areas): string
    {
        $folded = ' '.self::fold($rawQuery);
        foreach (['specific', 'city'] as $level) {
            foreach ($areas as $label => $terms) {
                foreach ($terms[$level] as $term) {
                    if (str_contains($folded, ' '.$term)) {
                        return (string) $label;
                    }
                }
            }
        }

        return '—';
    }

    /** @return array<string, array{specific: list<string>, city: list<string>}> area label => folded terms */
    private function areaTerms(int $brandId): array
    {
        $out = [];
        $clean = fn (array $values): array => array_values(array_unique(array_filter(array_map(fn ($t): string => self::fold((string) $t), $values), fn (string $t): bool => mb_strlen($t) >= 3)));
        foreach (BrandServiceArea::query()->where('brand_id', $brandId)->where('status', 'active')->orderByDesc('physical_branch')->orderBy('id')->get() as $area) {
            $city = $clean([$area->city_name]);
            $specific = array_values(array_diff($clean([$area->district_name, $area->name]), $city));
            if ($city !== [] || $specific !== []) {
                $out[$area->displayName()] = ['specific' => $specific, 'city' => $city];
            }
        }

        return $out;
    }

    private static function fold(string $value): string
    {
        return trim(Str::ascii(mb_strtolower(str_replace(['İ', 'I'], ['i', 'ı'], $value))));
    }

    /** @return list<int> */
    private function resources(DigitalAsset $site, string $capability): array
    {
        return DB::table('core_asset_bindings')->where('digital_asset_id', $site->id)->where('capability', $capability)
            ->where('status', 'active')->pluck('external_resource_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
    }

    /** @param  list<int>  $resources */
    private function gscBase(array $resources, string $from, string $to): Builder
    {
        return DB::table('gsc_query_page_daily')->whereIn('external_resource_id', $resources ?: [0])->where('search_type', 'web')
            ->whereBetween('reporting_date', [$from, $to]);
    }

    private function metricSql(): string
    {
        $position = QuerySourceAggregator::positionExpression();

        return "sum(clicks) as clicks, sum(impressions) as impressions, sum(({$position}) * impressions) as weighted, sum(CASE WHEN ({$position}) IS NULL THEN 0 ELSE impressions END) as weight";
    }

    private static function position(?object $row): ?float
    {
        return $row !== null && (int) ($row->weight ?? 0) > 0 ? round((float) $row->weighted / (int) $row->weight, 1) : null;
    }

    /**
     * @param  list<int>  $resources
     * @return array<string, array{clicks: int, impressions: int, weighted: float, weight: int, position: ?float}>
     */
    private function byColumn(array $resources, string $column, string $from, string $to): array
    {
        if ($resources === []) {
            return [];
        }
        $out = [];
        $this->gscBase($resources, $from, $to)->groupBy($column)->selectRaw("{$column} as k, ".$this->metricSql())
            ->orderByDesc('clicks')->orderByDesc('impressions')->limit(self::MAX_ROWS)->get()
            ->each(function (object $r) use (&$out): void {
                $out[(string) $r->k] = ['clicks' => (int) $r->clicks, 'impressions' => (int) $r->impressions, 'weighted' => (float) $r->weighted, 'weight' => (int) $r->weight, 'position' => self::position($r)];
            });

        return $out;
    }

    /**
     * @param  list<int>  $resources
     * @param  list<string>  $raws
     * @return array<string, array{clicks: int, impressions: int, weighted: float, weight: int}>
     */
    private function byRawQueries(array $resources, array $raws, string $from, string $to): array
    {
        if ($resources === [] || $raws === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($raws, self::CHUNK) as $chunk) {
            $this->gscBase($resources, $from, $to)->whereIn('query', $chunk)->groupBy('query')->selectRaw('query as k, '.$this->metricSql())->get()
                ->each(function (object $r) use (&$out): void {
                    $key = (string) $r->k;
                    $out[$key] = ['clicks' => (int) $r->clicks + ($out[$key]['clicks'] ?? 0), 'impressions' => (int) $r->impressions + ($out[$key]['impressions'] ?? 0),
                        'weighted' => (float) $r->weighted + ($out[$key]['weighted'] ?? 0.0), 'weight' => (int) $r->weight + ($out[$key]['weight'] ?? 0)];
                });
        }

        return $out;
    }

    /**
     * GA4 sessions / key events per landing path.
     *
     * @param  list<int>  $resources
     * @return array<string, array{sessions: int, key_events: float}>
     */
    private function landing(array $resources, string $from, string $to): array
    {
        if ($resources === []) {
            return [];
        }
        $out = [];
        DB::table('ga4_landing_source_daily')->whereIn('external_resource_id', $resources)->whereBetween('reporting_date', [$from, $to])
            ->groupBy('landingPage')->selectRaw('"landingPage" as landing, sum(sessions) as sessions, sum("keyEvents") as key_events')
            ->orderByDesc('sessions')->limit(self::MAX_ROWS)->get()
            ->each(function (object $r) use (&$out): void {
                $landing = (string) $r->landing;
                if ($landing === '' || $landing === '(not set)') {
                    return;
                }
                $path = self::path($landing);
                $out[$path] = ['sessions' => (int) $r->sessions + ($out[$path]['sessions'] ?? 0), 'key_events' => (float) $r->key_events + ($out[$path]['key_events'] ?? 0.0)];
            });

        return $out;
    }

    /**
     * @template T
     *
     * @param  array{end: string, start: string, prev_start: string, prev_end: string, gsc: list<int>, ga4: list<int>}  $w
     * @param  callable(): T  $build
     * @return T
     */
    private function cached(DigitalAsset $site, string $part, array $w, callable $build): mixed
    {
        return Cache::remember('site:analysis:'.$site->id.':'.$part.':'.$w['start'].':'.$w['end'].':'.md5(json_encode([$w['gsc'], $w['ga4']])), now()->addHour(), $build);
    }
}
