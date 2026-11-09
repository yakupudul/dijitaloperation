<?php

namespace App\Services\Operations;

use App\Support\SchemaState;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Kullanım defteri (kök neden planı madde 5, yakup 2026-11-09): what is actually used, so pruning is decided from data
 * and not guessed. The operator's page opens (screen) and Livewire actions (action), and every scheduled job run (job:
 * duration, failures), counted per Istanbul day in usage_daily. MCP system-health `usage` lists screens nobody opened
 * and jobs with their cost over the last 30 days. Recording never breaks a request or a job.
 */
final class UsageLedger
{
    public const string SCREEN = 'screen';

    public const string ACTION = 'action';

    public const string JOB = 'job';

    public const int KEEP_DAYS = 120;

    public function record(string $kind, string $key, int $ms = 0, bool $failed = false): void
    {
        try {
            if (! SchemaState::hasTable('usage_daily')) {
                return;
            }
            $key = mb_substr($key, 0, 191);
            $day = now('Europe/Istanbul')->toDateString();
            $identity = ['kind' => $kind, 'key' => $key, 'day' => $day];
            $updated = DB::table('usage_daily')->where($identity)->update([
                'count' => DB::raw('count + 1'), 'failures' => DB::raw('failures + '.($failed ? 1 : 0)),
                'total_ms' => DB::raw('total_ms + '.max(0, $ms)), 'last_at' => now(),
            ]);
            if ($updated === 0) {
                DB::table('usage_daily')->insertOrIgnore($identity + ['count' => 1, 'failures' => $failed ? 1 : 0, 'total_ms' => max(0, $ms), 'last_at' => now()]);
            }
        } catch (Throwable) {
            // Measuring never breaks a page or a job.
        }
    }

    public function jobFinished(ScheduledTaskFinished $event): void
    {
        $this->record(self::JOB, self::jobKey($event->task), (int) round($event->runtime * 1000), ($event->task->exitCode ?? 0) !== 0);
    }

    public function jobFailed(ScheduledTaskFailed $event): void
    {
        $this->record(self::JOB, self::jobKey($event->task), 0, true);
    }

    public static function jobKey(ScheduledEvent $task): string
    {
        $command = (string) ($task->command ?? '');
        if ($command !== '' && preg_match("/artisan'?\\s+(.+)$/", $command, $m) === 1) {
            return trim(str_replace(["'", '"'], '', $m[1]));
        }

        return (string) ($task->description ?: 'closure');
    }

    /**
     * The last $days days: every key with its runs, failures and time; screens of the menu nobody opened are found by
     * comparing with the route list (unused_screens).
     *
     * @return array{days: int, since: string, screens: list<array<string, mixed>>, actions: list<array<string, mixed>>, jobs: list<array<string, mixed>>, unused_screens: list<string>}
     */
    public function summary(int $days = 30): array
    {
        $since = now('Europe/Istanbul')->subDays($days)->toDateString();
        $first = SchemaState::hasTable('usage_daily') ? DB::table('usage_daily')->min('day') : null;
        $rows = SchemaState::hasTable('usage_daily') ? DB::table('usage_daily')->where('day', '>=', $since)
            ->groupBy('kind', 'key')->selectRaw('kind, key, sum(count) as runs, sum(failures) as failures, sum(total_ms) as ms, max(last_at) as last_at')->get() : collect();
        $of = fn (string $kind): array => $rows->where('kind', $kind)->sortByDesc(fn ($r): int => $kind === self::JOB ? (int) $r->ms : (int) $r->runs)
            ->map(fn ($r): array => ['key' => (string) $r->key, 'runs' => (int) $r->runs, 'failures' => (int) $r->failures,
                'total_min' => round(((int) $r->ms) / 60000, 1), 'last_at' => $r->last_at])->values()->all();
        $opened = $rows->where('kind', self::SCREEN)->pluck('key')->all();
        $screens = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route): bool => in_array('GET', $route->methods(), true) && str_starts_with((string) $route->getName(), 'operator.') && ! str_contains($route->uri(), '{'))
            ->map(fn ($route): string => (string) $route->getName())->unique()->values();

        return ['days' => $days, 'since' => (string) ($first ?? $since), 'screens' => $of(self::SCREEN), 'actions' => array_slice($of(self::ACTION), 0, 60), 'jobs' => $of(self::JOB),
            'unused_screens' => $screens->reject(fn (string $name): bool => in_array($name, $opened, true))->values()->all()];
    }

    public function prune(): int
    {
        return SchemaState::hasTable('usage_daily') ? DB::table('usage_daily')->where('day', '<', now()->subDays(self::KEEP_DAYS)->toDateString())->delete() : 0;
    }
}
