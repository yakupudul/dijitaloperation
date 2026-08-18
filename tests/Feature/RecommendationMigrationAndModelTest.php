<?php

namespace Tests\Feature;

use App\Enums\RecommendationSourceKind;
use App\Models\DigitalAsset;
use App\Models\Finding;
use App\Models\Opportunity;
use App\Models\Recommendation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RollsBackMigrationsUntil;
use Tests\TestCase;

class RecommendationMigrationAndModelTest extends TestCase
{
    use RefreshDatabase;
    use RollsBackMigrationsUntil;

    public function test_recommendations_table_has_expected_columns_and_indexes(): void
    {
        $this->assertTrue(Schema::hasTable('recommendations'));

        $this->assertTrue(Schema::hasColumns('recommendations', [
            'id',
            'source_kind',
            'finding_id',
            'opportunity_id',
            'digital_asset_id',
            'source_module',
            'origin',
            'idempotency_key',
            'title',
            'action',
            'rationale',
            'priority',
            'effort',
            'status',
            'created_at',
            'updated_at',
        ]));

        $foreignKeys = Schema::getForeignKeys('recommendations');

        $findingForeignKey = collect($foreignKeys)->first(
            fn (array $foreignKey): bool => $foreignKey['columns'] === ['finding_id']
                && $foreignKey['foreign_table'] === 'findings'
                && $foreignKey['foreign_columns'] === ['id']
        );

        $this->assertNotNull($findingForeignKey);
        $this->assertSame('restrict', $findingForeignKey['on_delete']);

        $opportunityForeignKey = collect($foreignKeys)->first(
            fn (array $foreignKey): bool => $foreignKey['columns'] === ['opportunity_id']
                && $foreignKey['foreign_table'] === 'opportunities'
                && $foreignKey['foreign_columns'] === ['id']
        );

        $this->assertNotNull($opportunityForeignKey);
        $this->assertSame('restrict', $opportunityForeignKey['on_delete']);

        $digitalAssetForeignKey = collect($foreignKeys)->first(
            fn (array $foreignKey): bool => $foreignKey['columns'] === ['digital_asset_id']
                && $foreignKey['foreign_table'] === 'digital_assets'
                && $foreignKey['foreign_columns'] === ['id']
        );

        $this->assertNotNull($digitalAssetForeignKey);
        $this->assertSame('set null', $digitalAssetForeignKey['on_delete']);

        $indexes = Schema::getIndexes('recommendations');

        $findingIndex = collect($indexes)->first(
            fn (array $index): bool => $index['columns'] === ['finding_id']
        );

        $digitalAssetIndex = collect($indexes)->first(
            fn (array $index): bool => $index['columns'] === ['digital_asset_id']
        );

        $this->assertNotNull($findingIndex);
        $this->assertNotNull($digitalAssetIndex);

        foreach ([
            ['source_kind'],
            ['opportunity_id'],
            ['source_kind', 'finding_id'],
            ['source_kind', 'opportunity_id'],
        ] as $columns) {
            $this->assertNotNull(
                collect($indexes)->first(fn (array $index): bool => $index['columns'] === $columns),
                'Missing index on '.implode(', ', $columns),
            );
        }

        $idempotencyIndex = collect($indexes)->first(
            fn (array $index): bool => $index['columns'] === ['idempotency_key']
        );

        $this->assertNotNull($idempotencyIndex);
        $this->assertTrue($idempotencyIndex['unique']);
    }

    public function test_finding_id_is_nullable_for_opportunity_sourced_recommendations(): void
    {
        $opportunity = Opportunity::factory()->create();

        $recommendation = Recommendation::factory()->forOpportunity($opportunity)->create([
            'title' => 'Act on: organic click recovery potential',
            'status' => Recommendation::STATUS_OPEN,
        ]);

        $this->assertDatabaseHas('recommendations', [
            'id' => $recommendation->id,
            'source_kind' => RecommendationSourceKind::Opportunity->value,
            'finding_id' => null,
            'opportunity_id' => $opportunity->id,
        ]);

        $this->assertTrue($recommendation->fresh()->opportunity->is($opportunity));
    }

    public function test_recommendation_can_be_created_via_factory_and_belongs_to_finding(): void
    {
        $asset = DigitalAsset::factory()->create([
            'name' => 'Acme Corporate Website',
            'type' => 'website',
        ]);

        $finding = Finding::factory()->create([
            'digital_asset_id' => $asset->id,
            'source_module' => 'website',
            'title' => 'Largest Contentful Paint is poor',
        ]);

        $recommendation = Recommendation::factory()->create([
            'finding_id' => $finding->id,
            'digital_asset_id' => $asset->id,
            'source_module' => 'website',
            'title' => 'Optimize LCP hero image delivery',
            'action' => 'Compress and lazy-load the hero image; serve a modern format.',
            'rationale' => 'LCP is dominated by an oversized hero image on the landing page.',
            'priority' => 'high',
            'effort' => 'medium',
            'status' => 'open',
        ]);

        $this->assertDatabaseHas('recommendations', [
            'id' => $recommendation->id,
            'finding_id' => $finding->id,
            'digital_asset_id' => $asset->id,
            'source_module' => 'website',
            'title' => 'Optimize LCP hero image delivery',
            'action' => 'Compress and lazy-load the hero image; serve a modern format.',
            'rationale' => 'LCP is dominated by an oversized hero image on the landing page.',
            'priority' => 'high',
            'effort' => 'medium',
            'status' => 'open',
        ]);

        $recommendation = $recommendation->fresh();

        $this->assertSame('Optimize LCP hero image delivery', $recommendation->title);
        $this->assertSame('high', $recommendation->priority);
        $this->assertTrue($recommendation->finding->is($finding));
        $this->assertTrue($recommendation->digitalAsset->is($asset));
        $this->assertSame('Largest Contentful Paint is poor', $recommendation->finding->title);
    }

    public function test_recommendation_persists_nullable_digital_asset_and_text_fields(): void
    {
        $finding = Finding::factory()->create();

        $recommendation = Recommendation::factory()->create([
            'finding_id' => $finding->id,
            'digital_asset_id' => null,
            'title' => 'Review crawl budget waste',
            'action' => null,
            'rationale' => null,
            'priority' => 'medium',
            'effort' => null,
            'status' => 'open',
        ]);

        $recommendation = $recommendation->fresh();

        $this->assertNull($recommendation->digital_asset_id);
        $this->assertNull($recommendation->action);
        $this->assertNull($recommendation->rationale);
        $this->assertNull($recommendation->effort);
        $this->assertTrue($recommendation->finding->is($finding));
    }

    public function test_recommendations_migration_rollback_cleanly(): void
    {
        $this->assertTrue(Schema::hasTable('recommendations'));

        $this->rollbackUntilTablesMissing('recommendations');

        $this->assertFalse(Schema::hasTable('recommendations'));

        $this->assertSame(0, Artisan::call('migrate'));

        $this->assertTrue(Schema::hasTable('recommendations'));
    }
}
