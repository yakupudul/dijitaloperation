<?php

namespace App\Services\Observability;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Observability\OperationalAlertState;
use App\Enums\Observability\OperationalHealthStatus;
use App\Models\Collection\CollectionRun;
use App\Models\Observability\OperationalAlert;
use App\Models\Observability\OpsDispatcherHeartbeat;
use App\Services\Async\AsyncWorkerHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Bounded multi-dimension operational snapshot — never averaged into a score.
 *
 * @phpstan-type Dimension array{status: string, message: string, details?: array<string, mixed>}
 */
final class OperationalHealthSnapshot
{
    public function __construct(
        private readonly AsyncWorkerHealth $queueHealth,
        private readonly WorkerHeartbeatService $workers,
        private readonly StuckCollectionDetector $stuck,
    ) {}

    /**
     * @return array{
     *     generated_at: string,
     *     overall_score: null,
     *     dimensions: array<string, Dimension>,
     *     open_alerts: list<array<string, mixed>>,
     *     open_alert_count: int
     * }
     */
    public function snapshot(): array
    {
        $dimensions = [
            'application' => $this->application(),
            'database' => $this->database(),
            'redis' => $this->redis(),
            'queue' => $this->queue(),
            'worker' => $this->worker(),
            'scheduler' => $this->scheduler(),
            'collection' => $this->collection(),
            'storage' => $this->storage(),
        ];

        $alerts = OperationalAlert::query()
            ->whereIn('state', [
                OperationalAlertState::Open->value,
                OperationalAlertState::Acknowledged->value,
            ])
            ->orderByDesc('severity')
            ->orderByDesc('opened_at')
            ->limit(25)
            ->get()
            ->map(fn (OperationalAlert $a): array => [
                'id' => (int) $a->id,
                'rule_key' => $a->rule_key,
                'severity' => $a->severity->value,
                'state' => $a->state->value,
                'title' => $a->title,
                'scope_type' => $a->scope_type,
                'scope_key' => $a->scope_key,
                'opened_at' => $a->opened_at?->toIso8601String(),
            ])
            ->all();

        return [
            'generated_at' => now()->toIso8601String(),
            'overall_score' => null, // FORBIDDEN — dimensions stay explicit
            'dimensions' => $dimensions,
            'open_alerts' => $alerts,
            'open_alert_count' => count($alerts),
        ];
    }

    /**
     * @return Dimension
     */
    private function application(): array
    {
        return [
            'status' => OperationalHealthStatus::Healthy->value,
            'message' => 'Application process is responding.',
            'details' => [
                'env' => (string) config('app.env'),
                'laravel' => app()->version(),
            ],
        ];
    }

    /**
     * @return Dimension
     */
    private function database(): array
    {
        try {
            DB::select('select 1');

            return [
                'status' => OperationalHealthStatus::Healthy->value,
                'message' => 'Database connection OK.',
            ];
        } catch (Throwable $e) {
            return [
                'status' => OperationalHealthStatus::Unhealthy->value,
                'message' => 'Database connection failed.',
                'details' => ['error_code' => 'DB_UNAVAILABLE'],
            ];
        }
    }

    /**
     * @return Dimension
     */
    private function redis(): array
    {
        $driver = (string) config('queue.default');
        if (! in_array($driver, ['redis'], true) && (string) config('cache.default') !== 'redis') {
            return [
                'status' => OperationalHealthStatus::Unknown->value,
                'message' => 'Redis not required by current default queue/cache drivers.',
                'details' => ['queue_default' => $driver, 'cache_default' => config('cache.default')],
            ];
        }

        try {
            Redis::connection()->ping();

            return [
                'status' => OperationalHealthStatus::Healthy->value,
                'message' => 'Redis ping OK.',
            ];
        } catch (Throwable) {
            return [
                'status' => OperationalHealthStatus::Unhealthy->value,
                'message' => 'Redis connectivity failed.',
                'details' => ['error_code' => 'REDIS_UNAVAILABLE'],
            ];
        }
    }

    /**
     * @return Dimension
     */
    private function queue(): array
    {
        $snap = $this->queueHealth->snapshot();
        $status = $snap['worker_appears_idle']
            ? OperationalHealthStatus::Degraded
            : OperationalHealthStatus::Healthy;

        return [
            'status' => $status->value,
            'message' => $snap['message'],
            'details' => [
                'pending_jobs' => $snap['pending_jobs'],
                'oldest_queued_job_age_seconds' => $snap['oldest_queued_job_age_seconds'],
                'queue_driver' => $snap['queue_driver'],
            ],
        ];
    }

    /**
     * @return Dimension
     */
    private function worker(): array
    {
        $snap = $this->workers->snapshot();

        return [
            'status' => $snap['status']->value,
            'message' => $snap['message'],
            'details' => [
                'fresh_heartbeats' => $snap['fresh_heartbeats'],
                'expected_supervisors' => $snap['expected_supervisors'],
            ],
        ];
    }

    /**
     * @return Dimension
     */
    private function scheduler(): array
    {
        $stale = (int) config('moxdop-observability.scheduler.dispatcher_stale_seconds', 600);
        $row = OpsDispatcherHeartbeat::query()->where('dispatcher_key', 'recurring')->first();
        if ($row === null) {
            return [
                'status' => OperationalHealthStatus::Unknown->value,
                'message' => 'Dispatcher heartbeat not yet observed (do not invent cron status).',
            ];
        }

        $age = max(0, now()->getTimestamp() - $row->last_seen_at->getTimestamp());
        if ($age > $stale) {
            return [
                'status' => OperationalHealthStatus::Unhealthy->value,
                'message' => 'Dispatcher heartbeat older than policy.',
                'details' => ['age_seconds' => $age, 'stale_seconds' => $stale],
            ];
        }

        return [
            'status' => OperationalHealthStatus::Healthy->value,
            'message' => 'Dispatcher heartbeat is fresh.',
            'details' => ['age_seconds' => $age],
        ];
    }

    /**
     * @return Dimension
     */
    private function collection(): array
    {
        $running = CollectionRun::query()->where('status', CollectionRunStatus::Running->value)->count();
        $failedRecent = CollectionRun::query()
            ->where('status', CollectionRunStatus::Failed->value)
            ->where('finished_at', '>=', now()->subHour())
            ->count();
        $stuck = count($this->stuck->candidates());

        $status = OperationalHealthStatus::Healthy;
        if ($stuck > 0) {
            $status = OperationalHealthStatus::Degraded;
        }
        if ($failedRecent >= 3) {
            $status = OperationalHealthStatus::Degraded;
        }

        return [
            'status' => $status->value,
            'message' => 'Collection runtime derived from canonical CollectionRun state.',
            'details' => [
                'running' => $running,
                'failed_last_hour' => $failedRecent,
                'stuck_candidates' => $stuck,
            ],
        ];
    }

    /**
     * @return Dimension
     */
    private function storage(): array
    {
        try {
            $disk = Storage::disk(config('filesystems.default', 'local'));
            $probe = 'ops-health-'.uniqid('', true);
            $disk->put($probe, 'ok');
            $disk->delete($probe);

            return [
                'status' => OperationalHealthStatus::Healthy->value,
                'message' => 'Default storage disk write/delete OK.',
            ];
        } catch (Throwable) {
            return [
                'status' => OperationalHealthStatus::Unhealthy->value,
                'message' => 'Default storage disk check failed.',
                'details' => ['error_code' => 'STORAGE_UNAVAILABLE'],
            ];
        }
    }
}
