<?php

namespace Tests\Feature\Findings;

use App\Enums\FindingConditionState;
use App\Enums\FindingEligibilityDisposition;
use App\Enums\FindingOrigin;
use App\Enums\GoalKind;
use App\Enums\ServiceBrandApplicabilityMode;
use App\Enums\ServiceScopeStatus;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerServiceScope;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\FindingEvaluation;
use App\Models\Recommendation;
use App\Models\Run;
use App\Models\ServiceDefinition;
use App\Models\Task;
use App\Services\BrandIntelligence\BrandGoalService;
use App\Services\BrandIntelligence\BrandOfferingService;
use App\Services\Findings\FindingConditionEvaluator;
use App\Services\Findings\FindingEvaluationService;
use App\Services\Findings\FindingEvidenceEligibilityService;
use App\Services\Findings\FindingReadService;
use App\Services\Findings\FindingRuleRegistry;
use App\Services\Findings\LegacyFindingOriginMigrator;
use App\Services\ServiceScope\CustomerServiceScopeService;
use App\Support\Evidence\Dto\CanonicalEvidenceDto;
use App\Support\Findings\FindingRule;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FindingProductionIntelligenceTest extends TestCase
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

    public function test_registry_validates_and_has_no_executable_expressions(): void
    {
        $registry = app(FindingRuleRegistry::class);
        $registry->validate();
        $this->assertSame('MOXDOP_FINDING_RULES', $registry->registryId());
        $this->assertSame(1, $registry->version());
        $this->assertCount(4, $registry->enabled());
        $this->assertFalse(str_contains(json_encode($registry->registry()), '"expression"'));
        $this->assertFalse(class_exists('App\\Models\\FindingV2'));
        $this->assertFalse(class_exists('App\\Models\\ProductionFinding'));
        $this->assertFalse(class_exists('App\\Models\\CanonicalFinding'));
        $this->assertTrue(class_exists(Finding::class));
    }

    public function test_canonical_evidence_without_matching_rule_creates_zero_findings(): void
    {
        $this->writeCanonical($this->asset, 'website.page.word_count', [
            'metrics' => ['word_count' => ['current' => 42]],
        ]);

        $stats = app(FindingEvaluationService::class)->evaluateAsset($this->asset);
        $this->assertSame(0, Finding::query()->count());
        $this->assertGreaterThan(0, $stats->rulesConsidered);
        $this->assertSame(0, Recommendation::query()->count());
        $this->assertSame(0, Task::query()->count());
    }

    public function test_metric_values_do_not_create_generic_findings(): void
    {
        $this->writeCanonical($this->asset, 'gsc.property.period_comparison', $this->gscPayload(
            clicksPrev: 200,
            clicksCurrent: 210,
            impressionsPrev: 1000,
            impressionsCurrent: 1100,
            ctrPrev: 0.04,
            ctrCurrent: 0.05,
        ));

        app(FindingEvaluationService::class)->evaluateAsset($this->asset);
        $this->assertSame(0, Finding::query()->count());
    }

    public function test_rule_true_creates_one_finding_and_retry_reuses_it(): void
    {
        $this->writeCanonical($this->asset, 'gsc.property.period_comparison', $this->gscPayload(
            clicksPrev: 200,
            clicksCurrent: 50,
            impressionsPrev: 100,
            impressionsCurrent: 90,
            ctrPrev: 0.20,
            ctrCurrent: 0.19,
        ));

        $first = app(FindingEvaluationService::class)->evaluateAsset($this->asset);
        $this->assertSame(1, $first->findingsCreated);
        $this->assertSame(1, Finding::query()->count());
        $finding = Finding::query()->firstOrFail();
        $this->assertSame('website:gsc:clicks-decline', $finding->fingerprint);
        $this->assertSame('website:gsc:clicks-decline', $finding->rule_id);
        $this->assertSame(FindingOrigin::RuleEngine->value, $finding->origin);
        $this->assertSame('open', $finding->status);
        $this->assertSame(FindingConditionState::True->value, $finding->condition_state);
        $this->assertStringNotContainsString('Fix', $finding->title);
        $this->assertStringNotContainsString('Investigate', (string) $finding->summary);
        $this->assertSame(1, FindingEvaluation::query()->count());
        $this->assertNull($finding->severity_score ?? null);

        $second = app(FindingEvaluationService::class)->evaluateAsset($this->asset);
        $this->assertSame(0, $second->findingsCreated);
        $this->assertGreaterThanOrEqual(1, $second->evaluationsReused);
        $this->assertSame(1, Finding::query()->count());
        $this->assertSame(1, FindingEvaluation::query()->count());
        $this->assertSame(0, Recommendation::query()->count());
        Http::assertNothingSent();
    }

    public function test_same_rule_different_subject_and_brand_are_distinct(): void
    {
        $this->writeCanonical($this->asset, 'gsc.property.period_comparison', $this->gscClicksDeclinePayload());
        app(FindingEvaluationService::class)->evaluateAsset($this->asset);

        $otherBrand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $other = DigitalAsset::factory()->create(['brand_id' => $otherBrand->id, 'type' => 'website']);
        $this->writeCanonical($other, 'gsc.property.period_comparison', $this->gscClicksDeclinePayload());
        app(FindingEvaluationService::class)->evaluateAsset($other);

        $this->assertSame(2, Finding::query()->where('fingerprint', 'website:gsc:clicks-decline')->count());
        $this->assertNotSame(
            Finding::query()->where('digital_asset_id', $this->asset->id)->value('id'),
            Finding::query()->where('digital_asset_id', $other->id)->value('id'),
        );
    }

    public function test_same_title_different_rules_are_not_deduped(): void
    {
        $this->writeCanonical($this->asset, 'gsc.property.period_comparison', $this->gscPayload(
            clicksPrev: 200,
            clicksCurrent: 50,
            impressionsPrev: 1000,
            impressionsCurrent: 300,
            ctrPrev: 0.10,
            ctrCurrent: 0.04,
        ));

        app(FindingEvaluationService::class)->evaluateAsset($this->asset);
        $this->assertGreaterThanOrEqual(2, Finding::query()->where('digital_asset_id', $this->asset->id)->count());
        $this->assertNotSame(
            Finding::query()->where('rule_id', 'website:gsc:clicks-decline')->value('id'),
            Finding::query()->where('rule_id', 'website:gsc:impressions-decline')->value('id'),
        );
    }

    public function test_new_evidence_revision_creates_new_evaluation_same_finding(): void
    {
        $first = $this->writeCanonical($this->asset, 'gsc.property.period_comparison', $this->gscClicksDeclinePayload(), 'fp-a');
        app(FindingEvaluationService::class)->evaluateAsset($this->asset);
        $findingId = Finding::query()->value('id');

        $first->update([
            'evidence_fingerprint' => 'fp-b',
            'payload' => $this->gscClicksDeclinePayload(clicksCurrent: 40),
        ]);

        app(FindingEvaluationService::class)->evaluateAsset($this->asset);
        $this->assertSame(1, Finding::query()->count());
        $this->assertSame($findingId, Finding::query()->value('id'));
        $this->assertSame(2, FindingEvaluation::query()->count());
        $this->assertNotSame(
            FindingEvaluation::query()->orderBy('id')->value('evaluation_fingerprint'),
            FindingEvaluation::query()->orderByDesc('id')->value('evaluation_fingerprint'),
        );
    }

    public function test_missing_stale_partial_and_integrity_do_not_auto_resolve(): void
    {
        $this->writeCanonical($this->asset, 'gsc.property.period_comparison', $this->gscClicksDeclinePayload());
        app(FindingEvaluationService::class)->evaluateAsset($this->asset);
        $finding = Finding::query()->firstOrFail();
        $this->assertSame('open', $finding->status);

        Evidence::query()->delete();
        app(FindingEvaluationService::class)->evaluateAsset($this->asset);
        $this->assertSame('open', $finding->fresh()->status);

        $this->writeCanonical($this->asset, 'gsc.property.period_comparison', $this->gscClicksDeclinePayload(), 'stale', [
            'freshness_state' => 'STALE',
        ]);
        app(FindingEvaluationService::class)->evaluateAsset($this->asset);
        $this->assertSame('open', $finding->fresh()->status);

        Evidence::query()->delete();
        $this->writeCanonical($this->asset, 'gsc.property.period_comparison', $this->gscClicksDeclinePayload(), 'partial', [
            'freshness_state' => 'PARTIAL',
        ]);
        app(FindingEvaluationService::class)->evaluateAsset($this->asset);
        $this->assertSame('open', $finding->fresh()->status);

        Evidence::query()->delete();
        $this->writeCanonical($this->asset, 'gsc.property.period_comparison', $this->gscClicksDeclinePayload(), 'integrity', [
            'integrity_status' => 'fail',
        ]);
        app(FindingEvaluationService::class)->evaluateAsset($this->asset);
        $this->assertSame('open', $finding->fresh()->status);
        $this->assertSame(0, Recommendation::query()->count());
    }

    public function test_trusted_clear_proof_auto_resolves_and_reopens_same_finding(): void
    {
        $evidence = $this->writeCanonical($this->asset, 'gsc.property.period_comparison', $this->gscClicksDeclinePayload());
        app(FindingEvaluationService::class)->evaluateAsset($this->asset);
        $finding = Finding::query()->firstOrFail();
        $this->assertSame('open', $finding->status);

        $evidence->update(['payload' => $this->gscPayload(
            clicksPrev: 200,
            clicksCurrent: 190,
            impressionsPrev: 100,
            impressionsCurrent: 90,
            ctrPrev: 0.2,
            ctrCurrent: 0.19,
        ), 'evidence_fingerprint' => 'cleared']);

        app(FindingEvaluationService::class)->evaluateAsset($this->asset);
        $finding = $finding->fresh();
        $this->assertSame('resolved', $finding->status);
        $this->assertNotNull($finding->resolved_at);

        $evidence->update(['payload' => $this->gscClicksDeclinePayload(), 'evidence_fingerprint' => 'again']);
        app(FindingEvaluationService::class)->evaluateAsset($this->asset);
        $finding = $finding->fresh();
        $this->assertSame('open', $finding->status);
        $this->assertNull($finding->resolved_at);
        $this->assertSame(1, Finding::query()->count());
        $this->assertGreaterThanOrEqual(3, FindingEvaluation::query()->count());
    }

    public function test_acknowledged_finding_is_not_duplicated(): void
    {
        $this->writeCanonical($this->asset, 'gsc.property.period_comparison', $this->gscClicksDeclinePayload());
        app(FindingEvaluationService::class)->evaluateAsset($this->asset);
        $finding = Finding::query()->firstOrFail();
        $finding->update(['status' => 'acknowledged']);

        $finding->latestEvaluation?->delete();
        FindingEvaluation::query()->delete();
        Evidence::query()->update(['evidence_fingerprint' => 'ack-2', 'payload' => $this->gscClicksDeclinePayload(clicksCurrent: 45)]);

        app(FindingEvaluationService::class)->evaluateAsset($this->asset);
        $this->assertSame(1, Finding::query()->count());
        $this->assertSame('acknowledged', Finding::query()->value('status'));
        $this->assertSame(FindingConditionState::True->value, Finding::query()->value('condition_state'));
    }

    public function test_ga4_sessions_rule_does_not_claim_qualified_leads_or_users(): void
    {
        $this->writeCanonical($this->asset, 'ga4.property.period_comparison', [
            'metrics' => [
                'sessions' => [
                    'current' => 20,
                    'previous' => 120,
                    'relative_change' => -0.833,
                    'relative_change_state' => 'VALUE',
                ],
                'activeUsers' => [
                    'current' => 10,
                    'previous' => 100,
                    'relative_change' => -0.9,
                    'relative_change_state' => 'VALUE',
                ],
            ],
            'freshness_state' => 'FRESH',
            'integrity_status' => 'pass',
            'period' => ['current' => ['start' => '2026-07-16', 'end' => '2026-08-12']],
        ]);

        app(FindingEvaluationService::class)->evaluateAsset($this->asset);
        $finding = Finding::query()->where('rule_id', 'website:ga4:sessions-decline')->first();
        $this->assertNotNull($finding);
        $this->assertStringContainsString('sessions', strtolower($finding->title));
        $this->assertStringNotContainsString('qualified', strtolower((string) $finding->summary));
        $this->assertStringNotContainsString('lead', strtolower((string) $finding->summary));
        $this->assertSame(0, Finding::query()->where('rule_id', 'website:ga4:users-decline')->count());
    }

    public function test_word_count_and_search_volume_do_not_become_findings(): void
    {
        $this->writeCanonical($this->asset, 'website.page.word_count', ['metrics' => ['word_count' => ['current' => 12]]]);
        $this->writeCanonical($this->asset, 'dataforseo.keyword.search_volume', ['metrics' => ['search_volume' => ['current' => 12100]]]);
        app(FindingEvaluationService::class)->evaluateAsset($this->asset);
        $this->assertSame(0, Finding::query()->count());
    }

    public function test_goal_and_offering_are_not_inferred_from_names(): void
    {
        $goal = app(BrandGoalService::class)->create($this->asset->brand, GoalKind::Business, 'Grow consults');
        $offering = app(BrandOfferingService::class)->resolveOrCreate($this->asset->brand, 'Dental Implant')['offering'];

        $payload = $this->gscClicksDeclinePayload();
        $payload['query'] = 'Dental Implant';
        $payload['event_name'] = 'Grow consults';
        $payload['campaign_name'] = 'Dental Implant';
        $this->writeCanonical($this->asset, 'gsc.property.period_comparison', $payload);

        app(FindingEvaluationService::class)->evaluateAsset($this->asset);
        $finding = Finding::query()->firstOrFail();
        $this->assertNull($finding->brand_goal_id);
        $this->assertNull($finding->brand_offering_id);
        $this->assertNotSame($goal->id, $finding->brand_goal_id);
        $this->assertNotSame($offering->id, $finding->brand_offering_id);
    }

    public function test_explicit_evidence_goal_is_inherited(): void
    {
        $goal = app(BrandGoalService::class)->create($this->asset->brand, GoalKind::Business, 'Grow consults');
        $row = $this->writeCanonical($this->asset, 'gsc.property.period_comparison', $this->gscClicksDeclinePayload());
        $row->update(['brand_goal_id' => $goal->id]);

        app(FindingEvaluationService::class)->evaluateAsset($this->asset);
        $this->assertSame($goal->id, Finding::query()->value('brand_goal_id'));
    }

    public function test_inactive_service_scope_does_not_suppress_or_create_scope(): void
    {
        $service = ServiceDefinition::query()->first();
        if ($service instanceof ServiceDefinition) {
            app(CustomerServiceScopeService::class)->create(
                $this->asset->brand->customer,
                $service,
                ServiceScopeStatus::Ended,
                ServiceBrandApplicabilityMode::CustomerWide,
            );
        }
        $scopeCount = CustomerServiceScope::query()->count();
        $this->writeCanonical($this->asset, 'gsc.property.period_comparison', $this->gscClicksDeclinePayload());
        app(FindingEvaluationService::class)->evaluateAsset($this->asset);
        $this->assertSame(1, Finding::query()->count());
        $this->assertSame($scopeCount, CustomerServiceScope::query()->count());
    }

    public function test_read_service_exposes_ids_not_raw_payload_and_empty_is_empty(): void
    {
        $empty = app(FindingReadService::class)->forAsset($this->asset);
        $this->assertSame([], $empty);

        $this->writeCanonical($this->asset, 'gsc.property.period_comparison', $this->gscClicksDeclinePayload());
        app(FindingEvaluationService::class)->evaluateAsset($this->asset);
        $rows = app(FindingReadService::class)->forAsset($this->asset);
        $this->assertCount(1, $rows);
        $this->assertNotEmpty($rows[0]->supportingEvidenceIds);
        $this->assertArrayNotHasKey('payload', $rows[0]->toArray());
        $this->assertSame($this->asset->id, $rows[0]->digitalAssetId);
    }

    public function test_legacy_origin_migration_is_idempotent_and_does_not_guess(): void
    {
        $mapped = Finding::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'fingerprint' => 'website:gsc:clicks-decline',
            'origin' => FindingOrigin::LegacyUnverified->value,
        ]);
        $unverified = Finding::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'fingerprint' => 'manual-operator-note',
            'origin' => FindingOrigin::LegacyUnverified->value,
        ]);
        $recommendation = Recommendation::factory()->create([
            'finding_id' => $unverified->id,
            'digital_asset_id' => $this->asset->id,
        ]);

        $migrator = app(LegacyFindingOriginMigrator::class);
        $first = $migrator->migrate();
        $this->assertSame(1, $first['mapped']);
        $this->assertSame(1, $first['unverified']);
        $second = $migrator->migrate();
        $this->assertSame(0, $second['mapped']);
        $this->assertGreaterThanOrEqual(1, $second['skipped']);
        $this->assertSame('website:gsc:clicks-decline', $mapped->fresh()->rule_id);
        $this->assertSame(FindingOrigin::RuleEngine->value, $mapped->fresh()->origin);
        $this->assertNull($unverified->fresh()->rule_id);
        $this->assertSame(FindingOrigin::LegacyUnverified->value, $unverified->fresh()->origin);
        $this->assertTrue(Recommendation::query()->whereKey($recommendation->id)->exists());
        $this->assertSame($unverified->id, $recommendation->fresh()->finding_id);
    }

    public function test_multi_evidence_mismatch_is_blocked(): void
    {
        $rule = FindingRule::fromArray([
            'id' => 'test.multi',
            'stable_id' => 'test:multi',
            'version' => 1,
            'enabled' => true,
            'meaning' => 'test',
            'category' => 'performance',
            'severity' => 'low',
            'source_module' => 'website',
            'evidence_definition_ids' => ['gsc.property.period_comparison', 'ga4.property.period_comparison'],
            'subject' => ['kind' => 'digital_asset', 'grain' => 'PER_DIGITAL_ASSET'],
            'cardinality' => ['strategy' => 'PER_DIGITAL_ASSET', 'max_per_digital_asset' => 1, 'bound' => 'one'],
            'activation' => ['combiner' => 'ALL', 'conditions' => [
                ['type' => 'VALUE_GTE', 'path' => 'metrics.clicks.previous', 'value' => 1],
            ]],
            'auto_resolve' => false,
            'reopen_policy' => 'REOPEN_SAME_FINDING',
            'currency_policy' => 'must_match',
        ]);

        $gsc = CanonicalEvidenceDto::fromModel($this->writeCanonical($this->asset, 'gsc.property.period_comparison', [
            'period' => ['current' => ['start' => 'a']],
            'currency' => 'TRY',
            'metrics' => ['clicks' => ['previous' => 10, 'relative_change_state' => 'VALUE']],
            'freshness_state' => 'FRESH',
            'integrity_status' => 'pass',
        ]));
        $other = DigitalAsset::factory()->create(['brand_id' => $this->asset->brand_id]);
        $ga4 = CanonicalEvidenceDto::fromModel($this->writeCanonical($other, 'ga4.property.period_comparison', [
            'period' => ['current' => ['start' => 'b']],
            'currency' => 'USD',
            'metrics' => ['sessions' => ['previous' => 10, 'relative_change_state' => 'VALUE']],
            'freshness_state' => 'FRESH',
            'integrity_status' => 'pass',
        ]));

        $report = app(FindingEvidenceEligibilityService::class)->evaluate($rule, $this->asset, [$gsc, $ga4]);
        $this->assertFalse($report->isEligible());
        $this->assertContains($report->disposition, [
            FindingEligibilityDisposition::ScopeMismatch,
            FindingEligibilityDisposition::PeriodMismatch,
            FindingEligibilityDisposition::CurrencyMismatch,
        ]);
    }

    public function test_condition_evaluator_does_not_treat_null_as_false(): void
    {
        $rule = FindingRule::fromArray([
            'id' => 'test.null',
            'stable_id' => 'test:null',
            'version' => 1,
            'enabled' => true,
            'meaning' => 'test',
            'category' => 'performance',
            'severity' => 'low',
            'source_module' => 'website',
            'evidence_definition_ids' => ['gsc.property.period_comparison'],
            'subject' => ['kind' => 'digital_asset', 'grain' => 'PER_DIGITAL_ASSET'],
            'cardinality' => ['strategy' => 'PER_DIGITAL_ASSET', 'max_per_digital_asset' => 1, 'bound' => 'one'],
            'activation' => ['combiner' => 'ALL', 'conditions' => [
                ['type' => 'VALUE_LT', 'path' => 'metrics.clicks.relative_change', 'value' => -0.2],
            ]],
            'auto_resolve' => false,
            'reopen_policy' => 'REOPEN_SAME_FINDING',
        ]);
        $dto = CanonicalEvidenceDto::fromModel($this->writeCanonical($this->asset, 'gsc.property.period_comparison', [
            'metrics' => ['clicks' => ['relative_change' => null]],
            'freshness_state' => 'FRESH',
        ]));
        $result = app(FindingConditionEvaluator::class)->activation($rule, [$dto]);
        $this->assertNull($result['result']);
    }

    public function test_high_cardinality_unmapped_rows_do_not_spam_findings(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->writeCanonical($this->asset, 'gsc.query.ctr', [
                'metrics' => ['ctr' => ['current' => 0.01]],
                'query' => 'q-'.$i,
            ], 'q-'.$i);
        }
        app(FindingEvaluationService::class)->evaluateAsset($this->asset);
        $this->assertSame(0, Finding::query()->count());
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
            'source_module' => 'search-console',
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
                    'current_state' => 'VALUE',
                    'previous_state' => 'VALUE',
                ],
            ],
            'freshness_state' => 'FRESH',
            'integrity_status' => 'pass',
        ];
    }
}
