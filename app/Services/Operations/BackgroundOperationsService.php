<?php

namespace App\Services\Operations;

use App\Enums\Collection\CollectionRunStatus;
use App\Models\AgentExecutionRun;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionRun;
use App\Models\Run;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class BackgroundOperationsService
{
    /**
     * @return array<string,mixed>
     */
    public function snapshot(string $status = 'active', string $provider = 'all', string $search = ''): array
    {
        $collectionRuns = $this->collectionRuns($status, $provider, $search);
        $failedJobs = $this->failedJobs();
        $queueDepths = $this->queueDepths();
        $agentRuns = $this->agentRuns();
        $legacyRuns = $this->legacyRuns();

        $active = $collectionRuns->whereIn('status', [
            CollectionRunStatus::Queued->value,
            CollectionRunStatus::Running->value,
            CollectionRunStatus::Retrying->value,
            CollectionRunStatus::CancellationRequested->value,
        ]);

        return [
            'summary' => [
                'active_collection_runs' => $active->count(),
                'stalled_collection_runs' => $active->where('stalled', true)->count(),
                'retrying_datasets' => $collectionRuns->sum('retrying_count'),
                'locked_datasets' => $collectionRuns->sum('locked_count'),
                'failed_jobs' => count($failedJobs),
                'queue_depth' => array_sum(array_column($queueDepths, 'size')),
                'running_agents' => collect($agentRuns)->where('status', AgentExecutionRun::STATUS_RUNNING)->count(),
            ],
            'providers' => $this->providers(),
            'collection_runs' => $collectionRuns->values()->all(),
            'agent_runs' => $agentRuns,
            'legacy_runs' => $legacyRuns,
            'failed_jobs' => $failedJobs,
            'queues' => $queueDepths,
            'infrastructure' => $this->infrastructure($collectionRuns),
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int,array<string,mixed>>
     */
    private function collectionRuns(string $status, string $provider, string $search)
    {
        $query = CollectionRun::query()
            ->with([
                'requestedBy:id,name,email',
                'customer:id,name',
                'brand:id,name',
                'digitalAsset:id,name,type,brand_id',
                'resourceRuns',
                'datasetRuns' => fn ($q) => $q->orderBy('id'),
            ])
            ->orderByDesc('id')
            ->limit(150);

        if ($status === 'active') {
            $query->whereIn('status', [
                CollectionRunStatus::Queued->value,
                CollectionRunStatus::Running->value,
                CollectionRunStatus::Retrying->value,
                CollectionRunStatus::CancellationRequested->value,
            ]);
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }

        $runs = $query->get();

        if ($provider !== 'all') {
            $wanted = strtoupper($provider);
            $runs = $runs->filter(fn (CollectionRun $run): bool => $run->datasetRuns->contains(
                fn (CollectionDatasetRun $dataset): bool => strtoupper((string) $dataset->provider_or_source) === $wanted
            ));
        }

        $needle = mb_strtolower(trim($search));
        if ($needle !== '') {
            $runs = $runs->filter(function (CollectionRun $run) use ($needle): bool {
                $haystack = mb_strtolower(implode(' ', array_filter([
                    '#'.$run->id,
                    $run->digitalAsset?->name,
                    $run->brand?->name,
                    $run->customer?->name,
                    data_get($run->metadata, 'collection_intent_label'),
                    $run->datasetRuns->pluck('provider_or_source')->implode(' '),
                    $run->datasetRuns->pluck('request_family_id')->implode(' '),
                ])));

                return str_contains($haystack, $needle);
            });
        }

        return $runs->map(function (CollectionRun $run): array {
            $datasets = $run->datasetRuns;
            $activeStatuses = [
                CollectionRunStatus::Queued,
                CollectionRunStatus::Running,
                CollectionRunStatus::Retrying,
                CollectionRunStatus::CancellationRequested,
            ];
            $active = in_array($run->status, $activeStatuses, true);
            $lastActivity = $run->last_activity_at;
            $stalled = $active && ($lastActivity === null || $lastActivity->lt(now()->subMinutes(5)));
            $providers = $datasets->pluck('provider_or_source')->filter()->map(fn ($value) => strtoupper((string) $value))->unique()->values();
            $staleLockCutoff = now()->subMinutes(15);

            $datasetRows = $datasets->map(function (CollectionDatasetRun $dataset) use ($staleLockCutoff): array {
                $locked = filled($dataset->dispatch_lock_token);
                $staleLock = $locked && ($dataset->dispatch_locked_at === null || $dataset->dispatch_locked_at->lt($staleLockCutoff));

                return [
                    'id' => (int) $dataset->id,
                    'provider' => strtoupper((string) $dataset->provider_or_source),
                    'family' => (string) $dataset->request_family_id,
                    'dataset' => (string) $dataset->dataset_contract_id,
                    'variant' => (string) ($dataset->execution_variant ?? ''),
                    'status' => $dataset->status->value,
                    'attempts' => (int) $dataset->attempt_count,
                    'max_attempts' => (int) $dataset->max_attempts,
                    'rows_received' => (int) $dataset->rows_received,
                    'rows_written' => (int) $dataset->rows_written,
                    'pages' => (int) $dataset->pages_completed,
                    'stage' => $dataset->stage,
                    'retry_at' => $dataset->retry_at?->toIso8601String(),
                    'last_activity' => $dataset->last_activity_at?->toIso8601String(),
                    'last_activity_human' => $dataset->last_activity_at?->diffForHumans(),
                    'locked' => $locked,
                    'stale_lock' => $staleLock,
                    'lock_age' => $dataset->dispatch_locked_at?->diffForHumans(),
                    'error_category' => $dataset->error_category?->value,
                    'error_code' => $dataset->error_code,
                    'error_message' => $dataset->error_message,
                    'date_range' => data_get($dataset->metadata, 'date_range'),
                ];
            })->values()->all();

            $completed = $datasets->where('status', CollectionRunStatus::Completed)->count();
            $failed = $datasets->where('status', CollectionRunStatus::Failed)->count();
            $retrying = $datasets->where('status', CollectionRunStatus::Retrying)->count();
            $running = $datasets->where('status', CollectionRunStatus::Running)->count();
            $queued = $datasets->where('status', CollectionRunStatus::Queued)->count();
            $locked = collect($datasetRows)->where('locked', true)->count();
            $staleLocks = collect($datasetRows)->where('stale_lock', true)->count();

            return [
                'id' => (int) $run->id,
                'uuid' => (string) $run->uuid,
                'status' => $run->status->value,
                'trigger' => $run->trigger_type->value,
                'title' => (string) (data_get($run->metadata, 'collection_intent_label') ?: 'Collection Run #'.$run->id),
                'providers' => $providers->all(),
                'provider_label' => $providers->implode(', ') ?: '—',
                'scope' => $run->digitalAsset?->name
                    ?: ($run->brand?->name ?: ($run->customer?->name ?: 'Provider / Integration')),
                'asset' => $run->digitalAsset?->name,
                'asset_type' => $run->digitalAsset?->type,
                'brand' => $run->brand?->name,
                'customer' => $run->customer?->name,
                'requested_by' => $run->requestedBy?->name,
                'started_at' => $run->started_at?->toIso8601String(),
                'last_activity' => $lastActivity?->toIso8601String(),
                'last_activity_human' => $lastActivity?->diffForHumans(),
                'finished_at' => $run->finished_at?->toIso8601String(),
                'stalled' => $stalled,
                'progress' => [
                    'total' => (int) $run->datasets_total,
                    'completed' => $completed,
                    'failed' => $failed,
                    'queued' => $queued,
                    'running' => $running,
                    'retrying' => $retrying,
                    'rows' => (int) $datasets->sum('rows_written'),
                    'attempts' => (int) $datasets->sum('attempt_count'),
                ],
                'retrying_count' => $retrying,
                'locked_count' => $locked,
                'stale_lock_count' => $staleLocks,
                'can_cancel' => ! $run->status->isTerminal(),
                'can_wake' => in_array($run->status, [CollectionRunStatus::Queued, CollectionRunStatus::Running, CollectionRunStatus::Retrying], true),
                'can_retry_now' => $retrying > 0,
                'can_release_stale_locks' => $staleLocks > 0,
                'failure_summary' => $run->failure_summary,
                'datasets' => $datasetRows,
            ];
        });
    }

    /** @return list<string> */
    private function providers(): array
    {
        if (! Schema::hasTable('collection_dataset_runs')) {
            return [];
        }

        return CollectionDatasetRun::query()
            ->whereNotNull('provider_or_source')
            ->distinct()
            ->orderBy('provider_or_source')
            ->pluck('provider_or_source')
            ->map(fn ($value): string => strtoupper((string) $value))
            ->values()
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function agentRuns(): array
    {
        if (! Schema::hasTable('agent_execution_runs')) {
            return [];
        }

        return AgentExecutionRun::query()
            ->with(['digitalAsset:id,name,type', 'brand:id,name', 'customer:id,name'])
            ->orderByDesc('id')
            ->limit(40)
            ->get()
            ->map(fn (AgentExecutionRun $run): array => [
                'id' => (int) $run->id,
                'agent' => (string) $run->agent_slug,
                'route' => (string) $run->ai_route_key,
                'status' => (string) $run->status,
                'scope' => $run->digitalAsset?->name ?: ($run->brand?->name ?: ($run->customer?->name ?: 'System')),
                'started_at' => $run->started_at?->toIso8601String(),
                'completed_at' => $run->completed_at?->toIso8601String(),
                'age' => $run->started_at?->diffForHumans(),
                'reason' => $run->block_reason_code,
            ])
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function legacyRuns(): array
    {
        if (! Schema::hasTable('runs')) {
            return [];
        }

        return Run::query()
            ->with('digitalAsset:id,name,type')
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(fn (Run $run): array => [
                'id' => (int) $run->id,
                'status' => (string) $run->status,
                'module' => (string) ($run->module_id ?? '—'),
                'scope' => $run->digitalAsset?->name ?: 'System',
                'started_at' => $run->started_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
            ])
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function failedJobs(): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return [];
        }

        return DB::table('failed_jobs')
            ->orderByDesc('id')
            ->limit(40)
            ->get()
            ->map(function ($job): array {
                $payload = json_decode((string) $job->payload, true);
                $display = is_array($payload) ? ($payload['displayName'] ?? data_get($payload, 'data.commandName')) : null;

                return [
                    'id' => (int) $job->id,
                    'uuid' => (string) $job->uuid,
                    'connection' => (string) $job->connection,
                    'queue' => (string) $job->queue,
                    'job' => (string) ($display ?: 'Queued job'),
                    'failed_at' => (string) $job->failed_at,
                    'exception' => mb_substr((string) $job->exception, 0, 1200),
                ];
            })
            ->all();
    }

    /** @return list<array{name:string,size:int,error:?string}> */
    private function queueDepths(): array
    {
        $connection = (string) config('moxdop-collection.queue_connection', config('queue.default', 'redis'));
        $names = array_values(array_unique(array_filter([
            (string) config('moxdop-collection.queue', 'collection'),
            'default',
            'async',
            'ai',
            'notifications',
        ])));

        return collect($names)->map(function (string $name) use ($connection): array {
            try {
                return ['name' => $name, 'size' => (int) Queue::connection($connection)->size($name), 'error' => null];
            } catch (Throwable $e) {
                return ['name' => $name, 'size' => 0, 'error' => $e->getMessage()];
            }
        })->all();
    }

    /** @param \Illuminate\Support\Collection<int,array<string,mixed>> $runs */
    private function infrastructure($runs): array
    {
        $redisOk = false;
        $redisError = null;
        try {
            Redis::connection()->ping();
            $redisOk = true;
        } catch (Throwable $e) {
            $redisError = $e->getMessage();
        }

        $active = $runs->whereIn('status', [
            CollectionRunStatus::Queued->value,
            CollectionRunStatus::Running->value,
            CollectionRunStatus::Retrying->value,
            CollectionRunStatus::CancellationRequested->value,
        ]);
        $latestActivity = $active->pluck('last_activity')->filter()->sortDesc()->first();

        return [
            'redis_ok' => $redisOk,
            'redis_error' => $redisError,
            'queue_connection' => (string) config('moxdop-collection.queue_connection', 'redis'),
            'collection_queue' => (string) config('moxdop-collection.queue', 'collection'),
            'latest_collection_activity' => $latestActivity,
            'active_stalled' => $active->where('stalled', true)->count(),
        ];
    }
}
