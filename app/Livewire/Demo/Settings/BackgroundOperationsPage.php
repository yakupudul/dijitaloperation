<?php

namespace App\Livewire\Demo\Settings;

use App\Enums\Collection\CollectionRunStatus;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionRun;
use App\Services\Collection\CancellationService;
use App\Services\Collection\StartCollectionService;
use App\Services\Operations\BackgroundOperationsService;
use App\Support\Demo\DemoState;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

#[Layout('operator.layouts.app')]
#[Title('Background Operations')]
class BackgroundOperationsPage extends Component
{
    #[Url(history: true)]
    public string $status = 'active';

    #[Url(history: true)]
    public string $provider = 'all';

    #[Url(as: 'q', history: true)]
    public string $search = '';

    public ?int $expandedRunId = null;

    public function mount(): void
    {
        $this->normalizeFilters();
    }

    public function updatedStatus(): void
    {
        $this->normalizeFilters();
    }

    public function updatedProvider(): void
    {
        $this->provider = $this->provider === 'all' ? 'all' : strtoupper(trim($this->provider));
    }

    public function toggleRun(int $runId): void
    {
        $this->expandedRunId = $this->expandedRunId === $runId ? null : $runId;
    }

    public function cancelRun(int $runId): void
    {
        $this->assertAdmin();
        $run = CollectionRun::query()->findOrFail($runId);
        app(CancellationService::class)->requestCancellation($run);
        DemoState::flash(__('background_operations.flash.cancelled', ['id' => $runId]));
    }

    public function wakeRun(int $runId): void
    {
        $this->assertAdmin();
        $run = CollectionRun::query()->with('datasetRuns')->findOrFail($runId);

        if ($run->status->isTerminal()) {
            DemoState::flash(__('background_operations.flash.terminal'));
            return;
        }

        $staleCutoff = now()->subMinutes(15);
        foreach ($run->datasetRuns as $dataset) {
            if (! in_array($dataset->status, [CollectionRunStatus::Queued, CollectionRunStatus::Retrying], true)) {
                continue;
            }

            $metadata = is_array($dataset->metadata) ? $dataset->metadata : [];
            unset($metadata['queue_dispatch_claimed'], $metadata['queue_dispatch_claimed_at']);

            $updates = [
                'metadata' => $metadata,
                'last_activity_at' => now(),
            ];

            if ($dataset->status === CollectionRunStatus::Retrying) {
                $updates['retry_at'] = now();
            }

            if ($dataset->dispatch_lock_token !== null
                && ($dataset->dispatch_locked_at === null || $dataset->dispatch_locked_at->lt($staleCutoff))) {
                $updates['dispatch_lock_token'] = null;
                $updates['dispatch_locked_at'] = null;
            }

            $dataset->forceFill($updates)->save();
        }

        $run->forceFill(['last_activity_at' => now()])->save();
        app(StartCollectionService::class)->dispatchEligibleRootJobs($run->fresh() ?? $run);
        DemoState::flash(__('background_operations.flash.woken', ['id' => $runId]));
    }

    public function retryNow(int $runId): void
    {
        $this->assertAdmin();
        $run = CollectionRun::query()->findOrFail($runId);

        $count = CollectionDatasetRun::query()
            ->where('collection_run_id', $runId)
            ->where('status', CollectionRunStatus::Retrying->value)
            ->update([
                'retry_at' => now(),
                'last_activity_at' => now(),
            ]);

        if ($count > 0) {
            $run->forceFill(['last_activity_at' => now()])->save();
        }

        DemoState::flash(__('background_operations.flash.retry_now', ['count' => $count]));
    }

    public function releaseStaleLocks(int $runId): void
    {
        $this->assertAdmin();
        $count = CollectionDatasetRun::query()
            ->where('collection_run_id', $runId)
            ->whereNotNull('dispatch_lock_token')
            ->where(function ($query): void {
                $query->whereNull('dispatch_locked_at')
                    ->orWhere('dispatch_locked_at', '<', now()->subMinutes(15));
            })
            ->whereIn('status', [
                CollectionRunStatus::Queued->value,
                CollectionRunStatus::Retrying->value,
                CollectionRunStatus::Running->value,
            ])
            ->update([
                'dispatch_lock_token' => null,
                'dispatch_locked_at' => null,
                'last_activity_at' => now(),
            ]);

        DemoState::flash(__('background_operations.flash.locks_released', ['count' => $count]));
    }

    public function retryFailedJob(string $uuid): void
    {
        $this->assertAdmin();
        if (! Schema::hasTable('failed_jobs')) {
            return;
        }

        $job = DB::table('failed_jobs')->where('uuid', $uuid)->first();
        if ($job === null) {
            DemoState::flash(__('background_operations.flash.failed_job_missing'));
            return;
        }

        try {
            Queue::connection((string) $job->connection)->pushRaw((string) $job->payload, (string) $job->queue);
            DB::table('failed_jobs')->where('uuid', $uuid)->delete();
            DemoState::flash(__('background_operations.flash.failed_job_retried'));
        } catch (Throwable $e) {
            report($e);
            DemoState::flash(__('background_operations.flash.failed_job_retry_error', ['message' => $e->getMessage()]));
        }
    }

    public function forgetFailedJob(string $uuid): void
    {
        $this->assertAdmin();
        if (! Schema::hasTable('failed_jobs')) {
            return;
        }

        DB::table('failed_jobs')->where('uuid', $uuid)->delete();
        DemoState::flash(__('background_operations.flash.failed_job_forgotten'));
    }

    public function render(BackgroundOperationsService $service): View
    {
        $snapshot = $service->snapshot($this->status, $this->provider, $this->search);

        return view('livewire.demo.settings.background-operations', [
            'snapshot' => $snapshot,
            'flash' => DemoState::pullFlash(),
            'isAdmin' => auth()->user()?->hasRole(Roles::ADMIN) ?? false,
            'statusOptions' => [
                'active' => __('background_operations.filters.active'),
                'all' => __('background_operations.filters.all'),
                'queued' => __('background_operations.status.queued'),
                'running' => __('background_operations.status.running'),
                'retrying' => __('background_operations.status.retrying'),
                'failed' => __('background_operations.status.failed'),
                'completed' => __('background_operations.status.completed'),
                'cancelled' => __('background_operations.status.cancelled'),
            ],
        ])->layoutData(['title' => __('background_operations.title')]);
    }

    private function normalizeFilters(): void
    {
        $allowed = ['active', 'all', 'queued', 'running', 'retrying', 'failed', 'completed', 'cancelled'];
        if (! in_array($this->status, $allowed, true)) {
            $this->status = 'active';
        }
    }

    private function assertAdmin(): void
    {
        $user = auth()->user();
        abort_unless($user !== null && $user->hasRole(Roles::ADMIN), 403);
    }
}
