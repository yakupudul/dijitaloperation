<?php

namespace Tests\Feature\Recommendations;

use App\Enums\RecommendationOrigin;
use App\Enums\RecommendationSourceKind;
use App\Livewire\Demo\Operations\OpportunitiesIndex;
use App\Livewire\Demo\Operations\RecommendationsIndex;
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
use App\Services\CreateTaskFromRecommendation;
use App\Services\Findings\FindingEvaluationService;
use App\Services\Opportunities\OpportunityEvaluationService;
use App\Services\Recommendations\CreateRecommendation;
use App\Services\Recommendations\CreateRecommendationFromFinding;
use App\Services\Recommendations\CreateRecommendationFromOpportunity;
use App\Services\Recommendations\RecommendationActivityRecorder;
use App\Services\Recommendations\RecommendationReadService;
use App\Services\Recommendations\RecommendationSourceGuard;
use App\Services\Recommendations\RecommendationSourceResolver;
use App\Services\Recommendations\UpdateRecommendation;
use App\Support\Recommendations\RecommendationSourceReference;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
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

    public function test_create_from_finding_persists_finding_source(): void
    {
        $finding = Finding::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'brand_id' => $this->brand->id,
            'customer_id' => $this->customer->id,
            'source_module' => 'website-diagnosis',
            'title' => 'Sitemap is missing',
        ]);

        $recommendation = app(CreateRecommendationFromFinding::class)->create(
            $finding,
            [
                'title' => 'Fix: Sitemap is missing',
                'action' => 'Publish a valid XML sitemap.',
                'priority' => 'high',
            ],
            RecommendationOrigin::Operator,
        );

        $this->assertSame(RecommendationSourceKind::Finding->value, $recommendation->source_kind);
        $this->assertSame($finding->id, $recommendation->finding_id);
        $this->assertNull($recommendation->opportunity_id);
        $this->assertSame($this->asset->id, $recommendation->digital_asset_id);
        $this->assertSame('website-diagnosis', $recommendation->source_module);
        $this->assertSame(RecommendationOrigin::Operator->value, $recommendation->origin);
        $this->assertTrue($recommendation->isFindingSourced());
        $this->assertFalse($recommendation->isOpportunitySourced());
        $this->assertSame(0, Task::query()->count());
    }

    public function test_create_from_opportunity_never_fabricates_a_finding(): void
    {
        $opportunity = $this->makeOpportunity(['title' => 'Organic click recovery potential']);
        $findingsBefore = Finding::query()->count();

        $recommendation = app(CreateRecommendationFromOpportunity::class)->create(
            $opportunity,
            [
                'title' => 'Act on: Organic click recovery potential',
                'action' => 'Review the declining query set and plan organic coverage work.',
            ],
            RecommendationOrigin::Operator,
        );

        $this->assertSame(RecommendationSourceKind::Opportunity->value, $recommendation->source_kind);
        $this->assertSame($opportunity->id, $recommendation->opportunity_id);
        $this->assertNull($recommendation->finding_id);
        $this->assertSame($findingsBefore, Finding::query()->count());
        $this->assertSame(CreateRecommendation::DEFAULT_OPPORTUNITY_SOURCE_MODULE, $recommendation->source_module);
        $this->assertSame(0, Task::query()->count());
        $this->assertTrue($opportunity->recommendations()->whereKey($recommendation->id)->exists());
    }

    public function test_one_source_can_hold_many_recommendations(): void
    {
        $finding = Finding::factory()->create(['digital_asset_id' => $this->asset->id]);
        $opportunity = $this->makeOpportunity();

        app(CreateRecommendationFromFinding::class)->create($finding, ['title' => 'First finding action']);
        app(CreateRecommendationFromFinding::class)->create($finding, ['title' => 'Second finding action']);
        app(CreateRecommendationFromOpportunity::class)->create($opportunity, ['title' => 'First opportunity action']);
        app(CreateRecommendationFromOpportunity::class)->create($opportunity, ['title' => 'Second opportunity action']);

        $this->assertCount(2, app(RecommendationReadService::class)->forFinding($finding));
        $this->assertCount(2, app(RecommendationReadService::class)->forOpportunity($opportunity));
    }

    public function test_update_cannot_change_the_source(): void
    {
        $finding = Finding::factory()->create(['digital_asset_id' => $this->asset->id]);
        $opportunity = $this->makeOpportunity();
        $recommendation = app(CreateRecommendationFromFinding::class)->create($finding, ['title' => 'Original title']);

        $updated = app(UpdateRecommendation::class)->update($recommendation, [
            'title' => 'Revised title',
            'status' => Recommendation::STATUS_ACCEPTED,
        ]);

        $this->assertSame('Revised title', $updated->title);
        $this->assertSame(Recommendation::STATUS_ACCEPTED, $updated->status);
        $this->assertSame($finding->id, $updated->finding_id);

        try {
            app(UpdateRecommendation::class)->update($recommendation, [
                'opportunity_id' => $opportunity->id,
                'source_kind' => RecommendationSourceKind::Opportunity->value,
            ]);
            $this->fail('Expected the source mutation to be rejected.');
        } catch (ValidationException) {
            // Expected: source is immutable after create.
        }

        $recommendation = $recommendation->fresh();
        $this->assertSame(RecommendationSourceKind::Finding->value, $recommendation->source_kind);
        $this->assertSame($finding->id, $recommendation->finding_id);
        $this->assertNull($recommendation->opportunity_id);
    }

    public function test_resolving_a_finding_does_not_delete_its_recommendation(): void
    {
        $finding = Finding::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'status' => Finding::STATUS_OPEN,
        ]);
        $recommendation = app(CreateRecommendationFromFinding::class)->create($finding, ['title' => 'Fix the thing']);

        $finding->forceFill(['status' => Finding::STATUS_RESOLVED, 'resolved_at' => now()])->save();

        $this->assertTrue(Recommendation::query()->whereKey($recommendation->id)->exists());
        $this->assertSame(Recommendation::STATUS_OPEN, $recommendation->fresh()->status);

        $this->expectException(QueryException::class);
        $finding->delete();
    }

    public function test_closing_an_opportunity_does_not_delete_its_recommendation(): void
    {
        $opportunity = $this->makeOpportunity();
        $recommendation = app(CreateRecommendationFromOpportunity::class)->create($opportunity, ['title' => 'Act on it']);

        $opportunity->forceFill([
            'status' => Opportunity::STATUS_DISMISSED,
            'closed_at' => now(),
        ])->save();

        $this->assertTrue(Recommendation::query()->whereKey($recommendation->id)->exists());

        $this->expectException(QueryException::class);
        $opportunity->delete();
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

    public function test_cross_brand_digital_asset_is_denied(): void
    {
        $otherCustomer = Customer::factory()->create();
        $otherBrand = Brand::factory()->create(['customer_id' => $otherCustomer->id]);
        $otherAsset = DigitalAsset::factory()->create(['brand_id' => $otherBrand->id, 'type' => 'website']);

        $finding = Finding::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'brand_id' => $this->brand->id,
            'customer_id' => $this->customer->id,
        ]);

        $this->expectException(ValidationException::class);

        app(CreateRecommendationFromFinding::class)->create($finding, [
            'title' => 'Cross-brand attempt',
            'digital_asset_id' => $otherAsset->id,
        ]);
    }

    public function test_missing_source_is_rejected_by_the_resolver(): void
    {
        $this->expectException(ValidationException::class);
        app(RecommendationSourceResolver::class)->resolve(RecommendationSourceReference::fromOpportunity(987654));
    }

    public function test_idempotency_key_is_reused_instead_of_duplicating(): void
    {
        $opportunity = $this->makeOpportunity();

        $first = app(CreateRecommendationFromOpportunity::class)->create(
            $opportunity,
            ['title' => 'Act on: opportunity'],
            RecommendationOrigin::Operator,
            null,
            'opportunity-convert:'.$opportunity->id,
        );
        $second = app(CreateRecommendationFromOpportunity::class)->create(
            $opportunity,
            ['title' => 'Act on: opportunity (retry)'],
            RecommendationOrigin::Operator,
            null,
            'opportunity-convert:'.$opportunity->id,
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Recommendation::query()->count());
        $this->assertSame('Act on: opportunity', $second->fresh()->title);
    }

    public function test_livewire_conversion_creates_one_recommendation_and_no_task(): void
    {
        $opportunity = $this->makeOpportunity(['title' => 'Session recovery potential']);
        $id = (string) $opportunity->id;

        Livewire::test(OpportunitiesIndex::class)
            ->set('view', 'all')
            ->call('createRecommendation', $id)
            ->call('createRecommendation', $id);

        $this->assertSame(Opportunity::STATUS_CONVERTED, $opportunity->fresh()->status);
        $this->assertSame(1, Recommendation::query()->count());
        $this->assertSame(0, Task::query()->count());

        $recommendation = Recommendation::query()->sole();
        $this->assertSame('Act on: Session recovery potential', $recommendation->title);
        $this->assertSame(RecommendationSourceKind::Opportunity->value, $recommendation->source_kind);
        $this->assertSame(RecommendationOrigin::Operator->value, $recommendation->origin);
        $this->assertSame('opportunity-convert:'.$opportunity->id, $recommendation->idempotency_key);
    }

    public function test_operations_list_is_db_backed_without_demo_rows(): void
    {
        Livewire::test(RecommendationsIndex::class)
            ->assertDontSee('Review conversion mapping for primary lead signal')
            ->assertDontSee('Replace underperforming Meta creative PB-Video-03')
            ->assertSee('No recommendations are awaiting decision');

        $finding = Finding::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'brand_id' => $this->brand->id,
            'title' => 'Sitemap is missing',
        ]);
        app(CreateRecommendationFromFinding::class)->create($finding, [
            'title' => 'Fix: Sitemap is missing',
            'action' => 'Publish a valid XML sitemap.',
        ]);

        Livewire::test(RecommendationsIndex::class)
            ->assertSee('Fix: Sitemap is missing')
            ->assertDontSee('Review conversion mapping for primary lead signal');

        $rows = app(RecommendationReadService::class)->forListPresentation();
        $this->assertCount(1, $rows);
        $this->assertSame(RecommendationSourceKind::Finding->value, $rows[0]['source_kind']);
        $this->assertSame((string) $finding->id, $rows[0]['finding_id']);
        $this->assertNull($rows[0]['source_opportunity_id']);
        $this->assertStringContainsString('Finding #'.$finding->id, $rows[0]['evidence']);
    }

    public function test_livewire_decisions_persist_and_create_task_explicitly(): void
    {
        $finding = Finding::factory()->create(['digital_asset_id' => $this->asset->id]);
        $accepted = app(CreateRecommendationFromFinding::class)->create($finding, ['title' => 'Accept me']);
        $dismissed = app(CreateRecommendationFromFinding::class)->create($finding, ['title' => 'Dismiss me']);
        $deferred = app(CreateRecommendationFromFinding::class)->create($finding, ['title' => 'Defer me']);

        Livewire::test(RecommendationsIndex::class)
            ->call('approve', (string) $accepted->id)
            ->call('reject', (string) $dismissed->id)
            ->call('defer', (string) $deferred->id)
            ->call('createTask', (string) $accepted->id);

        $this->assertSame(Recommendation::STATUS_ACCEPTED, $accepted->fresh()->status);
        $this->assertSame(Recommendation::STATUS_DISMISSED, $dismissed->fresh()->status);
        $this->assertSame(Recommendation::STATUS_OPEN, $deferred->fresh()->status);
        $this->assertSame(1, Task::query()->count());
        $this->assertSame($accepted->id, Task::query()->first()?->recommendation_id);
        $this->assertSame(Recommendation::STATUS_ACCEPTED, $accepted->fresh()->status);
    }

    public function test_activity_is_recorded_for_create_and_status_change_only(): void
    {
        $opportunity = $this->makeOpportunity();
        $recommendation = app(CreateRecommendationFromOpportunity::class)->create($opportunity, ['title' => 'Act on it']);

        $this->assertDatabaseHas('brand_context_activities', [
            'brand_id' => $this->brand->id,
            'event' => RecommendationActivityRecorder::CREATED,
            'subject_type' => Recommendation::class,
            'subject_id' => $recommendation->id,
        ]);

        app(UpdateRecommendation::class)->accept($recommendation);

        $this->assertDatabaseHas('brand_context_activities', [
            'event' => RecommendationActivityRecorder::STATUS_CHANGED,
            'subject_id' => $recommendation->id,
        ]);

        $before = DB::table('brand_context_activities')->count();
        app(UpdateRecommendation::class)->update($recommendation->fresh(), ['status' => Recommendation::STATUS_ACCEPTED]);
        $this->assertSame($before, DB::table('brand_context_activities')->count());
    }

    public function test_task_snapshot_carries_opportunity_provenance(): void
    {
        $opportunity = $this->makeOpportunity(['title' => 'Organic click recovery potential']);
        $recommendation = app(CreateRecommendationFromOpportunity::class)->create($opportunity, [
            'title' => 'Act on: Organic click recovery potential',
            'action' => 'Plan organic coverage work.',
        ]);

        $task = app(CreateTaskFromRecommendation::class)->create($recommendation);

        $this->assertSame('opportunity', $task->snapshot_json['source_kind']);
        $this->assertNull($task->snapshot_json['finding']);
        $this->assertSame($opportunity->id, $task->snapshot_json['opportunity']['id']);
        $this->assertSame($opportunity->rule_id, $task->snapshot_json['opportunity']['rule_id']);
    }

    public function test_migration_backfills_legacy_rows_with_finding_source(): void
    {
        $finding = Finding::factory()->create(['digital_asset_id' => $this->asset->id]);

        // Later prompts added migrations after Prompt 41; roll back until the
        // Recommendation source_kind column is gone, then re-migrate.
        $guard = 0;
        while (Schema::hasColumn('recommendations', 'source_kind') && $guard < 20) {
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
