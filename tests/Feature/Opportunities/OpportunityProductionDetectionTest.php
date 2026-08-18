<?php

namespace Tests\Feature\Opportunities;

use App\Enums\OpportunityCommercialScopeState;
use App\Enums\OpportunityDetectionState;
use App\Enums\OpportunityOrigin;
use App\Enums\ServiceBrandApplicabilityMode;
use App\Enums\ServiceScopeStatus;
use App\Livewire\Demo\Operations\OpportunitiesIndex;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerServiceScope;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\Opportunity;
use App\Models\OpportunityEvaluation;
use App\Models\Recommendation;
use App\Models\Run;
use App\Models\ServiceDefinition;
use App\Models\Task;
use App\Models\User;
use App\Services\Findings\FindingEvaluationService;
use App\Services\Opportunities\OpportunityDispositionService;
use App\Services\Opportunities\OpportunityEvaluationService;
use App\Services\Opportunities\OpportunityReadService;
use App\Services\Opportunities\OpportunityRuleRegistry;
use App\Services\ServiceScope\CustomerServiceScopeService;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class OpportunityProductionDetectionTest extends TestCase
{
    use RefreshDatabase;

    private DigitalAsset $asset;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->seed(RoleAndPermissionSeeder::class);

        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $this->asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'website',
        ]);
    }

    public function test_registry_validates_without_magic_score_or_expressions(): void
    {
        $registry = app(OpportunityRuleRegistry::class);
        $registry->validate();
        $this->assertSame('MOXDOP_OPPORTUNITY_RULES', $registry->registryId());
        $this->assertSame(1, $registry->version());
        $this->assertCount(3, $registry->enabled());
        $json = json_encode($registry->registry());
        $this->assertFalse(str_contains($json, '"expression"'));
        $this->assertFalse(str_contains($json, '"opportunity_score"'));
        $this->assertFalse(str_contains($json, '"impact_score"'));
        $this->assertFalse(class_exists('App\\Models\\OpportunityV2'));
        $this->assertFalse(class_exists('App\\Models\\ProductionOpportunity'));
        $this->assertFalse(class_exists('App\\Models\\CanonicalOpportunity'));
        $this->assertTrue(class_exists(Opportunity::class));
        $this->assertTrue(Schema::hasTable('opportunities'));
        $this->assertTrue(Schema::hasTable('opportunity_evaluations'));
        $this->assertFalse(Schema::hasColumn('opportunities', 'score'));
        $this->assertFalse(Schema::hasColumn('opportunities', 'opportunity_score'));
    }

    public function test_evidence_alone_creates_zero_opportunities(): void
    {
        $this->writeCanonical($this->asset, 'gsc.property.period_comparison', $this->gscClicksDeclinePayload());

        $stats = app(OpportunityEvaluationService::class)->evaluateAsset($this->asset);
        $this->assertSame(0, Opportunity::query()->count());
        $this->assertGreaterThan(0, $stats->rulesConsidered);
        $this->assertSame(0, Recommendation::query()->count());
        $this->assertSame(0, Task::query()->count());
        Http::assertNothingSent();
    }

    public function test_finding_evaluation_creates_zero_opportunities(): void
    {
        $this->writeCanonical($this->asset, 'gsc.property.period_comparison', $this->gscClicksDeclinePayload());
        $before = Opportunity::query()->count();
        app(FindingEvaluationService::class)->evaluateAsset($this->asset);
        $this->assertSame(1, Finding::query()->count());
        $this->assertSame($before, Opportunity::query()->count());
        $this->assertSame(0, Opportunity::query()->count());
    }

    public function test_open_finding_without_opportunity_rule_match_creates_zero(): void
    {
        $this->writeCanonical($this->asset, 'gsc.property.period_comparison', $this->gscPayload(
            clicksPrev: 200,
            clicksCurrent: 210,
            impressionsPrev: 5000,
            impressionsCurrent: 1000,
            ctrPrev: 0.04,
            ctrCurrent: 0.04,
        ));
        app(FindingEvaluationService::class)->evaluateAsset($this->asset);
        $this->assertGreaterThan(0, Finding::query()->count());

        app(OpportunityEvaluationService::class)->evaluateAsset($this->asset);
        $this->assertSame(0, Opportunity::query()->where('rule_id', 'website:gsc:organic-click-recovery')->count());
    }

    public function test_rule_true_creates_one_opportunity_and_retry_reuses(): void
    {
        $this->seedClicksDeclineFinding();

        $first = app(OpportunityEvaluationService::class)->evaluateAsset($this->asset);
        $this->assertSame(1, $first->opportunitiesCreated);
        $this->assertSame(1, Opportunity::query()->count());

        $opportunity = Opportunity::query()->firstOrFail();
        $this->assertSame('website:gsc:organic-click-recovery', $opportunity->rule_id);
        $this->assertStringStartsWith('website:gsc:organic-click-recovery:', $opportunity->fingerprint);
        $this->assertNotEmpty($opportunity->semantic_fingerprint);
        $this->assertSame(OpportunityOrigin::RuleEngine->value, $opportunity->origin);
        $this->assertSame(Opportunity::STATUS_OPEN, $opportunity->status);
        $this->assertSame(OpportunityDetectionState::Detected->value, $opportunity->detection_state);
        $this->assertSame('high', $opportunity->qualitative_priority);
        $this->assertStringNotContainsString('Create', $opportunity->title);
        $this->assertStringNotContainsString('Publish', $opportunity->title);
        $this->assertNull($opportunity->getAttribute('score') ?? null);

        $second = app(OpportunityEvaluationService::class)->evaluateAsset($this->asset);
        $this->assertSame(1, Opportunity::query()->count());
        $this->assertSame(1, OpportunityEvaluation::query()->count());
        $this->assertGreaterThan(0, $second->evaluationsReused + $second->opportunitiesReused);
        $this->assertSame(0, Recommendation::query()->count());
        $this->assertSame(0, Task::query()->count());
        Http::assertNothingSent();
    }

    public function test_new_evidence_revision_creates_new_evaluation_same_opportunity(): void
    {
        $this->seedClicksDeclineFinding('ev-1');
        app(OpportunityEvaluationService::class)->evaluateAsset($this->asset);
        $opportunityId = Opportunity::query()->value('id');

        Evidence::query()->delete();
        Finding::query()->update(['last_seen_at' => now()]);
        $this->writeCanonical($this->asset, 'gsc.property.period_comparison', $this->gscClicksDeclinePayload(40), 'ev-2');
        app(FindingEvaluationService::class)->evaluateAsset($this->asset);
        app(OpportunityEvaluationService::class)->evaluateAsset($this->asset);

        $this->assertSame(1, Opportunity::query()->count());
        $this->assertSame($opportunityId, Opportunity::query()->value('id'));
        $this->assertGreaterThan(1, OpportunityEvaluation::query()->count());
    }

    public function test_different_brands_produce_different_opportunities(): void
    {
        $this->seedClicksDeclineFinding();
        app(OpportunityEvaluationService::class)->evaluateAsset($this->asset);

        $otherBrand = Brand::factory()->create(['customer_id' => $this->asset->brand->customer_id]);
        $otherAsset = DigitalAsset::factory()->create(['brand_id' => $otherBrand->id, 'type' => 'website']);
        $this->writeCanonical($otherAsset, 'gsc.property.period_comparison', $this->gscClicksDeclinePayload(), 'other-ev');
        app(FindingEvaluationService::class)->evaluateAsset($otherAsset);
        app(OpportunityEvaluationService::class)->evaluateAsset($otherAsset);

        $this->assertSame(2, Opportunity::query()->count());
        $this->assertNotSame(
            Opportunity::query()->where('digital_asset_id', $this->asset->id)->value('semantic_fingerprint'),
            Opportunity::query()->where('digital_asset_id', $otherAsset->id)->value('semantic_fingerprint'),
        );
    }

    public function test_missing_stale_partial_integrity_do_not_close(): void
    {
        $this->seedClicksDeclineFinding();
        app(OpportunityEvaluationService::class)->evaluateAsset($this->asset);
        $opportunity = Opportunity::query()->firstOrFail();
        $this->assertNull($opportunity->closed_at);

        Evidence::query()->delete();
        app(OpportunityEvaluationService::class)->evaluateAsset($this->asset);
        $this->assertNull($opportunity->fresh()->closed_at);

        $this->writeCanonical($this->asset, 'gsc.property.period_comparison', array_merge(
            $this->gscClicksDeclinePayload(),
            ['freshness_state' => 'STALE'],
        ), 'stale-ev');
        app(OpportunityEvaluationService::class)->evaluateAsset($this->asset);
        $this->assertNull($opportunity->fresh()->closed_at);

        Evidence::query()->delete();
        $this->writeCanonical($this->asset, 'gsc.property.period_comparison', array_merge(
            $this->gscClicksDeclinePayload(),
            ['freshness_state' => 'PARTIAL'],
        ), 'partial-ev');
        app(OpportunityEvaluationService::class)->evaluateAsset($this->asset);
        $this->assertNull($opportunity->fresh()->closed_at);

        Evidence::query()->delete();
        $this->writeCanonical($this->asset, 'gsc.property.period_comparison', array_merge(
            $this->gscClicksDeclinePayload(),
            ['integrity_status' => 'fail', 'freshness_state' => 'FRESH'],
        ), 'bad-integrity');
        app(OpportunityEvaluationService::class)->evaluateAsset($this->asset);
        $this->assertNull($opportunity->fresh()->closed_at);
        $this->assertSame(1, Opportunity::query()->count());
    }

    public function test_explicit_clear_closes_and_reopen_reuses_same_opportunity(): void
    {
        $this->seedClicksDeclineFinding();
        app(OpportunityEvaluationService::class)->evaluateAsset($this->asset);
        $opportunity = Opportunity::query()->firstOrFail();

        Finding::query()->update([
            'status' => Finding::STATUS_RESOLVED,
            'resolved_at' => now(),
            'condition_state' => 'false',
        ]);

        $clearStats = app(OpportunityEvaluationService::class)->evaluateAsset($this->asset);
        $this->assertSame(1, $clearStats->opportunitiesClosed);
        $opportunity->refresh();
        $this->assertNotNull($opportunity->closed_at);
        $this->assertSame(OpportunityDetectionState::NoLongerDetected->value, $opportunity->detection_state);

        Finding::query()->update([
            'status' => Finding::STATUS_OPEN,
            'resolved_at' => null,
            'condition_state' => 'true',
        ]);
        Evidence::query()->delete();
        $this->writeCanonical($this->asset, 'gsc.property.period_comparison', $this->gscClicksDeclinePayload(45), 'reopen-ev');

        $reopen = app(OpportunityEvaluationService::class)->evaluateAsset($this->asset);
        $this->assertSame(1, Opportunity::query()->count());
        $this->assertSame($opportunity->id, Opportunity::query()->value('id'));
        $this->assertSame(1, $reopen->opportunitiesReopened);
        $this->assertNull(Opportunity::query()->firstOrFail()->closed_at);
        $this->assertSame(OpportunityDetectionState::Detected->value, Opportunity::query()->value('detection_state'));
    }

    public function test_dismissed_opportunity_is_not_duplicated_or_reopened(): void
    {
        $this->seedClicksDeclineFinding();
        app(OpportunityEvaluationService::class)->evaluateAsset($this->asset);
        $opportunity = Opportunity::query()->firstOrFail();
        app(OpportunityDispositionService::class)->dismiss($opportunity);

        Finding::query()->update(['status' => Finding::STATUS_RESOLVED, 'resolved_at' => now()]);
        app(OpportunityEvaluationService::class)->evaluateAsset($this->asset);

        Finding::query()->update(['status' => Finding::STATUS_OPEN, 'resolved_at' => null]);
        Evidence::query()->delete();
        $this->writeCanonical($this->asset, 'gsc.property.period_comparison', $this->gscClicksDeclinePayload(30), 'dismiss-ev');
        app(OpportunityEvaluationService::class)->evaluateAsset($this->asset);

        $this->assertSame(1, Opportunity::query()->count());
        $this->assertSame(Opportunity::STATUS_DISMISSED, Opportunity::query()->value('status'));
    }

    public function test_outside_service_scope_still_creates_opportunity_without_creating_scope(): void
    {
        $scopesBefore = CustomerServiceScope::query()->count();
        $this->seedClicksDeclineFinding();
        app(OpportunityEvaluationService::class)->evaluateAsset($this->asset);

        $opportunity = Opportunity::query()->firstOrFail();
        $this->assertSame(OpportunityCommercialScopeState::OutsideCurrentScope->value, $opportunity->commercial_scope_state);
        $this->assertSame($scopesBefore, CustomerServiceScope::query()->count());
    }

    public function test_active_seo_scope_marks_in_current_scope_without_identity_change(): void
    {
        $service = ServiceDefinition::query()->where('code', 'seo')->firstOrFail();
        app(CustomerServiceScopeService::class)->create(
            customer: $this->asset->brand->customer,
            service: $service,
            status: ServiceScopeStatus::Active,
            brandMode: ServiceBrandApplicabilityMode::CustomerWide,
            owner: User::factory()->create(),
        );

        $this->seedClicksDeclineFinding();
        app(OpportunityEvaluationService::class)->evaluateAsset($this->asset);
        $fingerprint = Opportunity::query()->value('fingerprint');
        $this->assertSame(OpportunityCommercialScopeState::InCurrentScope->value, Opportunity::query()->value('commercial_scope_state'));

        CustomerServiceScope::query()->update(['status' => ServiceScopeStatus::Ended->value]);
        Evidence::query()->delete();
        $this->writeCanonical($this->asset, 'gsc.property.period_comparison', $this->gscClicksDeclinePayload(35), 'scope-ev');
        app(FindingEvaluationService::class)->evaluateAsset($this->asset);
        app(OpportunityEvaluationService::class)->evaluateAsset($this->asset);

        $this->assertSame(1, Opportunity::query()->count());
        $this->assertSame($fingerprint, Opportunity::query()->value('fingerprint'));
        $this->assertSame(OpportunityCommercialScopeState::OutsideCurrentScope->value, Opportunity::query()->value('commercial_scope_state'));
        $this->assertNull(Opportunity::query()->value('closed_at'));
    }

    public function test_no_goal_or_offering_text_inference(): void
    {
        $this->seedClicksDeclineFinding();
        app(OpportunityEvaluationService::class)->evaluateAsset($this->asset);
        $opportunity = Opportunity::query()->firstOrFail();
        $this->assertNull($opportunity->brand_goal_id);
        $this->assertNull($opportunity->brand_offering_id);
    }

    public function test_search_volume_and_keyword_evidence_do_not_spam(): void
    {
        for ($i = 0; $i < 40; $i++) {
            $this->writeCanonical($this->asset, 'dataforseo.keyword.search_volume', [
                'metrics' => ['search_volume' => ['current' => 10000 + $i]],
                'keyword' => 'implant-'.$i,
            ], 'kw-'.$i);
        }
        app(OpportunityEvaluationService::class)->evaluateAsset($this->asset);
        $this->assertSame(0, Opportunity::query()->count());
    }

    public function test_operations_index_is_db_backed_without_demo_fallback_or_score(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Roles::ADMIN);
        $this->actingAs($user);

        Livewire::test(OpportunitiesIndex::class)
            ->assertDontSee('High paid implant demand but weak organic coverage')
            ->assertDontSee('Opportunity score');

        $this->seedClicksDeclineFinding();
        app(OpportunityEvaluationService::class)->evaluateAsset($this->asset);

        Livewire::test(OpportunitiesIndex::class)
            ->assertSee('Organic click recovery potential')
            ->assertDontSee('Opportunity score')
            ->assertDontSee('High paid implant demand but weak organic coverage');

        $id = (string) Opportunity::query()->value('id');
        Livewire::test(OpportunitiesIndex::class)
            ->call('review', $id)
            ->call('createRecommendation', $id);

        $this->assertSame(Opportunity::STATUS_CONVERTED, Opportunity::query()->value('status'));

        // Recommendation creation from a converted Opportunity is owned by the Recommendation
        // source architecture (Prompt 41); conversion still creates no Task.
        $this->assertSame(1, Recommendation::query()->where('source_kind', 'opportunity')->count());
        $this->assertSame(0, Task::query()->count());
    }

    public function test_read_service_exposes_context_without_raw_payload_or_score(): void
    {
        $this->seedClicksDeclineFinding();
        app(OpportunityEvaluationService::class)->evaluateAsset($this->asset);
        $rows = app(OpportunityReadService::class)->forListPresentation();
        $this->assertCount(1, $rows);
        $this->assertArrayNotHasKey('opportunity_score', $rows[0]);
        $this->assertArrayNotHasKey('score', $rows[0]);
        $this->assertSame('Organic click recovery potential', $rows[0]['title']);
        $this->assertIsArray($rows[0]['evidence']);
        foreach ($rows[0]['evidence'] as $item) {
            $this->assertArrayNotHasKey('payload', $item);
        }
    }

    public function test_ctr_and_ga4_rules_are_bounded(): void
    {
        $this->writeCanonical($this->asset, 'gsc.property.period_comparison', $this->gscPayload(
            clicksPrev: 200,
            clicksCurrent: 180,
            impressionsPrev: 5000,
            impressionsCurrent: 5200,
            ctrPrev: 0.05,
            ctrCurrent: 0.02,
        ), 'ctr-ev');
        app(FindingEvaluationService::class)->evaluateAsset($this->asset);
        app(OpportunityEvaluationService::class)->evaluateAsset($this->asset);
        $this->assertSame(1, Opportunity::query()->where('rule_id', 'website:gsc:organic-ctr-improvement')->count());

        $this->writeCanonical($this->asset, 'ga4.property.period_comparison', [
            'metrics' => [
                'sessions' => [
                    'previous' => 500,
                    'current' => 200,
                    'relative_change' => -0.6,
                    'relative_change_state' => 'VALUE',
                ],
            ],
            'freshness_state' => 'FRESH',
            'integrity_status' => 'pass',
        ], 'ga4-ev');
        app(FindingEvaluationService::class)->evaluateAsset($this->asset);
        app(OpportunityEvaluationService::class)->evaluateAsset($this->asset);
        $this->assertLessThanOrEqual(3, Opportunity::query()->count());
        $this->assertSame(1, Opportunity::query()->where('rule_id', 'website:ga4:session-recovery')->count());
    }

    private function seedClicksDeclineFinding(string $fingerprint = 'ev-clicks'): void
    {
        $this->writeCanonical($this->asset, 'gsc.property.period_comparison', $this->gscClicksDeclinePayload(), $fingerprint);
        app(FindingEvaluationService::class)->evaluateAsset($this->asset);
        $this->assertSame(1, Finding::query()->where('rule_id', 'website:gsc:clicks-decline')->count());
        $this->assertSame(0, Opportunity::query()->count());
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $overrides
     */
    private function writeCanonical(
        DigitalAsset $asset,
        string $definitionId,
        array $payload,
        string $fingerprint = 'ev-1',
        array $overrides = [],
    ): Evidence {
        $run = Run::factory()->create([
            'digital_asset_id' => $asset->id,
            'module_id' => 'evidence-canonicalization',
            'status' => 'completed',
        ]);

        $payload = array_merge([
            'definition_id' => $definitionId,
            'freshness_state' => 'FRESH',
            'integrity_status' => 'pass',
            'period' => [
                'current' => ['start' => '2026-07-16', 'end' => '2026-08-12'],
                'previous' => ['start' => '2026-06-18', 'end' => '2026-07-15'],
            ],
        ], $payload, $overrides);

        return Evidence::factory()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $asset->id,
            'source_module' => str_starts_with($definitionId, 'ga4') ? 'ga4' : 'search-console',
            'type' => $definitionId,
            'definition_id' => $definitionId,
            'evidence_fingerprint' => $fingerprint.'-'.$asset->id.'-'.$definitionId,
            'is_canonical' => true,
            'eligibility_status' => 'eligible',
            'title' => $definitionId,
            'payload' => $payload,
            'generated_by_ai' => false,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function gscClicksDeclinePayload(int $clicksCurrent = 50): array
    {
        return $this->gscPayload(
            clicksPrev: 200,
            clicksCurrent: $clicksCurrent,
            impressionsPrev: 100,
            impressionsCurrent: 90,
            ctrPrev: 0.20,
            ctrCurrent: 0.19,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function gscPayload(
        float $clicksPrev,
        float $clicksCurrent,
        float $impressionsPrev,
        float $impressionsCurrent,
        float $ctrPrev,
        float $ctrCurrent,
    ): array {
        $clicksChange = $clicksPrev == 0.0 ? null : ($clicksCurrent - $clicksPrev) / $clicksPrev;
        $imprChange = $impressionsPrev == 0.0 ? null : ($impressionsCurrent - $impressionsPrev) / $impressionsPrev;
        $ctrChange = $ctrPrev == 0.0 ? null : ($ctrCurrent - $ctrPrev) / $ctrPrev;

        return [
            'metrics' => [
                'clicks' => [
                    'current' => $clicksCurrent,
                    'previous' => $clicksPrev,
                    'relative_change' => $clicksChange,
                    'relative_change_state' => 'VALUE',
                ],
                'impressions' => [
                    'current' => $impressionsCurrent,
                    'previous' => $impressionsPrev,
                    'relative_change' => $imprChange,
                    'relative_change_state' => 'VALUE',
                ],
                'ctr' => [
                    'current' => $ctrCurrent,
                    'previous' => $ctrPrev,
                    'relative_change' => $ctrChange,
                    'relative_change_state' => 'VALUE',
                    'current_state' => 'VALUE',
                    'previous_state' => 'VALUE',
                ],
            ],
            'freshness_state' => 'FRESH',
            'integrity_status' => 'pass',
        ];
    }
}
