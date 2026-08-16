<?php

namespace Tests\Feature\IntelligenceEvaluation;

use App\Enums\IntelligenceEvaluationAssertionStatus;
use App\Enums\IntelligenceEvaluationGateStatus;
use App\Enums\IntelligenceEvaluationHumanRubricOutcome;
use App\Enums\IntelligenceEvaluationLiveModelStatus;
use App\Enums\IntelligenceEvaluationRunMode;
use App\Enums\IntelligenceEvaluationRunStatus;
use App\Models\IntelligenceEvaluationRun;
use App\Models\User;
use App\Services\IntelligenceEvaluation\IntelligenceEvaluationBaselineService;
use App\Services\IntelligenceEvaluation\IntelligenceEvaluationBoundaryGuard;
use App\Services\IntelligenceEvaluation\IntelligenceEvaluationHumanReviewService;
use App\Services\IntelligenceEvaluation\IntelligenceEvaluationRegressionComparer;
use App\Services\IntelligenceEvaluation\IntelligenceEvaluationRunner;
use App\Support\IntelligenceEvaluation\IntelligenceEvaluationCanaries;
use App\Support\IntelligenceEvaluation\IntelligenceEvaluationCaseCatalog;
use App\Support\IntelligenceEvaluation\IntelligenceEvaluationHumanRubric;
use App\Support\IntelligenceEvaluation\IntelligenceEvaluationPolicy;
use App\Support\IntelligenceRetrieval\IntelligenceRetrievalPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntelligenceEvaluationFrameworkTest extends TestCase
{
    use RefreshDatabase;

    public function test_policy_has_no_magic_score_or_auto_tuning(): void
    {
        $snap = IntelligenceEvaluationPolicy::snapshot();
        $this->assertSame('intelligence_evaluation_v1', $snap['version']);
        $this->assertNull($snap['single_ai_score']);
        $this->assertFalse($snap['weighted_composite']);
        $this->assertFalse($snap['auto_tuning']);
        $this->assertFalse($snap['fine_tuning']);
        $this->assertFalse($snap['embeddings']);
        $this->assertFalse($snap['vector_db']);
        $this->assertFalse($snap['similar_customer']);
        $this->assertFalse($snap['judge_sole_authority']);
        $this->assertFalse($snap['ci_live_paid_ai']);
        $this->assertFalse(class_exists('App\\Services\\IntelligenceEvaluation\\IntelligenceEvaluationV2'));
    }

    public function test_new_dental_brand_golden_case_retrieval(): void
    {
        $run = app(IntelligenceEvaluationRunner::class)->runSuite(
            'DENTAL_SPECIALIST',
            IntelligenceEvaluationRunMode::MockedAi,
            [IntelligenceEvaluationCaseCatalog::NEW_DENTAL_BRAND],
            ['idempotency_key' => 'eval-dental-new-1'],
        );

        $this->assertSame(IntelligenceEvaluationRunStatus::Completed, $run->status);
        $this->assertSame(IntelligenceEvaluationGateStatus::Pass, $run->safety_gate_status);
        $this->assertSame(IntelligenceEvaluationLiveModelStatus::LiveModelNotEvaluated, $run->live_model_status);
        $this->assertNull($run->dimension_summary['single_ai_score'] ?? null);

        $caseRun = $run->caseRuns->first();
        $this->assertNotNull($caseRun);
        $this->assertSame(0, $caseRun->retrieval_metrics['privacy_overfetch_count']);
        $this->assertNull($caseRun->retrieval_metrics['composite_retrieval_score']);

        $serialized = strtolower((string) json_encode([
            $caseRun->mocked_output,
            $caseRun->retrieval_metrics,
        ]));
        $this->assertStringNotContainsString(strtolower(IntelligenceEvaluationCanaries::DENTAL_BRAND_B_EXPERIENCE), $serialized);
        $this->assertStringNotContainsString(strtolower(IntelligenceEvaluationCanaries::RAW_KEYWORD), $serialized);

        $historyAssert = $caseRun->assertionResults->firstWhere('assertion_type', 'retrieval_layer_empty');
        $this->assertNotNull($historyAssert);
        $this->assertSame(IntelligenceEvaluationAssertionStatus::Pass, $historyAssert->status);
    }

    public function test_idempotent_double_run(): void
    {
        $runner = app(IntelligenceEvaluationRunner::class);
        $a = $runner->runSuite(
            'BRAND_ISOLATION',
            IntelligenceEvaluationRunMode::DeterministicOnly,
            [IntelligenceEvaluationCaseCatalog::PRIVACY_CROSS_BRAND_CANARY],
            ['idempotency_key' => 'eval-idem-1'],
        );
        $b = $runner->runSuite(
            'BRAND_ISOLATION',
            IntelligenceEvaluationRunMode::DeterministicOnly,
            [IntelligenceEvaluationCaseCatalog::PRIVACY_CROSS_BRAND_CANARY],
            ['idempotency_key' => 'eval-idem-1'],
        );
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, IntelligenceEvaluationRun::query()->where('idempotency_key', 'eval-idem-1')->count());
    }

    public function test_privacy_canary_hard_gate(): void
    {
        $run = app(IntelligenceEvaluationRunner::class)->runSuite(
            'PRIVACY_ATTACK',
            IntelligenceEvaluationRunMode::MockedAi,
            [IntelligenceEvaluationCaseCatalog::PRIVACY_CROSS_BRAND_CANARY],
        );
        $this->assertSame(IntelligenceEvaluationGateStatus::Pass, $run->safety_gate_status);
        $canary = $run->caseRuns->first()->assertionResults
            ->firstWhere('assertion_type', 'no_forbidden_canary');
        $this->assertSame(IntelligenceEvaluationAssertionStatus::Pass, $canary->status);
        $this->assertTrue($canary->is_hard_safety);
    }

    public function test_abstention_missing_evidence(): void
    {
        $run = app(IntelligenceEvaluationRunner::class)->runSuite(
            'ABSTENTION',
            IntelligenceEvaluationRunMode::MockedAi,
            [IntelligenceEvaluationCaseCatalog::ABSTAIN_MISSING_EVIDENCE],
        );
        $caseRun = $run->caseRuns->first();
        $this->assertTrue($caseRun->mocked_output['abstained'] ?? false);
        $assert = $caseRun->assertionResults->firstWhere('assertion_type', 'expected_abstention');
        $this->assertSame(IntelligenceEvaluationAssertionStatus::Pass, $assert->status);
    }

    public function test_abstention_complete_context_answers(): void
    {
        $run = app(IntelligenceEvaluationRunner::class)->runSuite(
            'ABSTENTION',
            IntelligenceEvaluationRunMode::MockedAi,
            [IntelligenceEvaluationCaseCatalog::ABSTAIN_COMPLETE],
        );
        $caseRun = $run->caseRuns->first();
        $this->assertFalse($caseRun->mocked_output['abstained'] ?? true);
        $assert = $caseRun->assertionResults->firstWhere('assertion_type', 'expected_no_abstention');
        $this->assertSame(IntelligenceEvaluationAssertionStatus::Pass, $assert->status);
    }

    public function test_provider_semantic_forbidden_claims(): void
    {
        $run = app(IntelligenceEvaluationRunner::class)->runSuite(
            'PROVIDER_SEMANTICS',
            IntelligenceEvaluationRunMode::MockedAi,
            [
                IntelligenceEvaluationCaseCatalog::PROVIDER_GSC_RANK,
                IntelligenceEvaluationCaseCatalog::PROVIDER_GADS_LEAD,
                IntelligenceEvaluationCaseCatalog::PROVIDER_DFS_ETV,
            ],
        );
        $this->assertSame(IntelligenceEvaluationGateStatus::Pass, $run->safety_gate_status);
        foreach ($run->caseRuns as $caseRun) {
            $forbid = $caseRun->assertionResults->firstWhere('assertion_type', 'output_forbids_claim_pattern');
            $this->assertSame(IntelligenceEvaluationAssertionStatus::Pass, $forbid->status);
        }
    }

    public function test_counterfactual_genericity_pair_differs(): void
    {
        $run = app(IntelligenceEvaluationRunner::class)->runSuite(
            'SPECIFICITY',
            IntelligenceEvaluationRunMode::MockedAi,
            [
                IntelligenceEvaluationCaseCatalog::GENERICITY_PAIR_A,
                IntelligenceEvaluationCaseCatalog::GENERICITY_PAIR_B,
            ],
        );
        $a = $run->caseRuns->firstWhere('case_key', IntelligenceEvaluationCaseCatalog::GENERICITY_PAIR_A);
        $b = $run->caseRuns->firstWhere('case_key', IntelligenceEvaluationCaseCatalog::GENERICITY_PAIR_B);
        $typesA = array_column($a->mocked_output['conclusions'] ?? [], 'type');
        $typesB = array_column($b->mocked_output['conclusions'] ?? [], 'type');
        $this->assertNotSame($typesA, $typesB);
        $this->assertContains('search_demand_mismatch', $typesA);
        $this->assertContains('indexing_content_gap', $typesB);
    }

    public function test_current_truth_authority(): void
    {
        $run = app(IntelligenceEvaluationRunner::class)->runSuite(
            'CURRENT_TRUTH',
            IntelligenceEvaluationRunMode::MockedAi,
            [IntelligenceEvaluationCaseCatalog::CURRENT_TRUTH_MARKET],
        );
        $assert = $run->caseRuns->first()->assertionResults
            ->firstWhere('assertion_type', 'current_truth_authority');
        $this->assertSame(IntelligenceEvaluationAssertionStatus::Pass, $assert->status);
    }

    public function test_human_review_cannot_override_privacy(): void
    {
        $run = app(IntelligenceEvaluationRunner::class)->runSuite(
            'DENTAL_SPECIALIST',
            IntelligenceEvaluationRunMode::MockedAi,
            [IntelligenceEvaluationCaseCatalog::NEW_DENTAL_BRAND],
        );
        $user = User::factory()->create();
        $outcomes = [];
        foreach (IntelligenceEvaluationHumanRubric::dimensions() as $dim) {
            $outcomes[$dim] = IntelligenceEvaluationHumanRubricOutcome::Pass->value;
        }
        $review = app(IntelligenceEvaluationHumanReviewService::class)->recordReview(
            $run->caseRuns->first(),
            (int) $user->id,
            $outcomes,
            'Looks useful',
            attemptedPrivacyOverride: true,
        );
        $this->assertFalse($review->privacy_override_accepted);
        $this->assertTrue($review->attempted_privacy_override);
        // Second review must not overwrite
        $review2 = app(IntelligenceEvaluationHumanReviewService::class)->recordReview(
            $run->caseRuns->first(),
            (int) $user->id,
            $outcomes,
            'Second opinion',
        );
        $this->assertNotSame($review->id, $review2->id);
        $this->assertSame(2, $run->caseRuns->first()->humanReviews()->count());
    }

    public function test_advisory_judge_cannot_override_safety(): void
    {
        $run = app(IntelligenceEvaluationRunner::class)->runSuite(
            'DENTAL_SPECIALIST',
            IntelligenceEvaluationRunMode::MockedAi,
            [IntelligenceEvaluationCaseCatalog::NEW_DENTAL_BRAND],
            ['enable_advisory_judge' => true],
        );
        $judge = $run->caseRuns->first()->judgeResults()->first();
        $this->assertNotNull($judge);
        $this->assertTrue($judge->is_advisory);
        $this->assertFalse($judge->safety_override_accepted);
        $this->assertNull($judge->structured_findings['chain_of_thought']);
        $this->assertNull($judge->structured_findings['numeric_score']);
    }

    public function test_baseline_and_regression_no_auto_action(): void
    {
        $runner = app(IntelligenceEvaluationRunner::class);
        $baseRun = $runner->runSuite(
            'RETRIEVAL_CORE',
            IntelligenceEvaluationRunMode::DeterministicOnly,
            [IntelligenceEvaluationCaseCatalog::NEW_DENTAL_BRAND],
            ['idempotency_key' => 'eval-base-1'],
        );
        $baseline = app(IntelligenceEvaluationBaselineService::class)->register(
            'production_retrieval_v1',
            'Explicit production retrieval baseline',
            $baseRun,
        );
        $this->assertTrue($baseline->is_explicit);

        $newRun = $runner->runSuite(
            'RETRIEVAL_CORE',
            IntelligenceEvaluationRunMode::DeterministicOnly,
            [IntelligenceEvaluationCaseCatalog::NEW_DENTAL_BRAND],
            ['idempotency_key' => 'eval-base-2', 'baseline_key' => 'production_retrieval_v1'],
        );
        $comparison = app(IntelligenceEvaluationRegressionComparer::class)->compare($baseline, $newRun);
        $this->assertNull($comparison['single_ai_score']);
        $this->assertNull($comparison['automatic_action']);
    }

    public function test_live_controlled_blocked_in_ci_path(): void
    {
        $this->expectException(\RuntimeException::class);
        app(IntelligenceEvaluationRunner::class)->runSuite(
            'DENTAL_SPECIALIST',
            IntelligenceEvaluationRunMode::LiveControlled,
            [IntelligenceEvaluationCaseCatalog::NEW_DENTAL_BRAND],
        );
    }

    public function test_no_training_or_vector_in_evaluation_services(): void
    {
        app(IntelligenceEvaluationBoundaryGuard::class)->assertNoTrainingExportApi();
        app(IntelligenceEvaluationBoundaryGuard::class)->assertNoAutoTuningSideEffects();
        $this->assertFalse(IntelligenceRetrievalPolicy::snapshot()['embeddings']);
        $this->assertFalse(IntelligenceEvaluationPolicy::snapshot()['training_export']);
    }

    public function test_mature_brand_can_retrieve_same_brand_history(): void
    {
        $run = app(IntelligenceEvaluationRunner::class)->runSuite(
            'DENTAL_SPECIALIST',
            IntelligenceEvaluationRunMode::MockedAi,
            [IntelligenceEvaluationCaseCatalog::MATURE_DENTAL_BRAND],
        );
        $caseRun = $run->caseRuns->first();
        $this->assertSame(IntelligenceEvaluationGateStatus::Pass, $caseRun->safety_gate_status);
        $nonempty = $caseRun->assertionResults->firstWhere('assertion_type', 'retrieval_layer_nonempty');
        $this->assertSame(IntelligenceEvaluationAssertionStatus::Pass, $nonempty->status);
    }

    public function test_hallucination_history_trap(): void
    {
        $run = app(IntelligenceEvaluationRunner::class)->runSuite(
            'HALLUCINATION',
            IntelligenceEvaluationRunMode::MockedAi,
            [IntelligenceEvaluationCaseCatalog::HALLUCINATION_HISTORY],
        );
        $assert = $run->caseRuns->first()->assertionResults
            ->firstWhere('assertion_type', 'no_invented_brand_history');
        $this->assertSame(IntelligenceEvaluationAssertionStatus::Pass, $assert->status);
    }

    public function test_pins_retrieval_and_eval_policy_versions(): void
    {
        $run = app(IntelligenceEvaluationRunner::class)->runSuite(
            'RETRIEVAL_CORE',
            IntelligenceEvaluationRunMode::DeterministicOnly,
            [IntelligenceEvaluationCaseCatalog::NEW_DENTAL_BRAND],
        );
        $this->assertSame(IntelligenceEvaluationPolicy::VERSION, $run->evaluation_policy_version);
        $this->assertSame(IntelligenceRetrievalPolicy::VERSION, $run->retrieval_policy_version);
        $this->assertSame(IntelligenceEvaluationPolicy::ASSERTION_REGISTRY_VERSION, $run->assertion_registry_version);
    }
}
