<?php

namespace App\Services\Operations;

use App\Models\Observability\OpsDispatcherHeartbeat;
use App\Models\Observability\WorkerHeartbeat;
use App\Services\Assistant\PushNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 13: watches the scheduler and the workers from OUTSIDE the scheduler. It is run by its own system cron line
 * (deploy/staging/cron.example), so a dead `schedule:run` is still noticed: stale scheduler heartbeat, stale queue
 * heartbeats, and a growing `jobs` backlog when the default queue is the database. Problems go to the phone.
 */
final class OpsWatchdog
{
    public const string LAST_RUN_KEY = 'ops:watchdog:last_run';

    public function __construct(private readonly PushNotifier $push) {}

    /** @return list<string> the problems found */
    public function run(): array
    {
        Cache::forever(self::LAST_RUN_KEY, now()->toIso8601String());
        $problems = [];

        $last = OpsDispatcherHeartbeat::query()->max('last_seen_at');
        $minutes = $last !== null ? (int) CarbonImmutable::parse((string) $last)->diffInMinutes(now()) : null;
        if ($minutes === null || $minutes > (int) config('moxdop-observability.watchdog.scheduler_minutes', 15)) {
            $problems[] = $minutes === null ? 'Zamanlayıcı hiç çalışmamış.' : 'Zamanlayıcı '.$minutes.' dakikadır çalışmıyor (cron / schedule:run).';
        }

        $stale = WorkerHeartbeat::query()->where('worker_id', 'like', 'queue:%')
            ->where('last_seen_at', '<', now()->subMinutes((int) config('moxdop-observability.watchdog.queue_minutes', 20)))->pluck('worker_id')->all();
        if ($stale !== []) {
            $problems[] = 'Kuyruk işçisi yanıt vermiyor: '.implode(', ', $stale).'.';
        }

        if (config('queue.connections.'.config('queue.default').'.driver') === 'database' && Schema::hasTable('jobs')) {
            $oldest = DB::table('jobs')->whereNull('reserved_at')->min('available_at');
            $waiting = $oldest !== null ? (int) floor((now()->getTimestamp() - (int) $oldest) / 60) : 0;
            if ($waiting > (int) config('moxdop-observability.watchdog.backlog_minutes', 30)) {
                $problems[] = 'Arka plan işleri birikiyor: en eski iş '.$waiting.' dakikadır bekliyor (QUEUE_CONNECTION=database ve çalışan bir database işçisi yok olabilir).';
            }
        }

        if (($disk = app(StorageGuard::class)->warning()) !== null) {
            $problems[] = $disk;
        }

        if ($problems !== []) {
            $this->push->send('ops-watchdog:'.md5(implode('|', $problems)), 'Sistem izleme uyarısı', implode(' ', $problems), 'critical',
                route('operator.settings.system-health'), 2);
        }

        return $problems;
    }

    /** @return array{last_run_at: ?string, installed: bool} */
    public static function status(): array
    {
        $last = Cache::get(self::LAST_RUN_KEY);

        return ['last_run_at' => is_string($last) ? $last : null, 'installed' => is_string($last) && CarbonImmutable::parse($last)->greaterThan(now()->subMinutes(20))];
    }
}
