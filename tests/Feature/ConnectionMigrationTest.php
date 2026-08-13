<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RollsBackMigrationsUntil;
use Tests\TestCase;

class ConnectionMigrationTest extends TestCase
{
    use RefreshDatabase;
    use RollsBackMigrationsUntil;

    public function test_core_connections_table_has_expected_columns_and_foreign_key(): void
    {
        $this->assertTrue(Schema::hasTable('core_connections'));

        $this->assertTrue(Schema::hasColumns('core_connections', [
            'id',
            'digital_asset_id',
            'type',
            'name',
            'config',
            'enabled',
            'last_success_at',
            'last_error',
            'created_at',
            'updated_at',
        ]));

        $foreignKeys = Schema::getForeignKeys('core_connections');

        $digitalAssetForeignKey = collect($foreignKeys)->first(
            fn (array $foreignKey): bool => $foreignKey['columns'] === ['digital_asset_id']
                && $foreignKey['foreign_table'] === 'digital_assets'
                && $foreignKey['foreign_columns'] === ['id']
        );

        $this->assertNotNull($digitalAssetForeignKey);
        $this->assertSame('cascade', $digitalAssetForeignKey['on_delete']);
    }

    public function test_core_connection_credentials_table_has_expected_columns_and_foreign_key(): void
    {
        $this->assertTrue(Schema::hasTable('core_connection_credentials'));

        $this->assertTrue(Schema::hasColumns('core_connection_credentials', [
            'id',
            'connection_id',
            'encrypted_payload',
            'created_at',
            'updated_at',
        ]));

        $foreignKeys = Schema::getForeignKeys('core_connection_credentials');

        $connectionForeignKey = collect($foreignKeys)->first(
            fn (array $foreignKey): bool => $foreignKey['columns'] === ['connection_id']
                && $foreignKey['foreign_table'] === 'core_connections'
                && $foreignKey['foreign_columns'] === ['id']
        );

        $this->assertNotNull($connectionForeignKey);
        $this->assertSame('cascade', $connectionForeignKey['on_delete']);
    }

    public function test_connection_migrations_rollback_cleanly(): void
    {
        $this->assertTrue(Schema::hasTable('core_connections'));
        $this->assertTrue(Schema::hasTable('core_connection_credentials'));

        $this->rollbackUntilTablesMissing('core_connections', 'core_connection_credentials');

        $this->assertFalse(Schema::hasTable('core_connection_credentials'));
        $this->assertFalse(Schema::hasTable('core_connections'));

        $this->assertSame(0, Artisan::call('migrate'));

        $this->assertTrue(Schema::hasTable('core_connections'));
        $this->assertTrue(Schema::hasTable('core_connection_credentials'));
    }
}
