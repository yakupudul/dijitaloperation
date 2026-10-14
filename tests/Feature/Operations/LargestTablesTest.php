<?php

namespace Tests\Feature\Operations;

use App\Services\Operations\ServerStatusReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class LargestTablesTest extends TestCase
{
    use RefreshDatabase;

    public function test_partitions_are_counted_under_their_parent_table(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->assertSame([], app(ServerStatusReader::class)->largestTables(), 'PostgreSQL only');

            return;
        }
        $tables = app(ServerStatusReader::class)->largestTables(500);
        $names = array_column($tables, 'table');

        $this->assertSame(count($names), count(array_unique($names)));
        $this->assertSame([], preg_grep('/^gsc_f_query_page_\d{4}/', $names), 'monthly partitions roll up into gsc_f_query_page');
        $this->assertContains('users', $names);
        $this->assertContains('gsc_f_query_page', $names);
        $this->assertSame(array_column($tables, 'bytes'), collect($tables)->sortByDesc('bytes')->pluck('bytes')->all());
    }

    public function test_disk_breakdown_lists_folder_sizes_largest_first(): void
    {
        $this->assertSame([], app(ServerStatusReader::class)->diskBreakdown(), 'a web read never runs du itself');
        app(ServerStatusReader::class)->measureDisk();
        $breakdown = app(ServerStatusReader::class)->diskBreakdown();

        $this->assertContains(storage_path(), array_column($breakdown, 'path'));
        $this->assertSame(array_column($breakdown, 'bytes'), collect($breakdown)->sortByDesc('bytes')->pluck('bytes')->all());
    }

    public function test_tuning_facts_name_the_drivers_and_php_limits(): void
    {
        $facts = app(ServerStatusReader::class)->tuningFacts();

        $this->assertSame(config('cache.default'), $facts['drivers']['cache']);
        $this->assertArrayHasKey('memory_limit', $facts['php']);
        $this->assertArrayHasKey('opcache', $facts);
    }
}
