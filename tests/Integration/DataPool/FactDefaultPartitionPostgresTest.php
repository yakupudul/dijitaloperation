<?php

namespace Tests\Integration\DataPool;

use App\Services\DataPool\PartitionManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * PostgreSQL: a row written before its month partition existed lands in the DEFAULT partition; creating the month
 * afterwards moves it into the new partition. Skipped unless DB_CONNECTION=pgsql.
 */
#[Group('postgres')]
final class FactDefaultPartitionPostgresTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL integration tests require DB_CONNECTION=pgsql');
        }
    }

    public function test_default_partition_catches_rows_and_the_month_partition_takes_them_over(): void
    {
        PartitionManager::forgetCache();
        DB::statement('CREATE TABLE part_probe_f (resource_id integer NOT NULL, reporting_date date NOT NULL, clicks integer NOT NULL DEFAULT 0,
            PRIMARY KEY (resource_id, reporting_date)) PARTITION BY RANGE (reporting_date)');
        $partitions = app(PartitionManager::class);

        $this->assertTrue($partitions->isPartitioned('part_probe_f'));
        $this->assertTrue($partitions->ensureDefault('part_probe_f'));
        DB::table('part_probe_f')->insert(['resource_id' => 1, 'reporting_date' => '2031-01-15', 'clicks' => 4]);
        $this->assertSame(1, DB::table('part_probe_f_default')->count());

        $this->assertTrue($partitions->ensureForWrite('part_probe_f', false, '2031-01-15', '2031-02-02'));

        $this->assertSame(0, DB::table('part_probe_f_default')->count());
        $this->assertSame(1, DB::table('part_probe_f_2031_01')->count());
        $this->assertSame(4, (int) DB::table('part_probe_f')->where('reporting_date', '2031-01-15')->value('clicks'));
        $partitions->ensureMonth('part_probe_f', CarbonImmutable::parse('2031-01-01'));
        $this->assertSame(1, DB::table('part_probe_f')->count(), 'idempotent');
    }
}
