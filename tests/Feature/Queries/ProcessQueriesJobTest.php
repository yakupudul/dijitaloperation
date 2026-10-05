<?php

namespace Tests\Feature\Queries;

use App\Jobs\Queries\ProcessQueriesJob;
use App\Services\Queries\QueryNotifier;
use App\Services\Queries\QueryPipeline;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Sorgular pipeline job while the shared lock is busy (another pass, a filter rescan, rules): one full pass waits,
 * further copies end, the first import always waits, and the wait is bounded by time, not attempts.
 */
final class ProcessQueriesJobTest extends TestCase
{
    use RefreshDatabase;

    private int $passes = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'select') && str_contains($query->sql, 'from "query_sources"')) {
                $this->passes++;
            }
        });
    }

    public function test_one_full_pass_waits_for_the_lock_and_further_copies_end(): void
    {
        $running = $this->busy();

        $this->handled(new ProcessQueriesJob, 'copy-1')->assertReleased(120);
        $this->assertSame('copy-1', Cache::get(ProcessQueriesJob::WAITING));
        $this->handled(new ProcessQueriesJob, 'copy-2')->assertNotReleased();
        $this->handled(new ProcessQueriesJob, 'copy-3')->assertNotReleased();
        $this->handled(new ProcessQueriesJob, 'copy-1')->assertReleased(120);
        $this->handled(new ProcessQueriesJob(import: true), 'import-1')->assertReleased(120);
        $this->assertSame('copy-1', Cache::get(ProcessQueriesJob::WAITING), 'the import never takes the waiting place');
        $this->assertSame(0, $this->passes, 'no pass while the lock is busy');

        $running->release();
        $this->handled(new ProcessQueriesJob, 'copy-1')->assertNotReleased();
        $this->assertGreaterThan(0, $this->passes, 'the waiting copy ran the pass');
        $this->assertNull(Cache::get(ProcessQueriesJob::WAITING), 'the pass that took the lock clears the place');
        $this->assertTrue(Cache::lock(ProcessQueriesJob::LOCK, 1)->get(), 'the lock is free again');
        Cache::lock(ProcessQueriesJob::LOCK)->forceRelease();

        // A request made during the next run waits again.
        $this->busy();
        $this->handled(new ProcessQueriesJob, 'copy-4')->assertReleased(120);
        $this->assertSame('copy-4', Cache::get(ProcessQueriesJob::WAITING));
    }

    public function test_a_waiting_pass_that_gives_up_frees_its_place(): void
    {
        $this->busy();
        $this->handled(new ProcessQueriesJob, 'copy-1')->assertReleased(120);

        $this->queued(new ProcessQueriesJob, 'copy-2')->failed(new MaxAttemptsExceededException('attempted too many times'));
        $this->assertSame('copy-1', Cache::get(ProcessQueriesJob::WAITING), 'another copy failing leaves the waiting one');

        $this->queued(new ProcessQueriesJob, 'copy-1')->failed(new MaxAttemptsExceededException('attempted too many times'));
        $this->assertNull(Cache::get(ProcessQueriesJob::WAITING));
        $this->handled(new ProcessQueriesJob, 'copy-3')->assertReleased(120);
    }

    public function test_waits_are_bounded_by_time_and_errors_by_count(): void
    {
        $this->freezeTime();

        Queue::connection('database')->push(new ProcessQueriesJob);

        $payload = json_decode((string) DB::table('jobs')->value('payload'), true);
        $this->assertNull($payload['maxTries'], 'no attempt limit: a long wait behind the lock never ends in MaxAttemptsExceeded');
        $this->assertSame(now()->addHours(2)->getTimestamp(), $payload['retryUntil']);
        $this->assertSame(3, $payload['maxExceptions']);
    }

    private function busy(): Lock
    {
        $lock = Cache::lock(ProcessQueriesJob::LOCK, 60);
        $this->assertTrue($lock->get());

        return $lock;
    }

    private function handled(ProcessQueriesJob $job, string $uuid): ProcessQueriesJob
    {
        $this->queued($job, $uuid)->handle(app(QueryPipeline::class), app(QueryNotifier::class));

        return $job;
    }

    /** The job as a queue worker runs it: each attempt of the same queued job has the same uuid. */
    private function queued(ProcessQueriesJob $job, string $uuid): ProcessQueriesJob
    {
        $job->setJob(new class($uuid) extends FakeJob
        {
            public function __construct(private readonly string $queuedUuid) {}

            public function uuid(): string
            {
                return $this->queuedUuid;
            }
        });

        return $job;
    }
}
