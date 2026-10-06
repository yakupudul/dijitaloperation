<?php

namespace Tests\Feature\Queries;

use App\Jobs\Queries\ApplyQueryRulesJob;
use App\Jobs\Queries\ProcessQueriesJob;
use App\Services\Queries\QueryRuleEngine;
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
 * "Kuralları uygula" / review approved / pending imported while the shared Sorgular lock is busy (a full pass, a filter
 * scan): one rules run waits, further copies end, and the wait is bounded by time, not attempts, so the variant /
 * topic keys are recomputed even behind a half-hour pass.
 */
final class ApplyQueryRulesJobTest extends TestCase
{
    use RefreshDatabase;

    private int $runs = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'select') && str_contains($query->sql, '"variant_key"') && str_contains($query->sql, 'from "queries"')) {
                $this->runs++;
            }
        });
    }

    public function test_one_rules_run_waits_for_the_lock_and_further_copies_end(): void
    {
        $running = $this->busy();

        $this->handled(new ApplyQueryRulesJob, 'copy-1')->assertReleased(60);
        $this->assertSame('copy-1', Cache::get(ApplyQueryRulesJob::WAITING));
        $this->handled(new ApplyQueryRulesJob, 'copy-2')->assertNotReleased();
        $this->handled(new ApplyQueryRulesJob, 'copy-3')->assertNotReleased();
        $this->handled(new ApplyQueryRulesJob, 'copy-1')->assertReleased(60);
        $this->assertSame(0, $this->runs, 'no rules run while the lock is busy');

        $running->release();
        $this->handled(new ApplyQueryRulesJob, 'copy-1')->assertNotReleased();
        $this->assertGreaterThan(0, $this->runs, 'the waiting copy applied the rules');
        $this->assertNull(Cache::get(ApplyQueryRulesJob::WAITING), 'the run that took the lock clears the place');
        $this->assertTrue(Cache::lock(ProcessQueriesJob::LOCK, 1)->get(), 'the lock is free again');
        Cache::lock(ProcessQueriesJob::LOCK)->forceRelease();

        // A request made during the next run waits again.
        $this->busy();
        $this->handled(new ApplyQueryRulesJob, 'copy-4')->assertReleased(60);
        $this->assertSame('copy-4', Cache::get(ApplyQueryRulesJob::WAITING));
    }

    public function test_a_waiting_rules_run_that_gives_up_frees_its_place(): void
    {
        $this->busy();
        $this->handled(new ApplyQueryRulesJob, 'copy-1')->assertReleased(60);

        $this->queued(new ApplyQueryRulesJob, 'copy-2')->failed(new MaxAttemptsExceededException('attempted too many times'));
        $this->assertSame('copy-1', Cache::get(ApplyQueryRulesJob::WAITING), 'another copy failing leaves the waiting one');

        $this->queued(new ApplyQueryRulesJob, 'copy-1')->failed(new MaxAttemptsExceededException('attempted too many times'));
        $this->assertNull(Cache::get(ApplyQueryRulesJob::WAITING));
        $this->handled(new ApplyQueryRulesJob, 'copy-3')->assertReleased(60);
    }

    public function test_waits_are_bounded_by_time_and_errors_by_count(): void
    {
        $this->freezeTime();

        Queue::connection('database')->push(new ApplyQueryRulesJob);

        $payload = json_decode((string) DB::table('jobs')->value('payload'), true);
        $this->assertNull($payload['maxTries'], 'no attempt limit: a long wait behind the lock never ends in MaxAttemptsExceeded');
        $this->assertSame(now()->addHours(2)->getTimestamp(), $payload['retryUntil']);
        $this->assertSame(3, $payload['maxExceptions']);
    }

    public function test_the_worker_keeps_a_rules_run_waiting_past_ten_pickups_and_fails_it_only_after_two_hours(): void
    {
        $this->freezeTime();
        $this->busy(0);
        Queue::connection('database')->push(new ApplyQueryRulesJob);

        for ($pickup = 1; $pickup <= 12; $pickup++) {
            $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'default'])->assertSuccessful();
            $this->travel(61)->seconds();
        }

        $this->assertSame(12, (int) DB::table('jobs')->value('attempts'), 'still waiting: 10 tries no longer drop it after ~10 minutes');
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(0, $this->runs);

        $this->travel(2)->hours();
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'default'])->assertSuccessful();

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('failed_jobs')->count(), 'gives up after retryUntil');
    }

    private function busy(int $seconds = 60): Lock
    {
        $lock = Cache::lock(ProcessQueriesJob::LOCK, $seconds);
        $this->assertTrue($lock->get());

        return $lock;
    }

    private function handled(ApplyQueryRulesJob $job, string $uuid): ApplyQueryRulesJob
    {
        $this->queued($job, $uuid)->handle(app(QueryRuleEngine::class));

        return $job;
    }

    /** The job as a queue worker runs it: each attempt of the same queued job has the same uuid. */
    private function queued(ApplyQueryRulesJob $job, string $uuid): ApplyQueryRulesJob
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
