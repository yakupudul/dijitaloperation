<?php

namespace Tests\Feature;

use App\Models\CoreConnection;
use App\Models\DigitalAsset;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RunMigrationAndModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_runs_table_has_expected_columns_indexes_and_foreign_keys(): void
    {
        $this->assertTrue(Schema::hasTable('runs'));

        $this->assertTrue(Schema::hasColumns('runs', [
            'id',
            'digital_asset_id',
            'core_connection_id',
            'module_id',
            'status',
            'started_at',
            'finished_at',
            'metadata',
            'created_at',
            'updated_at',
        ]));

        $foreignKeys = Schema::getForeignKeys('runs');

        $digitalAssetForeignKey = collect($foreignKeys)->first(
            fn (array $foreignKey): bool => $foreignKey['columns'] === ['digital_asset_id']
                && $foreignKey['foreign_table'] === 'digital_assets'
                && $foreignKey['foreign_columns'] === ['id']
        );

        $this->assertNotNull($digitalAssetForeignKey);
        $this->assertSame('cascade', $digitalAssetForeignKey['on_delete']);

        $coreConnectionForeignKey = collect($foreignKeys)->first(
            fn (array $foreignKey): bool => $foreignKey['columns'] === ['core_connection_id']
                && $foreignKey['foreign_table'] === 'core_connections'
                && $foreignKey['foreign_columns'] === ['id']
        );

        $this->assertNotNull($coreConnectionForeignKey);
        $this->assertSame('set null', $coreConnectionForeignKey['on_delete']);

        $indexes = Schema::getIndexes('runs');

        $statusIndex = collect($indexes)->first(
            fn (array $index): bool => $index['columns'] === ['status']
        );

        $this->assertNotNull($statusIndex);

        $assetStartedIndex = collect($indexes)->first(
            fn (array $index): bool => $index['columns'] === ['digital_asset_id', 'started_at']
        );

        $this->assertNotNull($assetStartedIndex);
    }

    public function test_run_can_be_created_via_factory_with_relations_and_metadata_cast(): void
    {
        $asset = DigitalAsset::factory()->create([
            'name' => 'Acme Corporate Website',
            'type' => 'website',
        ]);

        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'wordpress',
            'name' => 'Primary WordPress',
        ]);

        $startedAt = now()->subHour()->startOfSecond();
        $finishedAt = now()->subMinutes(10)->startOfSecond();
        $metadata = [
            'trigger' => 'manual',
            'attempt' => 1,
        ];

        $run = Run::factory()->create([
            'digital_asset_id' => $asset->id,
            'core_connection_id' => $connection->id,
            'module_id' => 'website',
            'status' => 'completed',
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'metadata' => $metadata,
        ]);

        $this->assertDatabaseHas('runs', [
            'id' => $run->id,
            'digital_asset_id' => $asset->id,
            'core_connection_id' => $connection->id,
            'module_id' => 'website',
            'status' => 'completed',
        ]);

        $run = $run->fresh();

        $this->assertTrue($run->digitalAsset->is($asset));
        $this->assertSame('Acme Corporate Website', $run->digitalAsset->name);
        $this->assertTrue($run->coreConnection->is($connection));
        $this->assertSame('Primary WordPress', $run->coreConnection->name);
        $this->assertSame($metadata, $run->metadata);
        $this->assertIsArray($run->metadata);
        $this->assertTrue($run->started_at->equalTo($startedAt));
        $this->assertTrue($run->finished_at->equalTo($finishedAt));
        $this->assertNotNull($run->created_at);
        $this->assertNotNull($run->updated_at);
        $this->assertTrue(method_exists($run, 'evidence'));
    }

    public function test_run_allows_nullable_core_connection_and_metadata(): void
    {
        $asset = DigitalAsset::factory()->create();

        $run = Run::factory()->create([
            'digital_asset_id' => $asset->id,
            'core_connection_id' => null,
            'metadata' => null,
            'status' => 'running',
            'finished_at' => null,
        ]);

        $run = $run->fresh();

        $this->assertNull($run->core_connection_id);
        $this->assertNull($run->coreConnection);
        $this->assertNull($run->metadata);
        $this->assertNull($run->finished_at);
        $this->assertTrue($run->digitalAsset->is($asset));
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->created_at);
        $this->assertNotNull($run->updated_at);
    }

    public function test_runs_migration_rollback_cleanly(): void
    {
        $this->assertTrue(Schema::hasTable('runs'));

        // Newest migrations may include credential_type/agent conversations/evidence/website fields/tasks/recommendations; roll back past runs.
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 19]));

        $this->assertFalse(Schema::hasTable('runs'));

        $this->assertSame(0, Artisan::call('migrate'));

        $this->assertTrue(Schema::hasTable('runs'));
    }
}
