<?php

namespace App\Services\Assistant;

use App\Support\SchemaState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Erişim kaydı: the uptime checks that carried an error, folded into episodes (checks of one site no more than
 * {@see self::GAP_MINUTES} apart). An episode is an `outage` when MoxDOP and the outside both got no answer at least once,
 * otherwise `moxdop_only` (the hosting dropped MoxDOP's address, or the site's connector proved it up). An outage that
 * lasted {@see UptimeMonitor::notifyAfterMinutes()} or longer was announced on the phone; the rest only stay here.
 */
final class UptimeLog
{
    /** Checks run every 5 minutes: two error rows further apart than this had a clean check between them. */
    private const GAP_MINUTES = 8;

    /** Error rows read at most (≈ 3,000 rows = 10 days of one site down without a break). */
    private const ROW_CAP = 3000;

    /**
     * @return list<array{site_id: int, site: string, started_at: Carbon, ended_at: Carbon, minutes: int, ongoing: bool, kind: string, notified: bool, checks: int, error: string}>
     */
    public function episodes(?int $siteId = null, int $days = 30, int $limit = 30): array
    {
        if (! SchemaState::hasTable('uptime_checks')) {
            return [];
        }
        $rows = DB::table('uptime_checks')
            ->join('digital_assets', 'digital_assets.id', '=', 'uptime_checks.digital_asset_id')
            ->whereNotNull('uptime_checks.error')
            ->where('uptime_checks.checked_at', '>=', now()->subDays($days))
            ->when($siteId !== null, fn ($q) => $q->where('uptime_checks.digital_asset_id', $siteId))
            ->orderByDesc('uptime_checks.checked_at')->limit(self::ROW_CAP)
            ->get(['uptime_checks.digital_asset_id', 'uptime_checks.ok', 'uptime_checks.error', 'uptime_checks.checked_at',
                'digital_assets.domain', 'digital_assets.name'])
            ->sortBy([['digital_asset_id', 'asc'], ['checked_at', 'asc']]);
        $states = DB::table('uptime_states')->whereIn('digital_asset_id', $rows->pluck('digital_asset_id')->unique()->all())
            ->pluck('state', 'digital_asset_id');
        $notifyAfter = UptimeMonitor::notifyAfterMinutes();

        $episodes = [];
        $current = null;
        foreach ($rows as $row) {
            $at = Carbon::parse($row->checked_at);
            if ($current !== null && $current['site_id'] === (int) $row->digital_asset_id && $at->lte($current['ended_at']->copy()->addMinutes(self::GAP_MINUTES))) {
                $current['ended_at'] = $at;
                $current['checks']++;
                $current['failed'] += $row->ok ? 0 : 1;
                $current['error'] = (string) $row->error;

                continue;
            }
            if ($current !== null) {
                $episodes[] = $current;
            }
            $current = ['site_id' => (int) $row->digital_asset_id, 'site' => (string) ($row->domain ?: $row->name), 'started_at' => $at,
                'ended_at' => $at, 'checks' => 1, 'failed' => $row->ok ? 0 : 1, 'error' => (string) $row->error];
        }
        if ($current !== null) {
            $episodes[] = $current;
        }

        $out = [];
        foreach ($episodes as $episode) {
            $ongoing = $episode['failed'] > 0 && ($states[$episode['site_id']] ?? null) === 'down'
                && $episode['ended_at']->gte(now()->subMinutes(self::GAP_MINUTES));
            $end = $ongoing ? now() : $episode['ended_at'];
            $minutes = (int) $episode['started_at']->diffInMinutes($end, true);
            $kind = $episode['failed'] > 0 ? 'outage' : 'moxdop_only';
            $out[] = ['site_id' => $episode['site_id'], 'site' => $episode['site'], 'started_at' => $episode['started_at'], 'ended_at' => $end,
                'minutes' => $minutes, 'ongoing' => $ongoing, 'kind' => $kind, 'notified' => $kind === 'outage' && $minutes >= $notifyAfter,
                'checks' => $episode['checks'], 'error' => mb_substr($episode['error'], 0, 160)];
        }
        usort($out, fn (array $a, array $b): int => $b['started_at'] <=> $a['started_at']);

        return array_slice($out, 0, $limit);
    }

    /** @return array{checks: int, failed: int, percent: ?float} share of the last $days days' checks that reached the site */
    public function summary(int $siteId, int $days = 30): array
    {
        if (! SchemaState::hasTable('uptime_checks')) {
            return ['checks' => 0, 'failed' => 0, 'percent' => null];
        }
        $row = DB::table('uptime_checks')->where('digital_asset_id', $siteId)->where('checked_at', '>=', now()->subDays($days))
            ->selectRaw('count(*) as checks, sum(case when ok then 0 else 1 end) as failed')->first();
        $checks = (int) ($row->checks ?? 0);
        $failed = (int) ($row->failed ?? 0);

        return ['checks' => $checks, 'failed' => $failed, 'percent' => $checks > 0 ? round(100 * ($checks - $failed) / $checks, 2) : null];
    }
}
