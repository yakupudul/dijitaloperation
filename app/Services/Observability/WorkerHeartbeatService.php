<?php

namespace App\Services\Observability;

use App\Enums\Observability\OperationalHealthStatus;
use App\Models\Observability\OpsDispatcherHeartbeat;
use App\Models\Observability\WorkerHeartbeat;
use App\Services\Async\AsyncWorkerHealth;

/**
 * Worker + dispatcher heartbeats — infrastructure identity only.
 */
final class WorkerHeartbeatService
{
    public function __construct(
        private readonly AsyncWorkerHealth $asyncWorkerHealth,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function beat(
        string $workerId,
        ?string $supervisor = null,
        ?string $queueClass = null,
        array $metadata = [],
    ): WorkerHeartbeat {
        return WorkerHeartbeat::query()->updateOrCreate(
            ['worker_id' => substr($workerId, 0, 120)],
            [
                'supervisor' => $supervisor !== null ? substr($supervisor, 0, 80) : null,
                'queue_class' => $queueClass !== null ? substr($queueClass, 0, 80) : null,
                'hostname' => gethostname() ?: null,
                'pid' => getmypid() ?: null,
                'last_seen_at' => now(),
                'metadata' => $metadata === [] ? null : $metadata,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function beatDispatcher(string $dispatcherKey = 'recurring', array $metadata = []): OpsDispatcherHeartbeat
    {
        return OpsDispatcherHeartbeat::query()->updateOrCreate(
            ['dispatcher_key' => substr($dispatcherKey, 0, 80)],
            [
                'last_seen_at' => now(),
                'metadata' => $metadata === [] ? null : $metadata,
            ],
        );
    }

    /**
     * @return array{
     *     status: OperationalHealthStatus,
     *     expected_supervisors: list<string>,
     *     fresh_heartbeats: int,
     *     stale_seconds: int,
     *     queue: array<string, mixed>,
     *     message: string
     * }
     */
    public function snapshot(): array
    {
        $staleSeconds = max(30, (int) config('moxdop-observability.worker.heartbeat_stale_seconds', 180));
        $expected = config('moxdop-observability.worker.expected_supervisors', []);
        $expected = is_array($expected) ? array_values(array_filter($expected, 'is_string')) : [];

        $fresh = WorkerHeartbeat::query()
            ->where('last_seen_at', '>=', now()->subSeconds($staleSeconds))
            ->count();

        $queue = $this->asyncWorkerHealth->snapshot();

        $status = OperationalHealthStatus::Unknown;
        $message = 'Worker expected capacity is not configured for this deployment.';

        if ($expected === []) {
            // Without deployment expected list, use queue idle heuristic only.
            if ($queue['worker_appears_idle']) {
                $status = OperationalHealthStatus::Degraded;
                $message = $queue['message'];
            } elseif ($fresh > 0) {
                $status = OperationalHealthStatus::Healthy;
                $message = $fresh.' fresh worker heartbeat(s); expected supervisors not configured.';
            } else {
                $status = OperationalHealthStatus::Unknown;
                $message = 'No worker heartbeats observed; expected supervisors not configured.';
            }
        } else {
            $freshSupervisors = WorkerHeartbeat::query()
                ->where('last_seen_at', '>=', now()->subSeconds($staleSeconds))
                ->whereIn('supervisor', $expected)
                ->pluck('supervisor')
                ->unique()
                ->values()
                ->all();
            $missing = array_values(array_diff($expected, $freshSupervisors));
            if ($missing === []) {
                $status = OperationalHealthStatus::Healthy;
                $message = 'All expected supervisors have fresh heartbeats.';
            } elseif (count($freshSupervisors) > 0) {
                $status = OperationalHealthStatus::Degraded;
                $message = 'Missing supervisors: '.implode(', ', $missing);
            } else {
                $status = OperationalHealthStatus::Unhealthy;
                $message = 'No expected supervisors have fresh heartbeats.';
            }
        }

        return [
            'status' => $status,
            'expected_supervisors' => $expected,
            'fresh_heartbeats' => $fresh,
            'stale_seconds' => $staleSeconds,
            'queue' => $queue,
            'message' => $message,
        ];
    }
}
