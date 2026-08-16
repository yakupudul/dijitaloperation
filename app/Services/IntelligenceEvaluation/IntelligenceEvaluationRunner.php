<?php

namespace App\Services\IntelligenceEvaluation;

use App\Enums\IntelligenceEvaluationAssertionStatus;
use App\Enums\IntelligenceEvaluationGateStatus;
use App\Enums\IntelligenceEvaluationLiveModelStatus;
use App\Enums\IntelligenceEvaluationRunMode;
use App\Enums\IntelligenceEvaluationRunStatus;
use App\Models\IntelligenceEvaluationAssertionResult;
use App\Models\IntelligenceEvaluationCaseRun;
use App\Models\IntelligenceEvaluationRun;
use App\Services\IntelligenceRetrieval\IntelligenceRetrievalService;
use App\Support\IntelligenceEvaluation\Dto\IntelligenceEvaluationCaseDefinition;
use App\Support\IntelligenceEvaluation\IntelligenceEvaluationCaseCatalog;
use App\Support\IntelligenceEvaluation\IntelligenceEvaluationPolicy;
use App\Support\IntelligenceEvaluation\IntelligenceEvaluationSuiteCatalog;
use App\Support\IntelligenceRetrieval\IntelligenceRetrievalPolicy;
use Illuminate\Support\Facades\DB;

/**
 * Canonical Intelligence Evaluation Runner (Prompt 55).
 *
 * Observes and measures. Never auto-tunes. Never calls business providers.
 * CI modes: DETERMINISTIC_ONLY / MOCKED_AI only.
 */
final class IntelligenceEvaluationRunner
{
    public function __construct(
        private readonly IntelligenceEvaluationSyntheticFixtureBuilder $fixtureBuilder,
        private readonly IntelligenceEvaluationContractFactory $contractFactory,
        private readonly IntelligenceRetrievalService $retrievalService,
        private readonly IntelligenceEvaluationRetrievalMetricsCalculator $metricsCalculator,
        private readonly IntelligenceEvaluationAssertionEngine $assertionEngine,
        private readonly IntelligenceEvaluationMockedOutputFactory $mockedOutputFactory,
        private readonly IntelligenceEvaluationBoundaryGuard $boundaryGuard,
        private readonly IntelligenceEvaluationAdvisoryJudge $advisoryJudge,
    ) {}

    /**
     * @param  list<string>|null  $caseKeys
     * @param  array{
     *     idempotency_key?: string|null,
     *     requested_by?: int|null,
     *     agent_definition_signature?: string|null,
     *     skill_definition_signature?: string|null,
     *     ai_route_version?: string|null,
     *     baseline_key?: string|null,
     *     enable_advisory_judge?: bool,
     *     max_cases?: int
     * }  $options
     */
    public function runSuite(
        string $suiteKey,
        IntelligenceEvaluationRunMode $mode = IntelligenceEvaluationRunMode::MockedAi,
        ?array $caseKeys = null,
        array $options = [],
    ): IntelligenceEvaluationRun {
        if ($mode === IntelligenceEvaluationRunMode::LiveControlled) {
            throw new \RuntimeException(
                'LIVE_CONTROLLED evaluation requires explicit privileged invocation via runLiveControlled().'
            );
        }

        if (! $mode->isCiSafe() && $mode !== IntelligenceEvaluationRunMode::HumanReview) {
            throw new \InvalidArgumentException('Unsupported evaluation run mode for this entry point.');
        }

        $idempotency = $options['idempotency_key'] ?? null;
        if (is_string($idempotency) && $idempotency !== '') {
            $existing = IntelligenceEvaluationRun::query()->where('idempotency_key', $idempotency)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        $suite = IntelligenceEvaluationSuiteCatalog::all()[$suiteKey] ?? null;
        if ($suite === null) {
            throw new \InvalidArgumentException('Unknown evaluation suite: '.$suiteKey);
        }

        $cases = $caseKeys !== null
            ? array_values(array_filter(
                array_map(
                    static fn (string $k) => IntelligenceEvaluationCaseCatalog::find($k),
                    $caseKeys
                )
            ))
            : IntelligenceEvaluationCaseCatalog::forSuite($suiteKey);

        $maxCases = (int) ($options['max_cases'] ?? 100);
        $cases = array_slice($cases, 0, max(1, $maxCases));

        return DB::transaction(function () use ($suite, $suiteKey, $mode, $cases, $options, $idempotency) {
            $run = IntelligenceEvaluationRun::query()->create([
                'suite_key' => $suiteKey,
                'suite_version' => $suite['version'],
                'dataset_key' => IntelligenceEvaluationSuiteCatalog::DATASET_KEY,
                'dataset_version' => IntelligenceEvaluationSuiteCatalog::DATASET_VERSION,
                'evaluation_policy_version' => IntelligenceEvaluationPolicy::VERSION,
                'assertion_registry_version' => IntelligenceEvaluationPolicy::ASSERTION_REGISTRY_VERSION,
                'human_rubric_version' => IntelligenceEvaluationPolicy::HUMAN_RUBRIC_VERSION,
                'run_mode' => $mode,
                'status' => IntelligenceEvaluationRunStatus::Running,
                'safety_gate_status' => IntelligenceEvaluationGateStatus::NotEvaluated,
                'quality_gate_status' => IntelligenceEvaluationGateStatus::NotEvaluated,
                'live_model_status' => IntelligenceEvaluationLiveModelStatus::LiveModelNotEvaluated,
                'agent_definition_signature' => $options['agent_definition_signature']
                    ?? IntelligenceEvaluationContractFactory::EVAL_AGENT,
                'skill_definition_signature' => $options['skill_definition_signature']
                    ?? IntelligenceEvaluationContractFactory::EVAL_SKILL,
                'ai_route_version' => $options['ai_route_version'] ?? null,
                'retrieval_policy_version' => IntelligenceRetrievalPolicy::VERSION,
                'output_schema_version' => 'structured_agent_output_v1',
                'baseline_key' => $options['baseline_key'] ?? null,
                'idempotency_key' => $idempotency,
                'requested_by' => $options['requested_by'] ?? null,
                'runtime_pins' => [
                    'evaluation_policy' => IntelligenceEvaluationPolicy::snapshot(),
                    'retrieval_policy' => IntelligenceRetrievalPolicy::snapshot(),
                    'auto_tuning' => false,
                    'fine_tuning' => false,
                    'single_ai_score' => null,
                ],
                'limits' => [
                    'max_cases' => count($cases),
                    'ci_live_paid_ai' => false,
                    'business_provider_calls' => 0,
                ],
                'started_at' => now(),
            ]);

            $safetyFail = false;
            $needsReview = false;
            $anyFail = false;
            $dimensionCounts = [];

            foreach ($cases as $case) {
                $caseResult = $this->runCase($run, $case, $mode, (bool) ($options['enable_advisory_judge'] ?? false));
                if ($caseResult->safety_gate_status === IntelligenceEvaluationGateStatus::Fail) {
                    $safetyFail = true;
                }
                if ($caseResult->status === IntelligenceEvaluationRunStatus::NeedsReview) {
                    $needsReview = true;
                }
                if ($caseResult->status === IntelligenceEvaluationRunStatus::Failed
                    || $caseResult->status === IntelligenceEvaluationRunStatus::SafetyFail) {
                    $anyFail = true;
                }
                foreach ($caseResult->assertionResults as $assertion) {
                    $dim = $assertion->dimension->value;
                    $dimensionCounts[$dim] ??= ['pass' => 0, 'fail' => 0, 'needs_review' => 0, 'skipped' => 0];
                    $dimensionCounts[$dim][$assertion->status->value] =
                        ($dimensionCounts[$dim][$assertion->status->value] ?? 0) + 1;
                }
            }

            $run->forceFill([
                'status' => $safetyFail
                    ? IntelligenceEvaluationRunStatus::SafetyFail
                    : ($needsReview
                        ? IntelligenceEvaluationRunStatus::NeedsReview
                        : ($anyFail
                            ? IntelligenceEvaluationRunStatus::Failed
                            : IntelligenceEvaluationRunStatus::Completed)),
                'safety_gate_status' => $safetyFail
                    ? IntelligenceEvaluationGateStatus::Fail
                    : IntelligenceEvaluationGateStatus::Pass,
                'quality_gate_status' => $safetyFail
                    ? IntelligenceEvaluationGateStatus::Fail
                    : ($needsReview
                        ? IntelligenceEvaluationGateStatus::NeedsReview
                        : ($anyFail
                            ? IntelligenceEvaluationGateStatus::Fail
                            : IntelligenceEvaluationGateStatus::Pass)),
                'live_model_status' => IntelligenceEvaluationLiveModelStatus::LiveModelNotEvaluated,
                'dimension_summary' => [
                    'counts' => $dimensionCounts,
                    'single_ai_score' => null,
                    'weighted_composite' => null,
                ],
                'finished_at' => now(),
            ])->save();

            $this->boundaryGuard->assertNoAutoTuningSideEffects();

            return $run->fresh(['caseRuns.assertionResults']);
        });
    }

    /**
     * Privileged live-controlled entry — still zero business provider calls.
     * CI must not invoke this.
     */
    public function runLiveControlled(string $suiteKey, array $options = []): never
    {
        throw new \RuntimeException(
            'Live controlled AI evaluation is privileged and must be enabled by explicit operator tooling; CI forbids paid live inference.'
        );
    }

    private function runCase(
        IntelligenceEvaluationRun $run,
        IntelligenceEvaluationCaseDefinition $case,
        IntelligenceEvaluationRunMode $mode,
        bool $enableAdvisoryJudge,
    ): IntelligenceEvaluationCaseRun {
        $started = hrtime(true);
        $fixtures = $this->fixtureBuilder->build($case);
        $options = $this->contractFactory->optionsFor($case, $fixtures);

        $pack = $this->retrievalService->retrieve(
            agentDefinitionSignature: $options['agent'],
            skillDefinitionSignature: $options['skill'],
            customerId: (int) $fixtures['customer']->id,
            brandId: (int) $fixtures['brand']->id,
            evidencePack: $fixtures['evidence_pack'],
            digitalAsset: $fixtures['digital_asset'],
            options: [
                'skill_memory_contract_override' => $options['skill_memory_contract_override'],
                'agent_permission_override' => $options['agent_permission_override'],
                'retrieval_contract_override' => $options['retrieval_contract_override'],
                'explicit_goal_ids' => $options['explicit_goal_ids'],
                'market_code' => $fixtures['fixture_map']['market'] ?? null,
                'channel' => $fixtures['fixture_map']['channel'] ?? null,
            ],
        );

        $retrievalMs = (int) ((hrtime(true) - $started) / 1_000_000);
        $metrics = $this->metricsCalculator->calculate($case, $pack, $fixtures);

        $structuredOutput = null;
        if ($mode !== IntelligenceEvaluationRunMode::DeterministicOnly) {
            $structuredOutput = $this->mockedOutputFactory->build($case, $pack);
        }

        $assertions = $this->assertionEngine->evaluate(
            case: $case,
            pack: $pack,
            metrics: $metrics,
            fixtures: $fixtures,
            structuredOutput: $structuredOutput,
            providerCallsMade: false,
            domainWritesMade: false,
            autoTuningOccurred: false,
            trainingExportOccurred: false,
        );

        $safetyFail = false;
        $needsReview = false;
        $fail = false;
        foreach ($assertions as $row) {
            if ($row['status'] === IntelligenceEvaluationAssertionStatus::Fail && $row['is_hard_safety']) {
                $safetyFail = true;
            }
            if ($row['status'] === IntelligenceEvaluationAssertionStatus::Fail) {
                $fail = true;
            }
            if ($row['status'] === IntelligenceEvaluationAssertionStatus::NeedsReview) {
                $needsReview = true;
            }
        }

        $caseRun = IntelligenceEvaluationCaseRun::query()->create([
            'evaluation_run_id' => $run->id,
            'case_key' => $case->caseKey,
            'case_version' => $case->caseVersion,
            'dataset_version' => $case->datasetVersion,
            'status' => $safetyFail
                ? IntelligenceEvaluationRunStatus::SafetyFail
                : ($needsReview
                    ? IntelligenceEvaluationRunStatus::NeedsReview
                    : ($fail
                        ? IntelligenceEvaluationRunStatus::Failed
                        : IntelligenceEvaluationRunStatus::Completed)),
            'safety_gate_status' => $safetyFail
                ? IntelligenceEvaluationGateStatus::Fail
                : IntelligenceEvaluationGateStatus::Pass,
            'ablation_variant' => $case->ablationVariant,
            'eval_customer_id' => $fixtures['customer']->id,
            'eval_brand_id' => $fixtures['brand']->id,
            'retrieval_fingerprint' => $pack->retrievalFingerprint,
            'context_pack_fingerprint' => $pack->memoryContextPack->contextFingerprint,
            'retrieval_metrics' => $metrics->toArray(),
            'dimension_results' => [
                'safety' => $safetyFail ? 'fail' : 'pass',
                'single_ai_score' => null,
            ],
            'runtime_pins' => [
                'case' => $case->toArray(),
                'agent' => $options['agent'],
                'skill' => $options['skill'],
                'retrieval_policy' => IntelligenceRetrievalPolicy::VERSION,
                'evaluation_policy' => IntelligenceEvaluationPolicy::VERSION,
            ],
            'mocked_output' => $structuredOutput,
            'retrieval_duration_ms' => $retrievalMs,
            'provider_latency_ms' => 0,
            'input_tokens' => null,
            'output_tokens' => null,
            'attempt_count' => 1,
            'failure_summary' => $safetyFail ? 'Hard safety assertion failed' : null,
        ]);

        foreach ($assertions as $row) {
            IntelligenceEvaluationAssertionResult::query()->create([
                'evaluation_case_run_id' => $caseRun->id,
                'assertion_type' => $row['assertion_type'],
                'dimension' => $row['dimension'],
                'status' => $row['status'],
                'is_hard_safety' => $row['is_hard_safety'],
                'source_phase' => $row['source_phase'],
                'authority' => $row['authority'],
                'expected' => $row['expected'],
                'actual' => $row['actual'],
                'reason_code' => $row['reason_code'],
                'diagnostic' => $row['diagnostic'],
            ]);
        }

        if ($enableAdvisoryJudge && $structuredOutput !== null) {
            $this->advisoryJudge->evaluateAdvisory($caseRun, $case, $structuredOutput, $safetyFail);
        }

        return $caseRun->fresh(['assertionResults']);
    }
}
