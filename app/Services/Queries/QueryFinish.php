<?php

namespace App\Services\Queries;

use App\Models\User;
use App\Services\Ai\AiAssignments;
use App\Services\Ai\ClaudeApiWindow;
use App\Services\Work\ContentCoverage;
use App\Support\Ai\AiRouteKeys;
use App\Support\Roles;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * `moxdop:queries:finish` (yakup, 2026-10-11): brings the library to "every query assigned, every service clustered,
 * every site with its idea pool" in one queued chain (FinishQueriesJob): catalog clean-up → unassigned queries asked
 * again → autopilot ticks until idle → clustering without the daily gate → the content titles → a check whose counts
 * the queries page shows ("Son kontrol: …").
 */
final class QueryFinish
{
    public const string STATE_KEY = 'queries:finish';

    public const string CHECK_KEY = 'queries:finish:check';

    /** @return array<string, mixed> */
    public static function state(): array
    {
        $state = Cache::get(self::STATE_KEY);

        return is_array($state) ? $state : [];
    }

    /** @param array<string, mixed> $values */
    public static function record(array $values): void
    {
        Cache::forever(self::STATE_KEY, ['updated_at' => now()->toIso8601String()] + $values + self::state());
    }

    /** @return array<string, mixed>|null the last check */
    public static function lastCheck(): ?array
    {
        $check = Cache::get(self::CHECK_KEY);

        return is_array($check) ? $check : null;
    }

    /** While the Claude API window is open query triage runs on its free credit (Haiku); true when it moved now. */
    public static function triageOnClaudeApi(): bool
    {
        $admin = User::query()->role(Roles::ADMIN)->where('is_active', true)->orderBy('id')->first();
        if (! ClaudeApiWindow::active() || $admin === null) {
            return false;
        }

        return app(AiAssignments::class)->applyOne(AiAssignments::PLAN_RECOMMENDED, AiRouteKeys::QUERIES_TRIAGE, $admin);
    }

    /** Unassigned visible queries AI marked as seen are asked again (the ~53k triage never re-sent). */
    public static function resetUnassigned(): int
    {
        $reset = 0;
        DB::table('queries')->whereNull('service_id')->where('hidden', false)->where('is_suggested', false)->whereNotNull('sector_id')
            ->whereNotNull('ai_checked_at')->select('id')->chunkById(QueryPipeline::CHUNK, function ($rows) use (&$reset): void {
                $reset += DB::table('queries')->whereIn('id', $rows->pluck('id')->all())->update(['ai_checked_at' => null]);
            });

        return $reset;
    }

    /**
     * The counts that say whether the library is finished; kept for the queries page.
     *
     * @return array{at: string, unassigned_unchecked: int, services_without_keywords: int, clusters_inactive_service: int, services_to_cluster: int, sites_short_of_ideas: int, idea_pool: array<string, int>, stale_queues: int}
     */
    public static function check(): array
    {
        $pool = [];
        foreach (app(ContentCoverage::class)->rows() as $row) {
            $pool[(string) ($row['site']->domain ?: $row['site']->name)] = (int) $row['waiting'];
        }
        // Running queues that stopped moving: the clustering queue, and a finish chain that has not moved for 2 hours.
        $state = self::state();
        $chainStale = ($state['stage'] ?? 'done') !== 'done' && isset($state['updated_at'])
            && Carbon::parse((string) $state['updated_at'])->addHours(QueryClusterQueue::STALE_AFTER_HOURS)->isPast();
        $stale = (QueryClusterQueue::stale() ? 1 : 0) + ($chainStale ? 1 : 0);
        $check = [
            'at' => now()->toIso8601String(),
            'unassigned_unchecked' => QueryAutopilot::queue()->count(),
            'services_without_keywords' => DB::table('service_catalog_items as s')->where('s.status', 'active')->whereNull('s.deleted_at')
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('service_matching_keywords as k')->whereColumn('k.service_catalog_item_id', 's.id'))->count(),
            'clusters_inactive_service' => DB::table('clusters as c')->join('service_catalog_items as s', 's.id', '=', 'c.service_id')
                ->where(fn ($q) => $q->where('s.status', '!=', 'active')->orWhereNotNull('s.deleted_at'))->count(),
            'services_to_cluster' => count(app(QueryClusterQueue::class)->servicesToCluster()),
            'sites_short_of_ideas' => count(array_filter($pool, fn (int $waiting): bool => $waiting < ContentCoverage::POOL)),
            'idea_pool' => array_slice($pool, 0, 100, true),
            'stale_queues' => $stale,
        ];
        Cache::forever(self::CHECK_KEY, $check);

        return $check;
    }

    /** "Son kontrol: …" line of the queries page. */
    public static function line(?array $check): ?string
    {
        if ($check === null) {
            return null;
        }

        return sprintf('Son kontrol (%s): bakılmamış hizmetsiz sorgu %s · eşleme kelimesiz hizmet %d · pasif hizmetli küme %d · kümelenecek hizmet %d · fikir havuzu %d altında %d site · takılı kuyruk %d',
            Carbon::parse((string) $check['at'])->timezone('Europe/Istanbul')->format('d.m H:i'),
            number_format((int) $check['unassigned_unchecked'], 0, ',', '.'), (int) $check['services_without_keywords'], (int) $check['clusters_inactive_service'],
            (int) $check['services_to_cluster'], ContentCoverage::POOL, (int) $check['sites_short_of_ideas'], (int) $check['stale_queues']);
    }
}
