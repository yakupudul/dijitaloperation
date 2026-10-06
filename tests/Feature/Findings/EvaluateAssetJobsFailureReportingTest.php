<?php

namespace Tests\Feature\Findings;

use App\Jobs\Async\EvaluateFindingsForAssetJob;
use App\Jobs\Async\EvaluateOpportunitiesForAssetJob;
use App\Models\Run;
use App\Services\Findings\FindingEvaluationService;
use App\Services\Opportunities\OpportunityEvaluationService;
use App\Support\Async\AsyncOperationTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Finding / Opportunity evaluation jobs: the queue worker reports every failed attempt (Worker::runJob), so the final
 * failure is one error occurrence, not two; only a timeout (the worker kills itself without a report) is reported by
 * failed(). An operator's Finding evaluation run that a timeout or MaxAttemptsExceeded ends is closed as failed.
 */
final class EvaluateAssetJobsFailureReportingTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{class-string, class-string}> */
    public static function jobs(): array
    {
        return [
            'findings' => [EvaluateFindingsForAssetJob::class, FindingEvaluationService::class],
            'opportunities' => [EvaluateOpportunitiesForAssetJob::class, OpportunityEvaluationService::class],
        ];
    }

    /**
     * @param  class-string  $job
     * @param  class-string  $service
     */
    #[DataProvider('jobs')]
    public function test_an_error_on_the_last_try_is_reported_once(string $job, string $service): void
    {
        $this->fakeReportedExceptions();
        // The service is resolved before handle() runs, so the attempt fails whatever the asset is.
        $this->app->bind($service, fn () => throw new RuntimeException('evaluation failed'));
        Queue::connection('database')->push(new $job(1));
        DB::table('jobs')->update(['attempts' => 2]);

        $this->work();

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'evaluation failed');
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('failed_jobs')->count());
    }

    /**
     * @param  class-string  $job
     * @param  class-string  $service
     */
    #[DataProvider('jobs')]
    public function test_a_job_past_its_tries_is_reported_once_by_the_worker_and_not_again_by_the_job(string $job, string $service): void
    {
        $this->fakeReportedExceptions();
        Queue::connection('database')->push(new $job(1));
        // Three attempts were killed from outside; the fourth reservation fails the job.
        DB::table('jobs')->update(['attempts' => 3]);

        $this->work();

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(fn (MaxAttemptsExceededException $exception): bool => str_contains($exception->getMessage(), $job));
        $this->assertSame(1, DB::table('failed_jobs')->count());
    }

    /**
     * @param  class-string  $job
     * @param  class-string  $service
     */
    #[DataProvider('jobs')]
    public function test_an_earlier_failed_try_is_still_reported_and_released_for_a_retry(string $job, string $service): void
    {
        $this->fakeReportedExceptions();
        $this->app->bind($service, fn () => throw new RuntimeException('evaluation failed'));
        Queue::connection('database')->push(new $job(1));

        $this->work();

        Exceptions::assertReportedCount(1);
        $this->assertSame(1, (int) DB::table('jobs')->value('attempts'), 'released with backoff for the next try');
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    /**
     * @param  class-string  $job
     * @param  class-string  $service
     */
    #[DataProvider('jobs')]
    public function test_only_a_timeout_is_reported_by_failed(string $job, string $service): void
    {
        Exceptions::fake();

        (new $job(1))->failed(new RuntimeException('already reported by the worker'));
        (new $job(1))->failed(new MaxAttemptsExceededException($job.' has been attempted too many times.'));
        Exceptions::assertNothingReported();

        (new $job(1))->failed(new TimeoutExceededException($job.' has timed out.'));
        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(TimeoutExceededException::class);
    }

    public function test_a_timeout_closes_the_operators_open_finding_evaluation_run_as_failed(): void
    {
        Exceptions::fake();
        $run = $this->evaluationRun('running');

        (new EvaluateFindingsForAssetJob((int) $run->digital_asset_id, runId: $run->id))
            ->failed(new TimeoutExceededException(EvaluateFindingsForAssetJob::class.' has timed out.'));

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(EvaluateFindingsForAssetJob::class.' has timed out.', data_get($run->metadata, 'failure_summary'));
        Exceptions::assertReportedCount(1);
    }

    public function test_a_job_past_its_tries_closes_the_run_the_killed_attempt_left_running(): void
    {
        $this->fakeReportedExceptions();
        $run = $this->evaluationRun('running');
        Queue::connection('database')->push(new EvaluateFindingsForAssetJob((int) $run->digital_asset_id, runId: $run->id));
        DB::table('jobs')->update(['attempts' => 3]);

        $this->work();

        $this->assertSame('failed', $run->fresh()->status);
        Exceptions::assertReportedCount(1);
    }

    public function test_failed_leaves_a_finished_run_as_it_is(): void
    {
        $completed = $this->evaluationRun('completed');
        $failed = $this->evaluationRun('failed');
        $failedMeta = $failed->metadata;

        (new EvaluateFindingsForAssetJob((int) $completed->digital_asset_id, runId: $completed->id))->failed(new RuntimeException('late'));
        (new EvaluateFindingsForAssetJob((int) $failed->digital_asset_id, runId: $failed->id))->failed(new RuntimeException('late'));

        $this->assertSame('completed', $completed->fresh()->status);
        $this->assertSame($failedMeta, $failed->fresh()->metadata, 'the catch in handle() already closed it');
    }

    private function evaluationRun(string $status): Run
    {
        return Run::factory()->create([
            'module_id' => 'finding-evaluation',
            'status' => $status,
            'started_at' => now()->subMinutes(5),
            'finished_at' => in_array($status, ['completed', 'failed'], true) ? now()->subMinute() : null,
            'metadata' => ['async' => true, 'operation_type' => AsyncOperationTypes::FINDING_EVALUATION, 'phase' => 'evaluating'],
        ]);
    }

    private function work(): void
    {
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'default'])->assertSuccessful();
    }

    /** The queue worker is built while the app boots; rebuild it so its reports reach the fake too. */
    private function fakeReportedExceptions(): void
    {
        Exceptions::fake();
        $this->app->forgetInstance('queue.worker');
    }
}
