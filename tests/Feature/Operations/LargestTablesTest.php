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
}
