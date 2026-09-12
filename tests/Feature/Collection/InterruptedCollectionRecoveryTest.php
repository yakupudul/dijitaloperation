<?php

namespace Tests\Feature\Collection;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Collection\DatasetExecutionOutcome;
use App\Jobs\Collection\ExecuteDatasetRunJob;
use App\Models\Collection\CollectionDatasetRun;
use App\Services\Collection\DatasetExecutorResolver;
use App\Services\Collection\RecoverInterruptedCollections;
use App\Services\Collection\Support\DatasetExecutionResult;
use App\Services\Collection\Testing\FakeDatasetExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class InterruptedCollectionRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Event::fake();
        config(['cache.default' => 'array', 'moxdop-collection.stale_running_seconds' => 1800,
            'moxdop-collection.job_timeout_seconds' => 300]);
    }

    #[Test]
    public function expired_execution_resumes_saved_checkpoint_and_stops_after_repeated_interruptions(): void
    {
        $dataset = $this->interrupted();
        $checkpoint = $dataset->checkpoint;
        $service = app(RecoverInterruptedCollections::class);
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $dataset->forceFill(['status' => CollectionRunStatus::Running,
                'last_activity_at' => now()->subHour(), 'dispatch_locked_at' => now()->subHour()])->save();
            $service->tick();
            $dataset->refresh();
            $this->assertSame($checkpoint, $dataset->checkpoint);
            $this->assertNull($dataset->dispatch_lock_token);
            $this->assertSame($attempt, data_get($dataset->metadata, 'interruption_recovery.attempts'));
            $this->assertSame($attempt < 4 ? CollectionRunStatus::Retrying : CollectionRunStatus::Failed, $dataset->status);
        }
        $this->assertSame(CollectionRunStatus::Failed, $dataset->collectionRun->fresh()->status);
    }

    #[Test]
    public function recent_lease_and_scheduled_retry_are_not_recovered(): void
    {
        $recent = $this->interrupted();
        $recent->update(['dispatch_locked_at' => now()]);
        $waiting = $this->interrupted();
        $waiting->update(['status' => CollectionRunStatus::Retrying, 'retry_at' => now()->addHour()]);

        app(RecoverInterruptedCollections::class)->tick();

        $this->assertSame(CollectionRunStatus::Running, $recent->fresh()->status);
        $this->assertSame(CollectionRunStatus::Retrying, $waiting->fresh()->status);
        $this->assertNull(data_get($recent->fresh()->metadata, 'interruption_recovery'));
        $this->assertNull(data_get($waiting->fresh()->metadata, 'interruption_recovery'));
    }

    #[Test]
    public function failed_prerequisite_settles_waiting_child_and_terminal_datasets_settle_parent(): void
    {
        $failed = $this->interrupted();
        $failed->update(['status' => CollectionRunStatus::Failed]);
        $child = CollectionDatasetRun::factory()->create([
            'collection_run_id' => $failed->collection_run_id,
            'collection_resource_run_id' => $failed->collection_resource_run_id,
            'depends_on_dataset_run_ids' => [$failed->id],
        ]);
        $completed = $this->interrupted();
        $completed->update(['status' => CollectionRunStatus::Completed]);

        app(RecoverInterruptedCollections::class)->tick();

        $this->assertSame(CollectionRunStatus::Failed, $child->fresh()->status);
        $this->assertSame('DEPENDENCY_FAILED', $child->fresh()->error_code);
        $this->assertSame(CollectionRunStatus::Failed, $failed->collectionRun->fresh()->status);
        $this->assertSame(CollectionRunStatus::Completed, $completed->collectionRun->fresh()->status);
        $this->assertSame(CollectionRunStatus::Completed, $completed->fresh()->status);
    }

    #[Test]
    public function delayed_continuation_persists_backoff_and_early_delivery_does_not_execute_again(): void
    {
        $dataset = $this->interrupted();
        $dataset->update(['status' => CollectionRunStatus::Queued, 'dispatch_lock_token' => null, 'dispatch_locked_at' => null]);
        $this->app->instance(DatasetExecutorResolver::class, new DatasetExecutorResolver([
            new FakeDatasetExecutor([$dataset->request_family_id], new DatasetExecutionResult(
                outcome: DatasetExecutionOutcome::Continue, checkpoint: ['report_id' => 'saved-report'], backoffSeconds: 120,
            )),
        ]));

        $this->app->call([new ExecuteDatasetRunJob($dataset->id), 'handle']);
        $dataset->refresh();
        $this->assertSame(CollectionRunStatus::Retrying, $dataset->status);
        $this->assertTrue($dataset->retry_at->isFuture());
        $this->assertSame('saved-report', $dataset->checkpoint['report_id']);
        $attempts = $dataset->attempt_count;

        $this->app->call([new ExecuteDatasetRunJob($dataset->id), 'handle']);
        $this->assertSame($attempts, $dataset->fresh()->attempt_count);
    }

    private function interrupted(): CollectionDatasetRun
    {
        $dataset = CollectionDatasetRun::factory()->create([
            'status' => CollectionRunStatus::Running, 'checkpoint' => ['page' => 7],
            'last_activity_at' => now()->subHour(), 'dispatch_lock_token' => 'expired-token',
            'dispatch_locked_at' => now()->subHour(),
        ]);
        $dataset->collectionRun->update(['status' => CollectionRunStatus::Running]);

        return $dataset;
    }
}
