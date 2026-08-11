<?php

namespace App\Services\Async;

use App\Models\Run;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bounded queue-worker health signal for Activity Center (no Prometheus).
 */
final class AsyncWorkerHealth
{
    /**
     * @return array{
     *     queue_driver: string,
     *     pending_jobs: int,
     *     oldest_queued_job_age_seconds: int|null,
     *     last_processed_at: string|null,
     *     worker_appears_idle: bool,
     *     message: string
     * }
     */
    public function snapshot(): array
    {
        $driver = (string) config('queue.default');
        $pending = 0;
        $oldestAge = null;

        if ($driver === 'database' && Schema::hasTable('jobs')) {
            $pending = (int) DB::table('jobs')->count();
            $availableAt = DB::table('jobs')->orderBy('available_at')->value('available_at');
            if ($availableAt !== null) {
                $oldestAge = max(0, now()->getTimestamp() - (int) $availableAt);
            }
        }

        $lastProcessed = Run::query()
            ->where('metadata->async', true)
            ->whereIn('status', ['completed', 'partial', 'failed'])
            ->orderByDesc('finished_at')
            ->value('finished_at');

        $workerAppearsIdle = $pending > 0 && $oldestAge !== null && $oldestAge >= 120;

        $message = match (true) {
            $driver !== 'database' => 'Queue driver is '.$driver.'. Worker health signal is limited.',
            $pending === 0 => 'No queued jobs waiting.',
            $workerAppearsIdle => 'Jobs are waiting and the oldest is over 2 minutes old — queue worker may not be running.',
            default => $pending.' job(s) waiting; worker appears active or recently started.',
        };

        return [
            'queue_driver' => $driver,
            'pending_jobs' => $pending,
            'oldest_queued_job_age_seconds' => $oldestAge,
            'last_processed_at' => $lastProcessed instanceof Carbon
                ? $lastProcessed->toIso8601String()
                : ($lastProcessed !== null ? (string) $lastProcessed : null),
            'worker_appears_idle' => $workerAppearsIdle,
            'message' => $message,
        ];
    }
}
