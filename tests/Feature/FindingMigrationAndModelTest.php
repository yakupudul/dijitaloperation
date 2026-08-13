<?php

namespace Tests\Feature;

use App\Models\DigitalAsset;
use App\Models\Finding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RollsBackMigrationsUntil;
use Tests\TestCase;

class FindingMigrationAndModelTest extends TestCase
{
    use RefreshDatabase;
    use RollsBackMigrationsUntil;

    public function test_findings_table_has_expected_columns_and_foreign_key(): void
    {
        $this->assertTrue(Schema::hasTable('findings'));

        $this->assertTrue(Schema::hasColumns('findings', [
            'id',
            'digital_asset_id',
            'source_module',
            'fingerprint',
            'category',
            'severity',
            'title',
            'summary',
            'confidence',
            'status',
            'first_seen_at',
            'last_seen_at',
            'last_run_id',
            'resolved_at',
            'created_at',
            'updated_at',
        ]));

        $foreignKeys = Schema::getForeignKeys('findings');

        $digitalAssetForeignKey = collect($foreignKeys)->first(
            fn (array $foreignKey): bool => $foreignKey['columns'] === ['digital_asset_id']
                && $foreignKey['foreign_table'] === 'digital_assets'
                && $foreignKey['foreign_columns'] === ['id']
        );

        $this->assertNotNull($digitalAssetForeignKey);
        $this->assertSame('cascade', $digitalAssetForeignKey['on_delete']);

        $indexes = Schema::getIndexes('findings');

        $fingerprintUnique = collect($indexes)->first(
            fn (array $index): bool => ($index['unique'] ?? false) === true
                && $index['columns'] === ['digital_asset_id', 'fingerprint']
        );

        $this->assertNotNull($fingerprintUnique);
    }

    public function test_finding_can_be_created_via_factory_and_belongs_to_digital_asset(): void
    {
        $asset = DigitalAsset::factory()->create([
            'name' => 'Acme Corporate Website',
            'type' => 'website',
        ]);

        $firstSeenAt = now()->subDays(3)->startOfSecond();
        $lastSeenAt = now()->subHour()->startOfSecond();

        $finding = Finding::factory()->create([
            'digital_asset_id' => $asset->id,
            'source_module' => 'website',
            'fingerprint' => 'website:lighthouse:lcp-poor',
            'category' => 'performance',
            'severity' => 'high',
            'title' => 'Largest Contentful Paint is poor',
            'summary' => 'LCP exceeds the recommended threshold.',
            'confidence' => 0.8750,
            'status' => 'open',
            'first_seen_at' => $firstSeenAt,
            'last_seen_at' => $lastSeenAt,
            'last_run_id' => null,
        ]);

        $this->assertDatabaseHas('findings', [
            'id' => $finding->id,
            'digital_asset_id' => $asset->id,
            'source_module' => 'website',
            'fingerprint' => 'website:lighthouse:lcp-poor',
            'category' => 'performance',
            'severity' => 'high',
            'title' => 'Largest Contentful Paint is poor',
            'summary' => 'LCP exceeds the recommended threshold.',
            'status' => 'open',
            'last_run_id' => null,
        ]);

        $finding = $finding->fresh();

        $this->assertSame('0.8750', $finding->confidence);
        $this->assertTrue($finding->first_seen_at->equalTo($firstSeenAt));
        $this->assertTrue($finding->last_seen_at->equalTo($lastSeenAt));
        $this->assertTrue($finding->digitalAsset->is($asset));
        $this->assertSame('Acme Corporate Website', $finding->digitalAsset->name);
    }

    public function test_findings_migration_rollback_cleanly(): void
    {
        $this->assertTrue(Schema::hasTable('findings'));

        $this->rollbackUntilTablesMissing('findings');

        $this->assertFalse(Schema::hasTable('findings'));

        $this->assertSame(0, Artisan::call('migrate'));

        $this->assertTrue(Schema::hasTable('findings'));
    }
}
