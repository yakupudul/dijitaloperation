<?php

namespace Tests\Feature\IntelligenceScheduling;

use App\Enums\AutomaticIntelligencePolicyStatus;
use App\Enums\Intelligence\AnalyzerEligibilityDisposition;
use App\Enums\Intelligence\AnalyzerKind;
use App\Enums\Intelligence\IntelligencePlanStatus;
use App\Enums\Intelligence\IntelligenceTriggerSource;
use App\Enums\RecurringScheduleKind;
use App\Events\EvidenceCanonicalized;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\IntelligenceExecutionPlan;
use App\Models\IntelligenceTrigger;
use App\Models\Recommendation;
use App\Models\Run;
use App\Models\Task;
use App\Services\IntelligenceScheduling\AnalyzerDependencyIndex;
use App\Services\IntelligenceScheduling\AutomaticIntelligencePolicyService;
use App\Services\IntelligenceScheduling\EvidenceAnalyticalFingerprintBuilder;
use App\Services\IntelligenceScheduling\IntelligenceTriggerService;
use App\Services\IntelligenceScheduling\ScheduleIntelligenceFromEvidenceService;
use App\Services\RecurringAutomation\RecurringAutomationRegistry;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IntelligenceSchedulingTest extends TestCase
{
    use RefreshDatabase;

    private DigitalAsset $asset;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Queue::fake();
        $this->seed(RoleAndPermissionSeeder::class);

        config([
            'moxdop-intelligence-scheduling.enabled' => true,
            'moxdop-intelligence-scheduling.dispatch_async' => false,
        ]);

        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $this->asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'website',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeCanonical(string $definitionId, array $payload, string $fingerprint = 'ev-1'): Evidence
    {
        $run = Run::factory()->create([
            'digital_asset_id' => $this->asset->id,
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
        ], $payload);

        return Evidence::factory()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $this->asset->id,
            'source_module' => 'search-console',
            'type' => $definitionId,
            'definition_id' => $definitionId,
            'evidence_fingerprint' => $fingerprint.'-'.$this->asset->id.'-'.$definitionId,
            'is_canonical' => true,
            'eligibility_status' => 'eligible',
            'title' => $definitionId,
            'payload' => $payload,
            'generated_by_ai' => false,
            'observed_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function gscDeclinePayload(): array
    {
        return [
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
                    'current_state' => 'VALUE',
                    'previous_state' => 'VALUE',
                ],
            ],
        ];
    }

    #[Test]
    public function evidence_analytical_fingerprint_ignores_updated_at_noise(): void
    {
        $evidence = $this->writeCanonical('gsc.property.period_comparison', $this->gscDeclinePayload());
        $builder = app(EvidenceAnalyticalFingerprintBuilder::class);
        $first = $builder->forEvidence($evidence);
        $evidence->forceFill(['updated_at' => now()->addDay()])->save();
        $second = $builder->forEvidence($evidence->fresh());

        $this->assertSame($first['analytical_fingerprint'], $second['analytical_fingerprint']);
    }

    #[Test]
    public function duplicate_evidence_change_creates_one_trigger(): void
    {
        $this->writeCanonical('gsc.property.period_comparison', $this->gscDeclinePayload());
        $service = app(IntelligenceTriggerService::class);

        $a = $service->recordEvidenceAnalyticalChange(
            $this->asset,
            IntelligenceTriggerSource::EvidenceAnalyticalStateChanged,
        );
        $b = $service->recordEvidenceAnalyticalChange(
            $this->asset,
            IntelligenceTriggerSource::EvidenceAnalyticalStateChanged,
        );

        $this->assertNotNull($a);
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, IntelligenceTrigger::query()->count());
    }

    #[Test]
    public function dependency_index_selects_only_affected_finding_rules(): void
    {
        $index = app(AnalyzerDependencyIndex::class);
        $matched = $index->findingRulesForEvidenceDefinitions(['gsc.property.period_comparison']);
        $unrelated = $index->findingRulesForEvidenceDefinitions(['website.page.word_count']);

        $this->assertNotEmpty($matched);
        foreach ($matched as $rule) {
            $this->assertSame(AnalyzerKind::FindingRule->value, $rule['kind']);
            $this->assertContains('gsc.property.period_comparison', $rule['evidence_definition_ids']);
        }
        $this->assertSame([], $unrelated);
    }

    #[Test]
    public function evidence_change_schedules_affected_finding_rules_only(): void
    {
        $this->writeCanonical('gsc.property.period_comparison', $this->gscDeclinePayload());

        $plan = app(ScheduleIntelligenceFromEvidenceService::class)
            ->handleEvidenceCanonicalized($this->asset, sync: true);

        $this->assertNotNull($plan);
        $this->assertSame(IntelligencePlanStatus::Completed, $plan->status);
        $phases = $plan->analyzers['phases'] ?? [];
        $findingPhase = $phases['PHASE_1_FINDING_RULES'] ?? [];
        $this->assertNotEmpty($findingPhase);
        foreach ($findingPhase as $analyzer) {
            $this->assertContains('gsc.property.period_comparison', $analyzer['evidence_definition_ids']);
        }
        $this->assertSame(1, Finding::query()->count());
        $this->assertSame(0, Recommendation::query()->count());
        $this->assertSame(0, Task::query()->count());
    }

    #[Test]
    public function unchanged_evidence_retry_does_not_duplicate_work_identity(): void
    {
        $this->writeCanonical('gsc.property.period_comparison', $this->gscDeclinePayload());
        $scheduler = app(ScheduleIntelligenceFromEvidenceService::class);

        $first = $scheduler->handleEvidenceCanonicalized($this->asset, sync: true);
        $second = $scheduler->handleEvidenceCanonicalized($this->asset, sync: true);

        $this->assertSame($first?->plan_fingerprint, $second?->plan_fingerprint);
        $this->assertSame(
            1,
            IntelligenceTrigger::query()
                ->where('source_kind', IntelligenceTriggerSource::EvidenceAnalyticalStateChanged)
                ->count(),
        );
        $this->assertSame(1, Finding::query()->count());
    }

    #[Test]
    public function skill_exists_but_automation_disabled_means_zero_ai_analyzers(): void
    {
        $this->writeCanonical('gsc.property.period_comparison', $this->gscDeclinePayload());

        $plan = app(ScheduleIntelligenceFromEvidenceService::class)
            ->handleEvidenceCanonicalized($this->asset, sync: true);

        $aiPhase = $plan?->analyzers['phases']['PHASE_3_AI_SKILLS'] ?? [];
        $this->assertSame([], $aiPhase);
        $this->assertSame(0, ($plan?->metadata['ai_calls'] ?? 0));
    }

    #[Test]
    public function automatic_policy_pins_exact_versions_and_rejects_latest(): void
    {
        $service = app(AutomaticIntelligencePolicyService::class);
        $brand = $this->asset->brand;

        $policy = $service->create($brand, [
            'agent_slug' => 'website-analyst',
            'agent_version' => '1.0.0',
            'skill_signature' => 'website.seo_review@1.0.0',
            'skill_version' => '1.0.0',
            'route_key' => 'default',
            'route_signature' => 'route@1',
            'allowed_trigger_kinds' => [IntelligenceTriggerSource::EvidenceAnalyticalStateChanged->value],
        ], null, [(int) $brand->customer_id], [(int) $brand->id], $this->asset);

        $this->assertTrue($policy->isActive());
        $this->assertSame('1.0.0', $policy->agent_version);
        $this->assertNotSame('latest', strtolower($policy->agent_version));

        $this->expectException(ValidationException::class);
        $service->create($brand, [
            'agent_slug' => 'website-analyst',
            'agent_version' => 'latest',
            'skill_signature' => 'website.seo_review@2.0.0',
            'skill_version' => '2.0.0',
            'route_key' => 'default',
            'route_signature' => 'route@2',
        ], null, [(int) $brand->customer_id], [(int) $brand->id]);
    }

    #[Test]
    public function disabling_policy_blocks_ai_eligibility(): void
    {
        $brand = $this->asset->brand;
        $policy = app(AutomaticIntelligencePolicyService::class)->create($brand, [
            'agent_slug' => 'website-analyst',
            'agent_version' => '1.0.0',
            'skill_signature' => 'website.seo_review@1.0.0',
            'skill_version' => '1.0.0',
            'route_key' => 'default',
            'route_signature' => 'route@1',
            'trigger_on_required_evidence_change' => true,
        ], null, [(int) $brand->customer_id], [(int) $brand->id], $this->asset);

        app(AutomaticIntelligencePolicyService::class)->disable($policy);
        $this->assertSame(AutomaticIntelligencePolicyStatus::Disabled, $policy->fresh()->status);

        $this->writeCanonical('gsc.property.period_comparison', $this->gscDeclinePayload());
        $plan = app(ScheduleIntelligenceFromEvidenceService::class)
            ->handleEvidenceCanonicalized($this->asset, sync: true);

        $aiPhase = $plan?->analyzers['phases']['PHASE_3_AI_SKILLS'] ?? [];
        $this->assertSame([], $aiPhase);
    }

    #[Test]
    public function execution_plan_is_immutable_and_finite_before_ai(): void
    {
        $this->writeCanonical('gsc.property.period_comparison', $this->gscDeclinePayload());
        $plan = app(ScheduleIntelligenceFromEvidenceService::class)
            ->handleEvidenceCanonicalized($this->asset, sync: true);

        $this->assertTrue($plan?->analyzers['finite_before_ai'] ?? false);
        $this->assertFalse($plan?->analyzers['swarm'] ?? true);
        $this->assertNull(AnalyzerKind::tryFrom('GENERAL'));
        $this->assertNull(AnalyzerKind::tryFrom('SWARM'));
        $this->assertNull(AnalyzerKind::tryFrom('OMNISCIENT'));
    }

    #[Test]
    public function prompt63_does_not_directly_insert_findings_or_create_v2_engines(): void
    {
        $this->assertFalse(class_exists('App\\Services\\IntelligenceScheduling\\IntelligenceEngineV2'));
        $this->assertFalse(class_exists('App\\Services\\Findings\\FindingEngineV2'));
        $this->assertFalse(class_exists('App\\Services\\Opportunities\\OpportunityEngineV2'));
        $this->assertFalse(class_exists('App\\Services\\Ai\\AgentRuntimeV2'));
        $this->assertFalse(class_exists('App\\Services\\RecurringAutomation\\SchedulerV2'));
    }

    #[Test]
    public function recurring_registry_includes_intelligence_validity_recheck(): void
    {
        $registry = app(RecurringAutomationRegistry::class);
        $kinds = array_map(static fn ($a) => $a->kind()->value, $registry->all());
        $this->assertContains(RecurringScheduleKind::IntelligenceValidityRecheck->value, $kinds);
    }

    #[Test]
    public function evidence_canonicalized_event_uses_intelligence_scheduler(): void
    {
        $this->writeCanonical('gsc.property.period_comparison', $this->gscDeclinePayload());
        $run = Run::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'module_id' => 'evidence-canonicalization',
            'status' => 'completed',
        ]);

        event(new EvidenceCanonicalized($this->asset, $run));

        $this->assertGreaterThanOrEqual(1, IntelligenceTrigger::query()->count());
        $this->assertGreaterThanOrEqual(1, IntelligenceExecutionPlan::query()->count());
        $this->assertSame(IntelligenceTriggerSource::EvidenceAnalyticalStateChanged, IntelligenceTrigger::query()->first()->source_kind);
    }

    #[Test]
    public function eligibility_disposition_has_no_numeric_score(): void
    {
        $this->assertNull(AnalyzerEligibilityDisposition::tryFrom('SCORE_42'));
        $this->assertSame('AUTOMATION_DISABLED', AnalyzerEligibilityDisposition::AutomationDisabled->value);
        $this->assertSame('AI_BUDGET_BLOCKED', AnalyzerEligibilityDisposition::AiBudgetBlocked->value);
    }

    #[Test]
    public function forbidden_trigger_sources_are_configured(): void
    {
        $forbidden = config('moxdop-intelligence-scheduling.forbidden_trigger_sources');
        $this->assertContains('ACTIVITY', $forbidden);
        $this->assertContains('NOTIFICATION', $forbidden);
        $this->assertContains('AGENT_RESULT', $forbidden);
        $this->assertContains('COLLECTION_RUN_COMPLETED', $forbidden);
        $this->assertNull(IntelligenceTriggerSource::tryFrom('ACTIVITY'));
        $this->assertNull(IntelligenceTriggerSource::tryFrom('AGENT_RESULT'));
    }

    #[Test]
    public function opportunity_dependency_index_respects_finding_rule_links(): void
    {
        $index = app(AnalyzerDependencyIndex::class);
        $findingDeps = $index->findingRulesForEvidenceDefinitions(['gsc.property.period_comparison']);
        $this->assertNotEmpty($findingDeps);
        $stableIds = array_map(static fn (array $r): string => (string) $r['stable_id'], $findingDeps);
        $oppFromFinding = $index->opportunityRulesForChanges([], $stableIds);
        $oppFromUnrelated = $index->opportunityRulesForChanges([], ['finding.rule.that.does.not.exist']);
        $this->assertSame([], $oppFromUnrelated);
        foreach ($oppFromFinding as $rule) {
            $this->assertSame(AnalyzerKind::OpportunityRule->value, $rule['kind']);
            $this->assertTrue(
                array_intersect($rule['finding_rule_stable_ids'], $stableIds) !== []
                || $rule['evidence_definition_ids'] !== [],
            );
        }
    }

    #[Test]
    public function opportunity_rules_linked_to_affected_findings_are_planned(): void
    {
        $index = app(AnalyzerDependencyIndex::class);
        $findings = $index->findingRulesForEvidenceDefinitions(['gsc.property.period_comparison']);
        $this->assertNotEmpty($findings);
        $stableIds = array_map(static fn (array $r): string => (string) $r['stable_id'], $findings);
        $linked = $index->opportunityRulesForChanges(['gsc.property.period_comparison'], $stableIds);

        $this->writeCanonical('gsc.property.period_comparison', $this->gscDeclinePayload());
        $plan = app(ScheduleIntelligenceFromEvidenceService::class)
            ->handleEvidenceCanonicalized($this->asset, sync: true);

        $oppPhase = $plan?->analyzers['phases']['PHASE_2_OPPORTUNITY_RULES'] ?? [];
        $plannedIds = array_map(static fn (array $a): string => (string) $a['analyzer_id'], $oppPhase);
        foreach ($linked as $rule) {
            $this->assertContains($rule['analyzer_id'], $plannedIds);
        }
    }

    #[Test]
    public function collection_run_completed_is_not_a_trigger_source(): void
    {
        $this->assertNull(IntelligenceTriggerSource::tryFrom('COLLECTION_RUN_COMPLETED'));
        $this->assertContains(
            'COLLECTION_RUN_COMPLETED',
            config('moxdop-intelligence-scheduling.forbidden_trigger_sources'),
        );
    }

    #[Test]
    public function no_provider_http_from_intelligence_scheduler_path(): void
    {
        Http::fake();
        $this->writeCanonical('gsc.property.period_comparison', $this->gscDeclinePayload());
        app(ScheduleIntelligenceFromEvidenceService::class)
            ->handleEvidenceCanonicalized($this->asset, sync: true);
        Http::assertNothingSent();
    }
}
