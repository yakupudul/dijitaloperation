<?php

namespace Tests\Feature\Recommendations;

use App\Enums\RecommendationOrigin;
use App\Enums\RecommendationSourceKind;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\Opportunity;
use App\Models\Recommendation;
use App\Models\Run;
use App\Models\Task;
use App\Models\User;
use App\Services\Findings\FindingEvaluationService;
use App\Services\Opportunities\OpportunityEvaluationService;
use App\Services\Recommendations\RecommendationSourceGuard;
use App\Support\Recommendations\RecommendationSourceReference;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RecommendationSourceArchitectureTest extends TestCase
{
    use RefreshDatabase;

    private DigitalAsset $asset;

    private Brand $brand;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->seed(RoleAndPermissionSeeder::class);

        $this->customer = Customer::factory()->create();
        $this->brand = Brand::factory()->create(['customer_id' => $this->customer->id]);
        $this->asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'website',
        ]);

        $user = User::factory()->create();
        $user->assignRole(Roles::ADMIN);
        $this->actingAs($user);
    }

    public function test_no_parallel_recommendation_entity_exists(): void
    {
        $this->assertFalse(class_exists('App\\Models\\RecommendationV2'));
        $this->assertFalse(class_exists('App\\Models\\ProductionRecommendation'));
        $this->assertFalse(class_exists('App\\Models\\CanonicalRecommendation'));
        $this->assertTrue(class_exists(Recommendation::class));

        $this->assertFalse(Schema::hasColumn('recommendations', 'source_id'));
        $this->assertFalse(Schema::hasColumn('recommendations', 'source_type'));
        $this->assertFalse(Schema::hasColumn('recommendations', 'sourceable_id'));
        $this->assertFalse(Schema::hasColumn('recommendations', 'sourceable_type'));
    }

    public function test_source_reference_enforces_xor(): void
    {
        $finding = Finding::factory()->create(['digital_asset_id' => $this->asset->id]);
        $opportunity = $this->makeOpportunity();

        $fromFinding = RecommendationSourceReference::fromFinding($finding);
        $this->assertTrue($fromFinding->isFinding());
        $this->assertSame($finding->id, $fromFinding->findingId());
        $this->assertNull($fromFinding->opportunityId());

        $fromOpportunity = RecommendationSourceReference::fromOpportunity($opportunity);
        $this->assertTrue($fromOpportunity->isOpportunity());
        $this->assertSame($opportunity->id, $fromOpportunity->opportunityId());
        $this->assertNull($fromOpportunity->findingId());

        $this->expectException(ValidationException::class);
        RecommendationSourceReference::fromColumns($finding->id, $opportunity->id);
    }

    public function test_source_reference_rejects_a_sourceless_recommendation(): void
    {
        $this->expectException(ValidationException::class);
        RecommendationSourceReference::fromColumns(null, null);
    }

    public function test_guard_rejects_both_sources_on_the_model(): void
    {
        $finding = Finding::factory()->create(['digital_asset_id' => $this->asset->id]);
        $opportunity = $this->makeOpportunity();

        $invalid = new Recommendation([
            'source_kind' => RecommendationSourceKind::Finding->value,
            'finding_id' => $finding->id,
            'opportunity_id' => $opportunity->id,
            'source_module' => 'website',
            'title' => 'Invalid',
            'priority' => 'medium',
            'status' => Recommendation::STATUS_OPEN,
        ]);

        $this->expectException(ValidationException::class);
        app(RecommendationSourceGuard::class)->assertConsistent($invalid);
    }

    public function test_saving_a_recommendation_without_a_source_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        Recommendation::query()->create([
            'digital_asset_id' => $this->asset->id,
            'source_module' => 'website',
            'title' => 'Sourceless recommendation',
            'priority' => 'medium',
            'status' => Recommendation::STATUS_OPEN,
        ]);
    }

    public function test_finding_and_opportunity_evaluation_generate_no_recommendations(): void
    {
        $this->writeCanonicalGscEvidence();

        app(FindingEvaluationService::class)->evaluateAsset($this->asset);
        $this->assertGreaterThan(0, Finding::query()->count());
        $this->assertSame(0, Recommendation::query()->count());

        app(OpportunityEvaluationService::class)->evaluateAsset($this->asset);
        $this->assertGreaterThan(0, Opportunity::query()->count());
        $this->assertSame(0, Recommendation::query()->count());
        $this->assertSame(0, Task::query()->count());
        Http::assertNothingSent();
    }

    public function test_migration_backfills_legacy_rows_with_finding_source(): void
    {
        $finding = Finding::factory()->create(['digital_asset_id' => $this->asset->id]);

        // Later prompts added migrations after Prompt 41; roll back until the
        // Recommendation source_kind column is gone, then re-migrate.
        $guard = 0;
        while (Schema::hasColumn('recommendations', 'source_kind') && $guard < 250) {
            $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1]));
            $guard++;
        }
        $this->assertFalse(Schema::hasColumn('recommendations', 'source_kind'));

        $legacyId = DB::table('recommendations')->insertGetId([
            'finding_id' => $finding->id,
            'digital_asset_id' => $this->asset->id,
            'source_module' => 'website-diagnosis',
            'title' => 'Legacy recommendation',
            'action' => 'Legacy action',
            'rationale' => null,
            'priority' => 'medium',
            'effort' => null,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(0, Artisan::call('migrate'));
        $this->assertTrue(Schema::hasColumn('recommendations', 'source_kind'));

        $this->assertDatabaseHas('recommendations', [
            'id' => $legacyId,
            'finding_id' => $finding->id,
            'source_kind' => RecommendationSourceKind::Finding->value,
            'origin' => RecommendationOrigin::Legacy->value,
            'opportunity_id' => null,
            'title' => 'Legacy recommendation',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeOpportunity(array $attributes = []): Opportunity
    {
        return Opportunity::factory()->create(array_merge([
            'digital_asset_id' => $this->asset->id,
            'brand_id' => $this->brand->id,
            'customer_id' => $this->customer->id,
            'status' => Opportunity::STATUS_OPEN,
            'title' => 'Organic click recovery potential',
            'description' => 'Organic clicks declined against the previous period.',
            'qualitative_priority' => 'high',
        ], $attributes));
    }

    private function writeCanonicalGscEvidence(): Evidence
    {
        $run = Run::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'module_id' => 'evidence-canonicalization',
            'status' => 'completed',
        ]);

        return Evidence::factory()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $this->asset->id,
            'source_module' => 'search-console',
            'type' => 'gsc.property.period_comparison',
            'definition_id' => 'gsc.property.period_comparison',
            'evidence_fingerprint' => 'rec-arch-'.$this->asset->id,
            'is_canonical' => true,
            'eligibility_status' => 'eligible',
            'title' => 'gsc.property.period_comparison',
            'generated_by_ai' => false,
            'payload' => [
                'definition_id' => 'gsc.property.period_comparison',
                'freshness_state' => 'FRESH',
                'integrity_status' => 'pass',
                'period' => [
                    'current' => ['start' => '2026-07-16', 'end' => '2026-08-12'],
                    'previous' => ['start' => '2026-06-18', 'end' => '2026-07-15'],
                ],
                'metrics' => [
                    'clicks' => [
                        'current' => 50,
                        'previous' => 200,
                        'relative_change' => -0.75,
                        'relative_change_state' => 'VALUE',
                    ],
                    'impressions' => [
                        'current' => 90,
                        'previous' => 100,
                        'relative_change' => -0.1,
                        'relative_change_state' => 'VALUE',
                    ],
                    'ctr' => [
                        'current' => 0.19,
                        'previous' => 0.20,
                        'relative_change' => -0.05,
                        'relative_change_state' => 'VALUE',
                        'current_state' => 'VALUE',
                        'previous_state' => 'VALUE',
                    ],
                ],
            ],
        ]);
    }
}
