<?php

namespace Tests\Feature\Operations;

use App\Models\ClaudeNote;
use App\Models\Observability\WorkerHeartbeat;
use App\Services\Operations\QueueDoctor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * moxdop:ops:watchdog hands a silent redis queue to QueueDoctor ("default" silent for hours on 2026-10-13, deploys did
 * not bring it back): a hung worker is killed, a queue nobody takes gets a Horizon restart, a backlog is only noted.
 */
final class QueueDoctorTest extends TestCase
{
    use RefreshDatabase;

    private FakeQueueDoctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'redis', 'queue.connections.redis.driver' => 'redis']);
        $this->doctor = new FakeQueueDoctor;
        $this->app->instance(QueueDoctor::class, $this->doctor);
        WorkerHeartbeat::query()->create(['worker_id' => 'queue:default', 'last_seen_at' => now()->subHours(7)]);
        WorkerHeartbeat::query()->create(['worker_id' => 'queue:collection', 'last_seen_at' => now()]);
    }

    public function test_a_worker_holding_a_job_far_past_its_timeout_is_killed_once_and_noted(): void
    {
        $this->doctor->snapshot = ['pending' => 140, 'oldest_minutes' => 420, 'pending_by_job' => ['App\\Jobs\\ExecuteExternalWriteJob' => 60, 'Illuminate\\Foundation\\Console\\QueuedCommand' => 19],
            'reserved' => [['name' => 'App\\Jobs\\SomeJob', 'seconds' => 7 * 3600]]];

        $this->artisan('moxdop:ops:watchdog')->assertSuccessful();
        $this->artisan('moxdop:ops:watchdog')->assertSuccessful();

        $this->assertSame(['default'], $this->doctor->killed, 'only the silent queue, once per half hour');
        $this->assertSame(0, $this->doctor->restarts);
        $note = ClaudeNote::query()->sole();
        $this->assertNull($note->brand_id);
        $this->assertStringContainsString('Kuyruk "default" sessiz', $note->text);
        $this->assertStringContainsString('140 iş bekliyor (en eskisi 420 dk) · bekleyenler: ExecuteExternalWriteJob ×60, QueuedCommand ×19', $note->text);
        $this->assertStringContainsString('çalışan: App\\Jobs\\SomeJob 420 dk · 1 takılı işçi durduruldu', $note->text);
    }

    public function test_jobs_waiting_with_no_worker_restart_horizon_at_most_once_an_hour(): void
    {
        $this->doctor->snapshot = ['pending' => 12, 'oldest_minutes' => 30, 'pending_by_job' => [], 'reserved' => []];

        $this->artisan('moxdop:ops:watchdog')->assertSuccessful();
        $this->artisan('moxdop:ops:watchdog')->assertSuccessful();

        $this->assertSame(1, $this->doctor->restarts);
        $this->assertSame([], $this->doctor->killed);
    }

    public function test_a_backlog_that_is_moving_is_only_noted(): void
    {
        $this->doctor->snapshot = ['pending' => 500, 'oldest_minutes' => 90, 'pending_by_job' => [], 'reserved' => [['name' => 'A', 'seconds' => 120]]];

        $this->artisan('moxdop:ops:watchdog')->assertSuccessful();

        $this->assertSame([], $this->doctor->killed);
        $this->assertSame(0, $this->doctor->restarts);
        $this->assertStringContainsString('dokunulmadı', ClaudeNote::query()->sole()->text);
    }

    public function test_nothing_happens_without_redis(): void
    {
        config(['queue.default' => 'sync']);

        $this->artisan('moxdop:ops:watchdog')->assertSuccessful();

        $this->assertSame(0, ClaudeNote::query()->count());
    }
}

final class FakeQueueDoctor extends QueueDoctor
{
    /** @var array<string, mixed> */
    public array $snapshot = [];

    /** @var list<string> */
    public array $killed = [];

    public int $restarts = 0;

    protected function snapshot(string $queue): array
    {
        return $this->snapshot;
    }

    protected function killWorkers(string $queue): int
    {
        $this->killed[] = $queue;

        return 1;
    }

    protected function restartHorizon(): void
    {
        $this->restarts++;
    }
}
