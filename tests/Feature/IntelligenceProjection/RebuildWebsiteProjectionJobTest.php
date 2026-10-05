<?php

namespace Tests\Feature\IntelligenceProjection;

use App\Jobs\IntelligenceProjection\RebuildWebsiteProjectionJob;
use App\Services\IntelligenceProjection\Website\WebsiteProjectionRebuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * The queue worker reports every failed attempt (Worker::runJob). The job adds no second report when it finally
 * fails, so one MaxAttemptsExceededException is one error occurrence in the error groups, not two.
 */
final class RebuildWebsiteProjectionJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_rebuild_past_its_tries_is_reported_once_by_the_worker_and_not_again_by_the_job(): void
    {
        $this->fakeReportedExceptions();
        Queue::connection('database')->push(new RebuildWebsiteProjectionJob(1));
        // Three attempts were killed from outside; the fourth reservation fails the job.
        DB::table('jobs')->update(['attempts' => 3]);

        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'default'])->assertSuccessful();

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(fn (MaxAttemptsExceededException $exception): bool => str_contains($exception->getMessage(), RebuildWebsiteProjectionJob::class));
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('failed_jobs')->count());
    }

    public function test_a_rebuild_that_throws_on_its_last_try_is_reported_once(): void
    {
        $this->fakeReportedExceptions();
        // The rebuilder is resolved before handle() runs, so the attempt fails whatever the website is.
        $this->app->bind(WebsiteProjectionRebuilder::class, fn () => throw new RuntimeException('rebuild failed'));
        Queue::connection('database')->push(new RebuildWebsiteProjectionJob(1));
        DB::table('jobs')->update(['attempts' => 2]);

        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'default'])->assertSuccessful();

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'rebuild failed');
        $this->assertSame(1, DB::table('failed_jobs')->count());
    }

    public function test_an_earlier_failed_try_is_still_reported_and_released_for_a_retry(): void
    {
        $this->fakeReportedExceptions();
        $this->app->bind(WebsiteProjectionRebuilder::class, fn () => throw new RuntimeException('rebuild failed'));
        Queue::connection('database')->push(new RebuildWebsiteProjectionJob(1));

        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'default'])->assertSuccessful();

        Exceptions::assertReportedCount(1);
        $this->assertSame(1, (int) DB::table('jobs')->value('attempts'), 'released with backoff for the next try');
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    /** The queue worker is built while the app boots; rebuild it so its reports reach the fake too. */
    private function fakeReportedExceptions(): void
    {
        Exceptions::fake();
        $this->app->forgetInstance('queue.worker');
    }
}
