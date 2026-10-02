<?php

namespace Tests\Feature\Collection;

use App\Enums\Collection\CollectionErrorCategory;
use App\Enums\Collection\CollectionRunStatus;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\Run;
use App\Services\Collection\CollectionStatusAggregator;
use App\Support\Async\AsyncFailureClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The linked operator Run mirrors the terminal CollectionRun: failures carry a category,
 * successful/partial outcomes clear any stale one.
 */
class CollectionOperatorRunFailureCategoryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function failed_collection_sets_a_category_from_the_failed_dataset(): void
    {
        $operatorRun = $this->operatorRun(['failure_category' => null]);
        $run = $this->terminalCollection($operatorRun, CollectionRunStatus::Failed, CollectionErrorCategory::RateLimit);

        app(CollectionStatusAggregator::class)->aggregateCollection($run);

        $operatorRun->refresh();
        $this->assertSame('failed', $operatorRun->status);
        $this->assertSame(AsyncFailureClassifier::TRANSIENT, data_get($operatorRun->metadata, 'failure_category'));
        $this->assertSame('rate_limit', data_get($operatorRun->metadata, 'collection_error_category'));
    }

    #[Test]
    public function failed_collection_with_a_non_transient_error_is_a_validation_failure(): void
    {
        $operatorRun = $this->operatorRun([]);
        $run = $this->terminalCollection($operatorRun, CollectionRunStatus::Failed, CollectionErrorCategory::Authorization);

        app(CollectionStatusAggregator::class)->aggregateCollection($run);

        $this->assertSame(AsyncFailureClassifier::VALIDATION, data_get($operatorRun->fresh()->metadata, 'failure_category'));
    }

    #[Test]
    public function cancelled_collection_sets_a_cancelled_category(): void
    {
        $operatorRun = $this->operatorRun([]);
        $run = $this->terminalCollection($operatorRun, CollectionRunStatus::Cancelled, null);

        app(CollectionStatusAggregator::class)->aggregateCollection($run);

        $operatorRun->refresh();
        $this->assertSame('failed', $operatorRun->status);
        $this->assertNotNull(data_get($operatorRun->metadata, 'failure_category'));
        $this->assertSame('cancelled', data_get($operatorRun->metadata, 'collection_error_category'));
    }

    #[Test]
    public function completed_and_partial_collections_clear_a_stale_category(): void
    {
        foreach ([CollectionRunStatus::Completed, CollectionRunStatus::Partial] as $status) {
            $operatorRun = $this->operatorRun([
                'failure_category' => AsyncFailureClassifier::TRANSIENT,
                'collection_error_category' => 'rate_limit',
            ]);
            $run = $this->terminalCollection($operatorRun, $status, null);

            app(CollectionStatusAggregator::class)->aggregateCollection($run);

            $operatorRun->refresh();
            $this->assertNotSame('failed', $operatorRun->status);
            $this->assertNull(data_get($operatorRun->metadata, 'failure_category'), $status->value);
            $this->assertNull(data_get($operatorRun->metadata, 'collection_error_category'), $status->value);
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function operatorRun(array $metadata): Run
    {
        return Run::factory()->create([
            'status' => 'running',
            'finished_at' => null,
            'metadata' => array_merge(['async' => true, 'operation_type' => 'collect_live_bound_data'], $metadata),
        ]);
    }

    private function terminalCollection(Run $operatorRun, CollectionRunStatus $status, ?CollectionErrorCategory $datasetError): CollectionRun
    {
        $run = CollectionRun::factory()->create([
            'status' => $status,
            'metadata' => ['operator_run_id' => $operatorRun->id],
        ]);
        $resourceRun = CollectionResourceRun::factory()->create([
            'collection_run_id' => $run->id,
            'status' => $status,
        ]);
        CollectionDatasetRun::factory()->create([
            'collection_run_id' => $run->id,
            'collection_resource_run_id' => $resourceRun->id,
            'status' => $datasetError !== null ? CollectionRunStatus::Failed : $status,
            'error_category' => $datasetError,
        ]);

        return $run;
    }
}
