<?php

namespace Tests\Integration\Deployment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('postgres')]
class PostgresAppSchemaMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_tables_exist_after_pgsql_migrate(): void
    {
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Requires DB_CONNECTION=pgsql');
        }

        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('customers'));
        $this->assertTrue(Schema::hasTable('core_asset_bindings'));
        $this->assertTrue(Schema::hasTable('collection_schedules'));
        $this->assertTrue(Schema::hasTable('prospects'));
    }
}
