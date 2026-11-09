<?php

namespace Tests\Feature\Operations;

use App\Services\Operations\UsageLedger;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Kullanım defteri (kök neden planı madde 5, yakup 2026-11-09): pruning is decided from measured use.
 */
final class UsageLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_runs_are_counted_per_day_with_time_and_failures(): void
    {
        $ledger = app(UsageLedger::class);
        $ledger->record(UsageLedger::JOB, 'moxdop:links:daily', 1200);
        $ledger->record(UsageLedger::JOB, 'moxdop:links:daily', 800, true);
        $ledger->record(UsageLedger::SCREEN, 'operator.sales');

        $row = DB::table('usage_daily')->where('key', 'moxdop:links:daily')->first();
        $this->assertSame([2, 1, 2000], [(int) $row->count, (int) $row->failures, (int) $row->total_ms]);

        $summary = $ledger->summary();
        $this->assertSame('moxdop:links:daily', $summary['jobs'][0]['key']);
        $this->assertSame(2, $summary['jobs'][0]['runs']);
        $this->assertSame('operator.sales', $summary['screens'][0]['key']);
        $this->assertNotContains('operator.sales', $summary['unused_screens']);
    }

    public function test_old_days_are_pruned(): void
    {
        DB::table('usage_daily')->insert(['kind' => 'job', 'key' => 'x', 'day' => now()->subDays(UsageLedger::KEEP_DAYS + 1)->toDateString(), 'count' => 1]);
        app(UsageLedger::class)->record(UsageLedger::JOB, 'y');

        $this->assertSame(1, app(UsageLedger::class)->prune());
        $this->assertSame(['y'], DB::table('usage_daily')->pluck('key')->all());
    }

    public function test_the_artisan_name_of_a_scheduled_command_is_the_key(): void
    {
        $event = new Event(app(CacheEventMutex::class), "'/usr/bin/php8.5' 'artisan' moxdop:repair:verify --batch");

        $this->assertSame('moxdop:repair:verify --batch', UsageLedger::jobKey($event));
    }
}
