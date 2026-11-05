<?php

namespace App\Services\Site\Clarity;

use App\Models\ClarityProject;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Daily pull of a site's Clarity Data Export API ("project-live-insights", last 24 hours) in two requests: URL × Device
 * (page behaviour) and Channel × Source × Campaign (traffic behaviour, 2026-11-05). The API allows 10 requests a day per
 * project and at most the last 3 days, so the system asks once a day (and the operator's "Şimdi çek" at most every
 * PULL_GAP_HOURS). Page rows are folded per page URL (query string dropped) and device, traffic rows per channel ×
 * source × campaign, both stored as the previous Istanbul day; then the rules run.
 */
final class ClarityCollector
{
    public const string ENDPOINT = 'https://www.clarity.ms/export-data/api/v1/project-live-insights';

    /** Clarity smart-event metric => stored percentage column. */
    public const array EVENTS = [
        'RageClickCount' => 'rage_pct',
        'DeadClickCount' => 'dead_pct',
        'QuickbackClick' => 'quickback_pct',
        'ScriptErrorCount' => 'script_error_pct',
        'ErrorClickCount' => 'error_click_pct',
        'ExcessiveScroll' => 'excessive_scroll_pct',
    ];

    /** Days of rows kept. */
    public const int KEEP_DAYS = 120;

    /** Requests one pull makes (pages + traffic); the operator's extra pulls keep the day under the API's 10. */
    public const int REQUESTS_PER_PULL = 2;

    /** Hours between an operator's "Şimdi çek" and the previous pull. */
    public const int PULL_GAP_HOURS = 6;

    public function __construct(private readonly ClarityRules $rules, private readonly ClarityTrafficRules $trafficRules) {}

    /** @return array{status: string, rows: int, message: string} */
    public function pull(DigitalAsset $site): array
    {
        $project = ClarityProject::query()->where('website_asset_id', $site->id)->first();
        if ($project === null || ! $project->enabled) {
            return ['status' => 'off', 'rows' => 0, 'message' => 'Clarity bağlı değil.'];
        }
        $answer = $this->request($project, ['dimension1' => 'URL', 'dimension2' => 'Device']);
        if (is_string($answer)) {
            return $this->finish($project, 'error', 0, $answer);
        }
        $rows = $this->fold($answer);
        $day = now('Europe/Istanbul')->subDay()->toDateString();
        $pages = Page::query()->where('website_asset_id', $site->id)->get(['id', 'url'])
            ->mapWithKeys(fn (Page $p): array => [SeoText::urlKey((string) $p->url) => (int) $p->id])->all();
        DB::transaction(function () use ($site, $rows, $day, $pages): void {
            DB::table('clarity_page_days')->where('website_asset_id', $site->id)->where('day', $day)->delete();
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('clarity_page_days')->insert(array_map(fn (array $row): array => $row + [
                    'website_asset_id' => $site->id, 'day' => $day, 'url_hash' => hash('sha256', $row['url']),
                    'page_id' => $pages[$row['url']] ?? null, 'created_at' => now(), 'updated_at' => now(),
                ], $chunk));
            }
            DB::table('clarity_page_days')->where('website_asset_id', $site->id)->where('day', '<', now()->subDays(self::KEEP_DAYS)->toDateString())->delete();
        });
        if ($rows === []) {
            return $this->finish($project, 'empty', 0, 'Clarity son 24 saatte oturum görmedi; etiket sitede yüklü mü?');
        }
        $this->rules->sync($site);
        $traffic = $this->pullTraffic($site, $project, $day);
        $result = $this->finish($project, 'ok', count($rows), count($rows).' sayfa × cihaz satırı alındı; '.$traffic);
        if (str_starts_with($traffic, 'trafik')) {
            $project->forceFill(['last_error' => mb_substr('Sayfalar alındı, '.$traffic, 0, 500)])->save();
        }

        return $result;
    }

    /** Channel × Source × Campaign of the same day; a failure here keeps the page pull and says why. */
    private function pullTraffic(DigitalAsset $site, ClarityProject $project, string $day): string
    {
        $answer = $this->request($project, ['dimension1' => 'Channel', 'dimension2' => 'Source', 'dimension3' => 'Campaign']);
        if (is_string($answer)) {
            return 'trafik kaynakları alınamadı ('.$answer.')';
        }
        $rows = $this->foldTraffic($answer);
        DB::transaction(function () use ($site, $rows, $day): void {
            DB::table('clarity_traffic_days')->where('website_asset_id', $site->id)->where('day', $day)->delete();
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('clarity_traffic_days')->insert(array_map(fn (array $row): array => $row + [
                    'website_asset_id' => $site->id, 'day' => $day, 'key_hash' => self::trafficKey($row['channel'], $row['source'], $row['campaign']),
                    'created_at' => now(), 'updated_at' => now(),
                ], $chunk));
            }
            DB::table('clarity_traffic_days')->where('website_asset_id', $site->id)->where('day', '<', now()->subDays(self::KEEP_DAYS)->toDateString())->delete();
        });
        $this->trafficRules->sync($site);

        return count($rows).' trafik kaynağı satırı.';
    }

    public static function trafficKey(string $channel, string $source, string $campaign): string
    {
        return hash('sha256', $channel."\0".$source."\0".$campaign);
    }

    /**
     * One export request (last 24 hours); the decoded answer, or why it failed.
     *
     * @param  array<string, string>  $dimensions
     * @return array<int|string, mixed>|string
     */
    private function request(ClarityProject $project, array $dimensions): array|string
    {
        try {
            $response = Http::timeout(30)->withToken($project->api_token)->acceptJson()
                ->get(self::ENDPOINT, ['numOfDays' => 1] + $dimensions);
        } catch (Throwable $exception) {
            return 'Clarity\'ye ulaşılamadı: '.mb_substr($exception->getMessage(), 0, 200);
        }
        if (! $response->successful()) {
            return match ($response->status()) {
                401, 403 => 'Clarity token geçersiz ya da yetkisi yok; Clarity › Settings › Data Export\'tan yeni token alın.',
                429 => 'Clarity günlük istek sınırı doldu; yarın yeniden çekilir.',
                default => 'Clarity yanıtı: HTTP '.$response->status(),
            };
        }

        return (array) $response->json();
    }

    /**
     * The traffic answer folded per channel × source × campaign (empty values become "(yok)"). Rows without a channel
     * and a source are not traffic rows and are skipped.
     *
     * @param  array<int|string, mixed>  $metrics
     * @return list<array<string, mixed>>
     */
    public function foldTraffic(array $metrics): array
    {
        $columns = ['QuickbackClick' => 'quickback_pct', 'RageClickCount' => 'rage_pct', 'DeadClickCount' => 'dead_pct'];
        $rows = [];
        $weights = [];
        foreach ($metrics as $metric) {
            $name = (string) data_get($metric, 'metricName', '');
            foreach ((array) data_get($metric, 'information', []) as $info) {
                $info = array_change_key_case((array) $info, CASE_LOWER);
                if (! array_key_exists('channel', $info) && ! array_key_exists('source', $info)) {
                    continue;
                }
                $clean = fn (string $key): string => mb_substr(trim((string) ($info[$key] ?? '')) ?: '(yok)', 0, $key === 'channel' ? 100 : 255);
                [$channel, $source, $campaign] = [$clean('channel'), mb_strtolower($clean('source')), $clean('campaign')];
                $key = $channel."\0".$source."\0".$campaign;
                $rows[$key] ??= ['channel' => $channel, 'source' => $source, 'campaign' => $campaign, 'sessions' => 0, 'bot_sessions' => 0,
                    'quickback_pct' => null, 'rage_pct' => null, 'dead_pct' => null, 'scroll_depth' => null, 'active_seconds' => null, 'seen' => 0];
                $sessions = (int) ($info['totalsessioncount'] ?? $info['sessionscount'] ?? 0);
                $rows[$key]['seen'] = max($rows[$key]['seen'], $sessions);
                if ($name === 'Traffic') {
                    $rows[$key]['sessions'] += $sessions;
                    $rows[$key]['bot_sessions'] += (int) ($info['totalbotsessioncount'] ?? 0);
                } elseif (isset($columns[$name]) && isset($info['sessionswithmetricpercentage'])) {
                    $this->weigh($rows[$key], $weights, $key.'|'.$columns[$name], $columns[$name], (float) $info['sessionswithmetricpercentage'], $sessions);
                } elseif ($name === 'ScrollDepth' && isset($info['averagescrolldepth'])) {
                    $this->weigh($rows[$key], $weights, $key.'|scroll_depth', 'scroll_depth', (float) $info['averagescrolldepth'], $sessions);
                } elseif ($name === 'EngagementTime' && isset($info['activetime'])) {
                    $rows[$key]['active_seconds'] = (int) $info['activetime'];
                }
            }
        }

        return array_values(array_map(function (array $row): array {
            $row['sessions'] = $row['sessions'] ?: $row['seen'];
            $row['bot_sessions'] = min($row['bot_sessions'], $row['sessions']);
            unset($row['seen']);

            return $row;
        }, $rows));
    }

    /**
     * The API answer (a list of {metricName, information: [{URL, Device, …}]}) folded per URL key × device.
     *
     * @param  array<int|string, mixed>  $metrics
     * @return list<array<string, mixed>>
     */
    public function fold(array $metrics): array
    {
        $rows = [];
        $weights = [];
        foreach ($metrics as $metric) {
            $name = (string) data_get($metric, 'metricName', '');
            foreach ((array) data_get($metric, 'information', []) as $info) {
                $info = array_change_key_case((array) $info, CASE_LOWER);
                $url = SeoText::urlKey((string) preg_replace('/[?#].*$/', '', (string) ($info['url'] ?? '')));
                if ($url === '') {
                    continue;
                }
                $device = self::device((string) ($info['device'] ?? ''));
                $key = $url.'|'.$device;
                $rows[$key] ??= ['url' => mb_substr($url, 0, 1000), 'device' => $device, 'sessions' => 0, 'rage_pct' => null, 'dead_pct' => null,
                    'quickback_pct' => null, 'script_error_pct' => null, 'error_click_pct' => null, 'excessive_scroll_pct' => null, 'scroll_depth' => null, 'active_seconds' => null, 'seen' => 0];
                $sessions = (int) ($info['totalsessioncount'] ?? $info['sessionscount'] ?? 0);
                $rows[$key]['seen'] = max($rows[$key]['seen'], $sessions);
                if ($name === 'Traffic') {
                    $rows[$key]['sessions'] += $sessions;
                } elseif (isset(self::EVENTS[$name]) && isset($info['sessionswithmetricpercentage'])) {
                    $this->weigh($rows[$key], $weights, $key.'|'.self::EVENTS[$name], self::EVENTS[$name], (float) $info['sessionswithmetricpercentage'], $sessions);
                } elseif ($name === 'ScrollDepth' && isset($info['averagescrolldepth'])) {
                    $this->weigh($rows[$key], $weights, $key.'|scroll_depth', 'scroll_depth', (float) $info['averagescrolldepth'], $sessions);
                } elseif ($name === 'EngagementTime' && isset($info['activetime'])) {
                    $rows[$key]['active_seconds'] = (int) $info['activetime'];
                }
            }
        }

        return array_values(array_map(function (array $row): array {
            $row['sessions'] = $row['sessions'] ?: $row['seen']; // no Traffic row: the largest event denominator
            unset($row['seen']);

            return $row;
        }, $rows));
    }

    /**
     * Several query-string variants of one page: a session-weighted mean.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $weights  slot => sessions folded so far
     */
    private function weigh(array &$row, array &$weights, string $slot, string $column, float $value, int $sessions): void
    {
        $before = $weights[$slot] ?? 0;
        $weight = max(1, $sessions);
        $row[$column] = round((((float) ($row[$column] ?? 0)) * $before + $value * $weight) / ($before + $weight), 2);
        $weights[$slot] = $before + $weight;
    }

    private static function device(string $device): string
    {
        return match (mb_strtolower(trim($device))) {
            'mobile' => 'mobile',
            'tablet' => 'tablet',
            'pc', 'desktop' => 'desktop',
            default => 'other',
        };
    }

    /** @return array{status: string, rows: int, message: string} */
    private function finish(ClarityProject $project, string $status, int $rows, string $message): array
    {
        $project->forceFill(['last_pulled_at' => now(), 'last_status' => $status, 'last_error' => $status === 'ok' ? null : mb_substr($message, 0, 500)])->save();

        return ['status' => $status, 'rows' => $rows, 'message' => $message];
    }
}
