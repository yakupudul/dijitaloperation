<?php

namespace App\Services\Measurement;

use App\Enums\SeoTaskStatus;
use App\Models\DigitalAsset;
use App\Models\SeoTask;
use App\Services\SeoTasks\SeoPlanInputCollector;
use App\Services\SeoTasks\SeoText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sayfa Karnesi: one row per page of a website with everything measured about it in the last 28 days —
 * Google clicks (vs the 28 days before), GA4 sessions, key events and channel mix, Google Ads landing
 * clicks / cost / conversions, indexing verdict, lab LCP and open SEO tasks — plus plain-language flags.
 * Stored data only; a source that has no data for a page shows as unknown, never as zero.
 */
final class PageScorecardReader
{
    public function __construct(private readonly SeoPlanInputCollector $inputs) {}

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, period: array{start: string, end: string}, sources: array<string, bool>}
     */
    public function read(DigitalAsset $site, string $search = '', int $limit = 50): array
    {
        $end = CarbonImmutable::now()->subDay()->startOfDay();
        $start = $end->subDays(27);
        $origin = SeoText::origin($site->primary_url ?: ('https://'.$site->domain));
        $host = preg_replace('/^www\./', '', mb_strtolower((string) parse_url($origin, PHP_URL_HOST))) ?? '';

        $traffic = $this->inputs->pageTraffic($site, $end);
        $ga4 = $this->inputs->ga4($site, $start, $end);
        $channels = $this->channels($site, $origin, $start, $end);
        $ads = $this->ads($site, $host, $start, $end);
        $inspections = $this->inputs->inspections($site);
        $performance = $this->inputs->performance($site);
        $tasks = $this->tasks($site);

        $keys = array_unique(array_merge(array_keys($traffic['pages']), array_keys($ga4['landing']), array_keys($ads), array_keys($tasks)));
        $rows = [];
        foreach ($keys as $key) {
            $gsc = $traffic['pages'][$key] ?? null;
            $analytics = $ga4['landing'][$key] ?? null;
            $paid = $ads[$key] ?? null;
            $url = (string) ($gsc['url'] ?? $paid['url'] ?? $tasks[$key]['url'] ?? ('https://'.$key));
            if ($search !== '' && ! str_contains(mb_strtolower($url), mb_strtolower($search))) {
                continue;
            }
            $inspection = $inspections[$key] ?? null;
            $lcp = $performance[$key]['lcp_ms'] ?? null;
            $row = [
                'key' => $key,
                'url' => $url,
                'path' => (string) (parse_url($url, PHP_URL_PATH) ?: '/'),
                'clicks' => $gsc !== null ? (int) $gsc['clicks_cur'] : null,
                'clicks_prev' => $gsc !== null ? (int) $gsc['clicks_prev'] : null,
                'impressions' => $gsc !== null ? (int) $gsc['impr_cur'] : null,
                'sessions' => $analytics !== null ? (int) $analytics['sessions'] : null,
                'key_events' => $analytics !== null ? (int) $analytics['key_events'] : null,
                'channels' => $channels[$key] ?? [],
                'ads_clicks' => $paid['clicks'] ?? null,
                'ads_cost' => $paid['cost'] ?? null,
                'ads_conversions' => $paid['conversions'] ?? null,
                'indexed' => $inspection !== null ? ($inspection['verdict'] === 'PASS') : null,
                'coverage' => $inspection['coverage_state'] ?? null,
                'lcp_ms' => $lcp,
                'tasks' => $tasks[$key]['titles'] ?? [],
                'task_count' => $tasks[$key]['count'] ?? 0,
            ];
            $row['flags'] = $this->flags($row);
            $row['weight'] = (int) ($row['clicks'] ?? 0) + (int) ($row['sessions'] ?? 0) + (int) ($row['ads_clicks'] ?? 0) + 5 * $row['task_count'];
            $rows[] = $row;
        }
        usort($rows, fn (array $a, array $b): int => $b['weight'] <=> $a['weight']);

        return [
            'rows' => array_slice($rows, 0, $limit),
            'total' => count($rows),
            'period' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'sources' => [
                'search_console' => $traffic['available'],
                'ga4' => $ga4['available'],
                'ga4_channels' => $channels !== [],
                'google_ads' => $ads !== [],
                'inspection' => $inspections !== [],
                'speed' => $performance !== [],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function flags(array $row): array
    {
        $flags = [];
        if ($row['indexed'] === false) {
            $flags[] = 'Google dizininde değil';
        }
        if ($row['clicks_prev'] !== null && $row['clicks_prev'] >= 20 && $row['clicks'] < 0.7 * $row['clicks_prev']) {
            $flags[] = 'Google tıkları düşüyor';
        }
        if ($row['sessions'] !== null && $row['sessions'] >= 100 && (int) $row['key_events'] === 0) {
            $flags[] = 'Ziyaret var, dönüşüm yok';
        }
        if ($row['ads_clicks'] !== null && $row['ads_clicks'] >= 30 && (float) $row['ads_conversions'] <= 0.0) {
            $flags[] = 'Reklam tıklıyor, dönüşmüyor';
        }
        if ($row['lcp_ms'] !== null && $row['lcp_ms'] > 4000) {
            $flags[] = 'Yavaş açılıyor';
        }

        return $flags;
    }

    /** @return array<string, array<string, int>> urlKey => channel => sessions */
    private function channels(DigitalAsset $site, string $origin, CarbonImmutable $start, CarbonImmutable $end): array
    {
        if (! Schema::hasTable('ga4_landing_channel_daily')) {
            return [];
        }
        $out = [];
        $this->inputs->scopeGa4(DB::table('ga4_landing_channel_daily'), $site)
            ->whereBetween('reporting_date', [$start->toDateString(), $end->toDateString()])
            ->groupBy('landingPage', 'sessionDefaultChannelGroup')
            ->selectRaw('landingPage as page, sessionDefaultChannelGroup as channel, sum(sessions) as sessions')
            ->get()
            ->each(function (object $row) use (&$out, $origin): void {
                $path = trim((string) $row->page);
                if ($path === '' || $path === '(not set)') {
                    return;
                }
                $key = SeoText::urlKey(str_starts_with($path, 'http') ? $path : $origin.(str_starts_with($path, '/') ? '' : '/').$path);
                $out[$key][(string) $row->channel] = ($out[$key][(string) $row->channel] ?? 0) + (int) $row->sessions;
            });
        foreach ($out as &$mix) {
            arsort($mix);
        }

        return $out;
    }

    /** @return array<string, array{url: string, clicks: int, cost: float, conversions: float}> */
    private function ads(DigitalAsset $site, string $host, CarbonImmutable $start, CarbonImmutable $end): array
    {
        if ($site->brand === null || ! Schema::hasTable('google_ads_landing_page_daily')) {
            return [];
        }
        $out = [];
        BrandMeasurementScope::for($site->brand)->apply(DB::table('google_ads_landing_page_daily'))
            ->whereBetween('reporting_date', [$start->toDateString(), $end->toDateString()])
            ->groupBy('landing_page')
            ->selectRaw('landing_page, sum(clicks) as clicks, sum(cost_amount) as cost, sum(conversions) as conversions')
            ->get()
            ->each(function (object $row) use (&$out, $host): void {
                $url = (string) $row->landing_page;
                $rowHost = preg_replace('/^www\./', '', mb_strtolower((string) parse_url($url, PHP_URL_HOST))) ?? '';
                if ($host !== '' && $rowHost !== $host) {
                    return;
                }
                // Ads landing URLs carry tracking parameters; a page is its path.
                $key = SeoText::urlKey(strtok($url, '?#') ?: $url);
                $entry = $out[$key] ?? ['url' => strtok($url, '?#') ?: $url, 'clicks' => 0, 'cost' => 0.0, 'conversions' => 0.0];
                $entry['clicks'] += (int) $row->clicks;
                $entry['cost'] = round($entry['cost'] + (float) $row->cost, 2);
                $entry['conversions'] = round($entry['conversions'] + (float) $row->conversions, 2);
                $out[$key] = $entry;
            });

        return $out;
    }

    /** @return array<string, array{url: string, count: int, titles: list<string>}> */
    private function tasks(DigitalAsset $site): array
    {
        $out = [];
        SeoTask::query()->where('digital_asset_id', $site->id)->where('status', SeoTaskStatus::Open->value)
            ->whereNotNull('target_url')->orderByDesc('priority_score')->limit(1000)->get(['target_url', 'title'])
            ->each(function (SeoTask $task) use (&$out): void {
                $key = SeoText::urlKey((string) $task->target_url);
                $out[$key] ??= ['url' => (string) $task->target_url, 'count' => 0, 'titles' => []];
                $out[$key]['count']++;
                if (count($out[$key]['titles']) < 3) {
                    $out[$key]['titles'][] = (string) $task->title;
                }
            });

        return $out;
    }
}
