<?php

namespace Tests\Unit\Collection;

use App\Enums\Collection\CollectionErrorCategory;
use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Collection\ProgressMode;
use App\Enums\Collection\RequirementLevel;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Services\Collection\CheckpointManager;
use App\Services\Collection\CollectionErrorRecorder;
use App\Services\Collection\CollectionStateMachine;
use App\Services\Collection\CollectionStatusAggregator;
use App\Services\Collection\DefaultRetryPolicy;
use App\Services\Collection\ProgressReporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CollectionEngineUnitTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function state_machine_allows_and_blocks_transitions(): void
    {
        $machine = app(CollectionStateMachine::class);
        $run = CollectionRun::factory()->create(['status' => CollectionRunStatus::Queued]);

        $machine->transition($run, CollectionRunStatus::Running);
        $this->assertSame(CollectionRunStatus::Running, $run->fresh()->status);

        $this->expectException(InvalidArgumentException::class);
        $machine->transition($run->fresh(), CollectionRunStatus::Queued);
    }

    #[Test]
    public function terminal_states_reject_further_transitions(): void
    {
        $machine = app(CollectionStateMachine::class);
        $run = CollectionRun::factory()->create(['status' => CollectionRunStatus::Completed]);

        $this->expectException(InvalidArgumentException::class);
        $machine->transition($run, CollectionRunStatus::Running);
    }

    #[Test]
    public function aggregation_required_failure_with_siblings_is_partial(): void
    {
        $run = CollectionRun::factory()->create(['status' => CollectionRunStatus::Running, 'datasets_total' => 2]);
        $resource = CollectionResourceRun::factory()->create([
            'collection_run_id' => $run->id,
            'status' => CollectionRunStatus::Running,
            'datasets_total' => 2,
        ]);

        CollectionDatasetRun::factory()->create([
            'collection_run_id' => $run->id,
            'collection_resource_run_id' => $resource->id,
            'status' => CollectionRunStatus::Completed,
            'requirement_level' => RequirementLevel::Required,
            'request_family_id' => 'A',
            'dataset_contract_id' => 'a',
        ]);
        CollectionDatasetRun::factory()->create([
            'collection_run_id' => $run->id,
            'collection_resource_run_id' => $resource->id,
            'status' => CollectionRunStatus::Failed,
            'requirement_level' => RequirementLevel::Required,
            'request_family_id' => 'B',
            'dataset_contract_id' => 'b',
        ]);

        app(CollectionStatusAggregator::class)->aggregateResource($resource->fresh());
        app(CollectionStatusAggregator::class)->aggregateCollection($run->fresh());

        $this->assertSame(CollectionRunStatus::Partial, $resource->fresh()->status);
        $this->assertSame(CollectionRunStatus::Partial, $run->fresh()->status);
    }

    #[Test]
    public function optional_failure_does_not_fail_parent(): void
    {
        $run = CollectionRun::factory()->create(['status' => CollectionRunStatus::Running, 'datasets_total' => 2]);
        $resource = CollectionResourceRun::factory()->create([
            'collection_run_id' => $run->id,
            'status' => CollectionRunStatus::Running,
            'datasets_total' => 2,
        ]);

        CollectionDatasetRun::factory()->create([
            'collection_run_id' => $run->id,
            'collection_resource_run_id' => $resource->id,
            'status' => CollectionRunStatus::Completed,
            'requirement_level' => RequirementLevel::Required,
            'request_family_id' => 'A',
            'dataset_contract_id' => 'a',
        ]);
        CollectionDatasetRun::factory()->create([
            'collection_run_id' => $run->id,
            'collection_resource_run_id' => $resource->id,
            'status' => CollectionRunStatus::Failed,
            'requirement_level' => RequirementLevel::Optional,
            'request_family_id' => 'B',
            'dataset_contract_id' => 'b',
        ]);

        app(CollectionStatusAggregator::class)->aggregateResource($resource->fresh());
        app(CollectionStatusAggregator::class)->aggregateCollection($run->fresh());

        $this->assertSame(CollectionRunStatus::Completed, $resource->fresh()->status);
        $this->assertSame(CollectionRunStatus::Completed, $run->fresh()->status);
        $this->assertNull($run->fresh()->failure_summary);
    }

    #[Test]
    public function all_required_failures_store_failure_summary(): void
    {
        $run = CollectionRun::factory()->create(['status' => CollectionRunStatus::Running, 'datasets_total' => 1]);
        $resource = CollectionResourceRun::factory()->create([
            'collection_run_id' => $run->id,
            'status' => CollectionRunStatus::Running,
            'datasets_total' => 1,
        ]);
        CollectionDatasetRun::factory()->create([
            'collection_run_id' => $run->id,
            'collection_resource_run_id' => $resource->id,
            'status' => CollectionRunStatus::Failed,
            'requirement_level' => RequirementLevel::Required,
            'request_family_id' => 'A',
            'dataset_contract_id' => 'a',
        ]);

        app(CollectionStatusAggregator::class)->aggregateResource($resource->fresh());
        app(CollectionStatusAggregator::class)->aggregateCollection($run->fresh());

        $this->assertSame(CollectionRunStatus::Failed, $resource->fresh()->status);
        $this->assertSame(CollectionRunStatus::Failed, $run->fresh()->status);
        $this->assertSame('All required datasets failed', $run->fresh()->failure_summary);
    }

    #[Test]
    public function not_eligible_is_not_failure(): void
    {
        $run = CollectionRun::factory()->create(['status' => CollectionRunStatus::Running, 'datasets_total' => 1]);
        $resource = CollectionResourceRun::factory()->create([
            'collection_run_id' => $run->id,
            'status' => CollectionRunStatus::Running,
            'datasets_total' => 1,
        ]);
        CollectionDatasetRun::factory()->create([
            'collection_run_id' => $run->id,
            'collection_resource_run_id' => $resource->id,
            'status' => CollectionRunStatus::NotEligible,
            'requirement_level' => RequirementLevel::Conditional,
            'request_family_id' => 'C',
            'dataset_contract_id' => 'c',
        ]);

        app(CollectionStatusAggregator::class)->aggregateResource($resource->fresh());
        app(CollectionStatusAggregator::class)->aggregateCollection($run->fresh());

        $this->assertSame(CollectionRunStatus::Completed, $resource->fresh()->status);
        $this->assertSame(CollectionRunStatus::Completed, $run->fresh()->status);
    }

    #[Test]
    public function retry_policy_and_error_sanitization(): void
    {
        $policy = app(DefaultRetryPolicy::class);
        $dataset = CollectionDatasetRun::factory()->create(['max_attempts' => 3, 'attempt_count' => 1]);

        $this->assertTrue($policy->shouldRetry($dataset, CollectionErrorCategory::Timeout, 1));
        $this->assertFalse($policy->shouldRetry($dataset, CollectionErrorCategory::Authentication, 1));
        $this->assertFalse($policy->shouldRetry($dataset, CollectionErrorCategory::Timeout, 3));
        $this->assertSame(30, $policy->backoffSeconds($dataset, 1));

        $recorder = app(CollectionErrorRecorder::class);
        $this->assertSame('Collection error', $recorder->sanitizeMessage('Bearer access_token=secret'));
        $this->assertSame('rate limited', $recorder->sanitizeMessage('rate limited'));
    }

    #[Test]
    public function checkpoint_rejects_secrets_and_progress_is_honest(): void
    {
        $checkpoints = app(CheckpointManager::class);
        $dataset = CollectionDatasetRun::factory()->create();

        $checkpoints->advance($dataset, ['page' => 2, 'start_row' => 1000]);
        $this->assertSame(2, $dataset->fresh()->checkpoint['page']);

        $this->expectException(InvalidArgumentException::class);
        $checkpoints->advance($dataset, ['access_token' => 'nope']);
    }

    #[Test]
    public function counted_progress_percentage_and_indeterminate_has_none(): void
    {
        $reporter = app(ProgressReporter::class);
        $dataset = CollectionDatasetRun::factory()->create([
            'progress_mode' => ProgressMode::Indeterminate,
        ]);

        $reporter->report($dataset, ProgressMode::Counted, 5, 10, 'page');
        $fresh = $dataset->fresh();
        $this->assertSame(50.0, $fresh->percentage());

        $reporter->report($fresh, ProgressMode::Indeterminate, 99, null, 'streaming', 10);
        $indeterminate = $fresh->fresh();
        $this->assertNull($indeterminate->percentage());
        $this->assertNull($indeterminate->progress_total);
        $this->assertSame(10, $indeterminate->rows_received);
    }
}
