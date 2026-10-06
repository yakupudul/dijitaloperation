<?php

namespace Tests\Feature\Queries;

use App\Enums\NotificationKind;
use App\Jobs\Queries\ProcessQueriesJob;
use App\Jobs\Queries\RescanQueriesJob;
use App\Models\FilterTerm;
use App\Models\Query;
use App\Models\QueryReview;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Queries\QueryNormalizer;
use App\Services\Queries\QueryNotifier;
use App\Services\Queries\QueryRescanner;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Filter rescan while the shared Sorgular lock is busy (a full pass, another scan, rules): one scan waits, further
 * copies end, and the wait is bounded by time, not attempts, so a long pass never drops the operator's scan and its
 * "Filtre taraması hazır" notice.
 */
final class RescanQueriesJobTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->operator = User::factory()->create(['is_active' => true]);
        FilterTerm::query()->create(['sector_id' => null, 'term' => 'forum']);
        Query::query()->create(['text' => 'implant forum', 'text_hash' => QueryNormalizer::hash('implant forum')]);
    }

    public function test_one_scan_waits_for_the_lock_and_further_copies_end(): void
    {
        $running = $this->busy();

        $this->handled(new RescanQueriesJob($this->operator->id), 'copy-1')->assertReleased(120);
        $this->assertSame('copy-1', Cache::get(RescanQueriesJob::WAITING));
        $this->handled(new RescanQueriesJob($this->operator->id), 'copy-2')->assertNotReleased();
        $this->handled(new RescanQueriesJob($this->operator->id), 'copy-3')->assertNotReleased();
        $this->handled(new RescanQueriesJob($this->operator->id), 'copy-1')->assertReleased(120);
        $this->assertSame(0, QueryReview::query()->count(), 'no scan while the lock is busy');
        $this->assertSame([], $this->notices());

        $running->release();
        $this->handled(new RescanQueriesJob($this->operator->id), 'copy-1')->assertNotReleased();
        $this->assertSame(1, QueryReview::query()->count(), 'the waiting copy ran the scan');
        $this->assertSame(['Filtre taraması hazır: 1 silinecek, 0 hizmet değişikliği'], $this->notices());
        $this->assertNull(Cache::get(RescanQueriesJob::WAITING), 'the scan that took the lock clears the place');
        $this->assertTrue(Cache::lock(ProcessQueriesJob::LOCK, 1)->get(), 'the lock is free again');
        Cache::lock(ProcessQueriesJob::LOCK)->forceRelease();

        // A request made during the next run waits again.
        $this->busy();
        $this->handled(new RescanQueriesJob($this->operator->id), 'copy-4')->assertReleased(120);
        $this->assertSame('copy-4', Cache::get(RescanQueriesJob::WAITING));
    }

    public function test_a_waiting_scan_that_gives_up_frees_its_place(): void
    {
        $this->busy();
        $this->handled(new RescanQueriesJob($this->operator->id), 'copy-1')->assertReleased(120);

        $this->queued(new RescanQueriesJob($this->operator->id), 'copy-2')->failed(new MaxAttemptsExceededException('attempted too many times'));
        $this->assertSame('copy-1', Cache::get(RescanQueriesJob::WAITING), 'another copy failing leaves the waiting one');

        $this->queued(new RescanQueriesJob($this->operator->id), 'copy-1')->failed(new MaxAttemptsExceededException('attempted too many times'));
        $this->assertNull(Cache::get(RescanQueriesJob::WAITING));
        $this->handled(new RescanQueriesJob($this->operator->id), 'copy-3')->assertReleased(120);
    }

    public function test_waits_are_bounded_by_time_and_errors_by_count(): void
    {
        $this->freezeTime();

        Queue::connection('database')->push(new RescanQueriesJob($this->operator->id));

        $payload = json_decode((string) DB::table('jobs')->value('payload'), true);
        $this->assertNull($payload['maxTries'], 'no attempt limit: a long wait behind the lock never ends in MaxAttemptsExceeded');
        $this->assertSame(now()->addHours(2)->getTimestamp(), $payload['retryUntil']);
        $this->assertSame(3, $payload['maxExceptions']);
    }

    public function test_the_worker_keeps_a_scan_waiting_past_ten_pickups_and_fails_it_only_after_two_hours(): void
    {
        $this->freezeTime();
        $this->busy(0);
        Queue::connection('database')->push(new RescanQueriesJob($this->operator->id));

        for ($pickup = 1; $pickup <= 12; $pickup++) {
            $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'default'])->assertSuccessful();
            $this->travel(121)->seconds();
        }

        $this->assertSame(12, (int) DB::table('jobs')->value('attempts'), 'still waiting: 10 tries no longer drop it after ~20 minutes');
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(0, QueryReview::query()->count());

        $this->travel(2)->hours();
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'default'])->assertSuccessful();

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('failed_jobs')->count(), 'gives up after retryUntil');
    }

    /** @return list<string> titles of the operator's Sorgular notices */
    private function notices(): array
    {
        return UserNotification::query()->where('recipient_user_id', $this->operator->id)
            ->where('notification_kind', NotificationKind::QueriesNotice->value)->orderBy('id')->get()
            ->map(fn (UserNotification $notice): string => (string) $notice->presentation['title'])->all();
    }

    private function busy(int $seconds = 60): Lock
    {
        $lock = Cache::lock(ProcessQueriesJob::LOCK, $seconds);
        $this->assertTrue($lock->get());

        return $lock;
    }

    private function handled(RescanQueriesJob $job, string $uuid): RescanQueriesJob
    {
        $this->queued($job, $uuid)->handle(app(QueryRescanner::class), app(QueryNotifier::class));

        return $job;
    }

    /** The job as a queue worker runs it: each attempt of the same queued job has the same uuid. */
    private function queued(RescanQueriesJob $job, string $uuid): RescanQueriesJob
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
