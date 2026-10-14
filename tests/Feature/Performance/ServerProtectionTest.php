<?php

namespace Tests\Feature\Performance;

use App\Support\Database\WebRequestDatabaseLimits;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

/** Guards that keep one busy part of the system from taking the server down for everything else. */
final class ServerProtectionTest extends TestCase
{
    public function test_a_web_request_on_postgres_gets_a_statement_time_limit(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn('pgsql');
        $connection->shouldReceive('statement')->once()->with("set statement_timeout = '55s'")->andReturnTrue();
        $connection->shouldReceive('statement')->once()->with("set idle_in_transaction_session_timeout = '60s'")->andReturnTrue();

        (new WebRequestDatabaseLimits(web: true))->apply($connection);
    }

    public function test_queue_workers_artisan_other_drivers_and_a_zero_setting_keep_no_limit(): void
    {
        $pg = Mockery::mock(Connection::class);
        $pg->shouldReceive('getDriverName')->andReturn('pgsql');
        $pg->shouldNotReceive('statement');
        (new WebRequestDatabaseLimits(web: false))->apply($pg);

        $sqlite = Mockery::mock(Connection::class);
        $sqlite->shouldReceive('getDriverName')->andReturn('sqlite');
        $sqlite->shouldNotReceive('statement');
        (new WebRequestDatabaseLimits(web: true))->apply($sqlite);

        config(['database.web_statement_timeout' => 0]);
        (new WebRequestDatabaseLimits(web: true))->apply($pg);
    }

    public function test_it_listens_for_new_database_connections(): void
    {
        $this->assertTrue(Event::hasListeners(ConnectionEstablished::class));
        $this->assertContains(WebRequestDatabaseLimits::class, array_map(
            fn ($listener): string => is_string($listener) ? $listener : '',
            (array) (app('events')->getRawListeners()[ConnectionEstablished::class] ?? []),
        ));
    }

    public function test_failed_jobs_older_than_thirty_days_are_pruned_daily(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => $event->description === 'failed-jobs-prune');

        $this->assertNotNull($event);
        $this->assertStringContainsString('queue:prune-failed --hours=720', (string) $event->command);
    }
}
