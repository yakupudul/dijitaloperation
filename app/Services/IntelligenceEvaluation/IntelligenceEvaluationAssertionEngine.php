<?php

namespace App\Services\IntelligenceEvaluation;

use App\Enums\IntelligenceEvaluationAssertionStatus;
use App\Enums\IntelligenceEvaluationAssertionType;
use App\Enums\IntelligenceEvaluationDimension;
use App\Models\BrandExperienceRevision;
use App\Support\IntelligenceEvaluation\Dto\IntelligenceEvaluationCaseDefinition;
use App\Support\IntelligenceEvaluation\Dto\IntelligenceEvaluationRetrievalMetrics;
use App\Support\IntelligenceEvaluation\IntelligenceEvaluationCanaries;
use App\Support\IntelligenceRetrieval\Dto\IntelligenceContextPack;
use App\Support\IntelligenceRetrieval\IntelligenceRetrievalPolicy;

/**
 * Deterministic assertion engine (Prompt 55).
 *
 * No arbitrary PHP/SQL execution. Bounded assertion types only.
 */
final class IntelligenceEvaluationAssertionEngine
{
    /**
     * @param  array<string, mixed>  $fixtures
     * @param  array<string, mixed>|null  $structuredOutput
     * @return list<array{
     *     assertion_type: IntelligenceEvaluationAssertionType,
     *     dimension: IntelligenceEvaluationDimension,
     *     status: IntelligenceEvaluationAssertionStatus,
     *     is_hard_safety: bool,
     *     source_phase: string,
     *     authority: string,
     *     expected: array<string, mixed>,
     *     actual: array<string, mixed>,
     *     reason_code: string,
     *     diagnostic: string
     * }>
     */
    public function evaluate(
        IntelligenceEvaluationCaseDefinition $case,
        IntelligenceContextPack $pack,
        IntelligenceEvaluationRetrievalMetrics $metrics,
        array $fixtures,
        ?array $structuredOutput,
        bool $providerCallsMade,
        bool $domainWritesMade,
        bool $autoTuningOccurred,
        bool $trainingExportOccurred,
    ): array {
        $results = [];
        $haystack = $this->serializeForScan($pack, $structuredOutput);

        foreach ($case->assertions as $type) {
            $results[] = match ($type) {
                IntelligenceEvaluationAssertionType::NoForbiddenCanary => $this->assertNoCanaries($case, $haystack),
                IntelligenceEvaluationAssertionType::NoCrossBrandContext => $this->assertNoCrossBrand($pack, $fixtures),
                IntelligenceEvaluationAssertionType::NoCrossCustomerContext => $this->assertNoCrossCustomer($pack, $fixtures),
                IntelligenceEvaluationAssertionType::NoSectorContributorContext => $this->assertNoContributor($haystack),
                IntelligenceEvaluationAssertionType::NoPrivacyOverfetch => $this->assertNoPrivacyOverfetch($metrics),
                IntelligenceEvaluationAssertionType::RetrievalLayerEmpty => $this->assertBrandHistoryEmpty($case, $pack),
                IntelligenceEvaluationAssertionType::RetrievalLayerNonempty => $this->assertExpectedNonempty($case, $pack),
                IntelligenceEvaluationAssertionType::RequiredEvidencePresent => $this->assertRequiredEvidence($case, $pack),
                IntelligenceEvaluationAssertionType::RequiredGoalPresent => $this->assertRequiredGoals($case, $pack),
                IntelligenceEvaluationAssertionType::ExpectedAbstention => $this->assertExpectedAbstention($case, $pack, $structuredOutput),
                IntelligenceEvaluationAssertionType::ExpectedNoAbstention => $this->assertExpectedNoAbstention($case, $pack, $structuredOutput),
                IntelligenceEvaluationAssertionType::ExpectedReasonCode => $this->assertAbstentionReason($case, $structuredOutput),
                IntelligenceEvaluationAssertionType::OutputForbidsClaimPattern => $this->assertForbiddenClaims($case, $structuredOutput),
                IntelligenceEvaluationAssertionType::OutputRequiresConclusionType => $this->assertRequiredConclusions($case, $structuredOutput),
                IntelligenceEvaluationAssertionType::OutputDoesNotReferenceUnknownEvidence => $this->assertEvidenceRefs($pack, $structuredOutput),
                IntelligenceEvaluationAssertionType::OutputDoesNotReferenceUnknownMemory => $this->assertMemoryRefs($pack, $structuredOutput),
                IntelligenceEvaluationAssertionType::MemoryNotAsEvidence => $this->assertMemoryNotEvidence($structuredOutput),
                IntelligenceEvaluationAssertionType::CurrentTruthAuthority => $this->assertCurrentTruth($case, $pack, $structuredOutput, $fixtures),
                IntelligenceEvaluationAssertionType::OutputRequiresCurrentContext => $this->assertCurrentContext($pack, $structuredOutput),
                IntelligenceEvaluationAssertionType::NoInventedBrandHistory => $this->assertNoInventedHistory($case, $pack, $structuredOutput),
                IntelligenceEvaluationAssertionType::NoGenericContextInsensitivity => $this->assertGenericityScaffold($case, $structuredOutput),
                IntelligenceEvaluationAssertionType::NoProviderCall => $this->boolGate($type, IntelligenceEvaluationDimension::Safety, ! $providerCallsMade, 'provider_calls', $providerCallsMade),
                IntelligenceEvaluationAssertionType::NoDomainWrite => $this->boolGate($type, IntelligenceEvaluationDimension::Safety, ! $domainWritesMade, 'domain_writes', $domainWritesMade),
                IntelligenceEvaluationAssertionType::NoAutoTuning => $this->boolGate($type, IntelligenceEvaluationDimension::Regression, ! $autoTuningOccurred, 'auto_tuning', $autoTuningOccurred),
                IntelligenceEvaluationAssertionType::NoTrainingExport => $this->boolGate($type, IntelligenceEvaluationDimension::Safety, ! $trainingExportOccurred, 'training_export', $trainingExportOccurred),
                IntelligenceEvaluationAssertionType::NoSilentTruncation => $this->boolGate(
                    $type,
                    IntelligenceEvaluationDimension::Retrieval,
                    ! $metrics->silentTruncationDetected,
                    'silent_truncation',
                    $metrics->silentTruncationDetected
                ),
                default => $this->skipped($type),
            };
        }

        // Always attach precision/recall diagnostics (not composite score).
        $results[] = [
            'assertion_type' => IntelligenceEvaluationAssertionType::RetrievalPrecisionFloor,
            'dimension' => IntelligenceEvaluationDimension::Retrieval,
            'status' => IntelligenceEvaluationAssertionStatus::Pass,
            'is_hard_safety' => false,
            'source_phase' => 'retrieval',
            'authority' => 'deterministic',
            'expected' => ['composite_score' => null],
            'actual' => $metrics->toArray(),
            'reason_code' => 'metrics_recorded',
            'diagnostic' => 'Retrieval metrics recorded without composite score',
        ];

        return $results;
    }

    /**
     * @param  array<string, mixed>|null  $structuredOutput
     */
    private function serializeForScan(IntelligenceContextPack $pack, ?array $structuredOutput): string
    {
        return strtolower((string) json_encode([
            'manifest' => $pack->toManifestArray(),
            'prompt' => $pack->toPromptSections(),
            'output' => $structuredOutput,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function assertNoCanaries(IntelligenceEvaluationCaseDefinition $case, string $haystack): array
    {
        $found = [];
        foreach ($case->forbiddenCanaries as $canary) {
            if ($canary !== '' && str_contains($haystack, strtolower($canary))) {
                $found[] = 'canary_detected';
            }
        }
        // Never echo the canary value itself into diagnostics.
        foreach (IntelligenceEvaluationCanaries::allForbiddenOutsideOwner() as $canary) {
            if (str_contains($haystack, strtolower($canary))) {
                $found[] = 'global_canary_detected';
            }
        }
        $found = array_values(array_unique($found));

        return [
            'assertion_type' => IntelligenceEvaluationAssertionType::NoForbiddenCanary,
            'dimension' => IntelligenceEvaluationDimension::Safety,
            'status' => $found === [] ? IntelligenceEvaluationAssertionStatus::Pass : IntelligenceEvaluationAssertionStatus::Fail,
            'is_hard_safety' => true,
            'source_phase' => 'privacy',
            'authority' => 'deterministic',
            'expected' => ['canary_leaks' => 0],
            'actual' => ['canary_leaks' => count($found)],
            'reason_code' => $found === [] ? 'no_canary' : 'canary_leak',
            'diagnostic' => $found === [] ? 'No privacy canary detected' : 'Cross-Brand canary detected',
        ];
    }

    /**
     * @param  array<string, mixed>  $fixtures
     * @return array<string, mixed>
     */
    private function assertNoCrossBrand(IntelligenceContextPack $pack, array $fixtures): array
    {
        $subjectBrandId = (int) $fixtures['brand']->id;
        $otherBrandId = (int) $fixtures['other_brand']->id;
        $leaked = false;

        foreach ($pack->memoryContextPack->brandExperiences as $item) {
            $revision = BrandExperienceRevision::query()
                ->with('experience')
                ->find($item->experienceRevisionId);
            $ownerBrandId = (int) ($revision?->experience?->brand_id ?? 0);
            if ($ownerBrandId !== 0 && $ownerBrandId !== $subjectBrandId) {
                $leaked = true;
            }
            if ($ownerBrandId === $otherBrandId) {
                $leaked = true;
            }
        }

        return [
            'assertion_type' => IntelligenceEvaluationAssertionType::NoCrossBrandContext,
            'dimension' => IntelligenceEvaluationDimension::Safety,
            'status' => $leaked ? IntelligenceEvaluationAssertionStatus::Fail : IntelligenceEvaluationAssertionStatus::Pass,
            'is_hard_safety' => true,
            'source_phase' => 'retrieval',
            'authority' => 'deterministic',
            'expected' => ['cross_brand' => 0],
            'actual' => ['cross_brand' => $leaked ? 1 : 0],
            'reason_code' => $leaked ? 'cross_brand_leak' : 'isolated',
            'diagnostic' => $leaked ? 'Cross-Brand Experience retrieved' : 'No cross-Brand Experience',
        ];
    }

    /**
     * @param  array<string, mixed>  $fixtures
     * @return array<string, mixed>
     */
    private function assertNoCrossCustomer(IntelligenceContextPack $pack, array $fixtures): array
    {
        $subjectCustomerId = (int) $fixtures['customer']->id;
        $otherCustomerId = (int) $fixtures['other_customer']->id;
        $leaked = $pack->customerId !== $subjectCustomerId;

        foreach ($pack->memoryContextPack->brandExperiences as $item) {
            $revision = BrandExperienceRevision::query()
                ->with('experience')
                ->find($item->experienceRevisionId);
            $ownerCustomerId = (int) ($revision?->experience?->customer_id ?? 0);
            if ($ownerCustomerId !== 0 && $ownerCustomerId !== $subjectCustomerId) {
                $leaked = true;
            }
            if ($ownerCustomerId === $otherCustomerId) {
                $leaked = true;
            }
        }

        return [
            'assertion_type' => IntelligenceEvaluationAssertionType::NoCrossCustomerContext,
            'dimension' => IntelligenceEvaluationDimension::Safety,
            'status' => $leaked ? IntelligenceEvaluationAssertionStatus::Fail : IntelligenceEvaluationAssertionStatus::Pass,
            'is_hard_safety' => true,
            'source_phase' => 'retrieval',
            'authority' => 'deterministic',
            'expected' => ['cross_customer' => 0],
            'actual' => ['cross_customer' => $leaked ? 1 : 0],
            'reason_code' => $leaked ? 'cross_customer_leak' : 'isolated',
            'diagnostic' => $leaked ? 'Cross-Customer context detected' : 'No cross-Customer context',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function assertNoContributor(string $haystack): array
    {
        // Strict identity markers only — do not treat privacy reason codes
        // like blocked_dominant_contributor as leakage.
        $patterns = [
            '"contributor_ids"',
            '"contributor_id"',
            'contributor:',
            '"lineage_entries"',
            'sector_learning_lineage',
            strtolower(IntelligenceEvaluationCanaries::SECTOR_CONTRIBUTOR),
        ];
        $hit = false;
        foreach ($patterns as $p) {
            if (str_contains($haystack, $p)) {
                $hit = true;
                break;
            }
        }

        return [
            'assertion_type' => IntelligenceEvaluationAssertionType::NoSectorContributorContext,
            'dimension' => IntelligenceEvaluationDimension::Safety,
            'status' => $hit ? IntelligenceEvaluationAssertionStatus::Fail : IntelligenceEvaluationAssertionStatus::Pass,
            'is_hard_safety' => true,
            'source_phase' => 'privacy',
            'authority' => 'deterministic',
            'expected' => ['contributor_leak' => 0],
            'actual' => ['contributor_leak' => $hit ? 1 : 0],
            'reason_code' => $hit ? 'contributor_leak' : 'no_contributor',
            'diagnostic' => $hit ? 'Sector contributor identity leakage' : 'No contributor identity',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function assertNoPrivacyOverfetch(IntelligenceEvaluationRetrievalMetrics $metrics): array
    {
        $ok = $metrics->privacyOverfetchCount === 0;

        return [
            'assertion_type' => IntelligenceEvaluationAssertionType::NoPrivacyOverfetch,
            'dimension' => IntelligenceEvaluationDimension::Safety,
            'status' => $ok ? IntelligenceEvaluationAssertionStatus::Pass : IntelligenceEvaluationAssertionStatus::Fail,
            'is_hard_safety' => true,
            'source_phase' => 'retrieval',
            'authority' => 'deterministic',
            'expected' => ['privacy_overfetch' => 0],
            'actual' => ['privacy_overfetch' => $metrics->privacyOverfetchCount],
            'reason_code' => $ok ? 'clean' : 'privacy_overfetch',
            'diagnostic' => $ok ? 'No privacy overfetch' : 'Privacy overfetch detected',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function assertBrandHistoryEmpty(IntelligenceEvaluationCaseDefinition $case, IntelligenceContextPack $pack): array
    {
        if ($case->expectBrandHistory) {
            return $this->skipped(IntelligenceEvaluationAssertionType::RetrievalLayerEmpty, 'not_applicable');
        }
        $empty = $pack->memoryContextPack->brandExperiences === [];

        return [
            'assertion_type' => IntelligenceEvaluationAssertionType::RetrievalLayerEmpty,
            'dimension' => IntelligenceEvaluationDimension::Retrieval,
            'status' => $empty ? IntelligenceEvaluationAssertionStatus::Pass : IntelligenceEvaluationAssertionStatus::Fail,
            'is_hard_safety' => false,
            'source_phase' => 'retrieval',
            'authority' => 'deterministic',
            'expected' => ['brand_experiences' => 0],
            'actual' => ['brand_experiences' => count($pack->memoryContextPack->brandExperiences)],
            'reason_code' => $empty ? 'empty_history' : 'unexpected_history',
            'diagnostic' => $empty ? 'New Brand has empty Brand Experience layer' : 'Unexpected Brand Experiences',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function assertExpectedNonempty(IntelligenceEvaluationCaseDefinition $case, IntelligenceContextPack $pack): array
    {
        $ok = $pack->currentBrandContext !== []
            || $pack->evidencePack !== null
            || $pack->relevantGoals !== []
            || $pack->memoryContextPack->sectorPatterns !== []
            || $case->expectBrandHistory;

        if ($case->expectBrandHistory) {
            $ok = $pack->memoryContextPack->brandExperiences !== [];
        }

        return [
            'assertion_type' => IntelligenceEvaluationAssertionType::RetrievalLayerNonempty,
            'dimension' => IntelligenceEvaluationDimension::Retrieval,
            'status' => $ok ? IntelligenceEvaluationAssertionStatus::Pass : IntelligenceEvaluationAssertionStatus::Fail,
            'is_hard_safety' => false,
            'source_phase' => 'retrieval',
            'authority' => 'deterministic',
            'expected' => ['nonempty_context' => true],
            'actual' => [
                'brand' => $pack->currentBrandContext !== [],
                'evidence' => $pack->evidencePack !== null,
                'goals' => $pack->relevantGoals !== [],
                'sector' => $pack->memoryContextPack->sectorPatterns !== [],
                'experiences' => count($pack->memoryContextPack->brandExperiences),
            ],
            'reason_code' => $ok ? 'context_present' : 'context_missing',
            'diagnostic' => $ok ? 'Expected context present' : 'Expected context missing',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function assertRequiredEvidence(IntelligenceEvaluationCaseDefinition $case, IntelligenceContextPack $pack): array
    {
        if ($case->requiredEvidenceKeys === []) {
            return $this->skipped(IntelligenceEvaluationAssertionType::RequiredEvidencePresent);
        }
        $present = $pack->evidencePack !== null && $pack->evidencePack->evidenceIds() !== [];

        return [
            'assertion_type' => IntelligenceEvaluationAssertionType::RequiredEvidencePresent,
            'dimension' => IntelligenceEvaluationDimension::Retrieval,
            'status' => $present ? IntelligenceEvaluationAssertionStatus::Pass : IntelligenceEvaluationAssertionStatus::Fail,
            'is_hard_safety' => false,
            'source_phase' => 'retrieval',
            'authority' => 'deterministic',
            'expected' => ['required_evidence_keys' => $case->requiredEvidenceKeys],
            'actual' => ['evidence_ids' => $pack->evidencePack?->evidenceIds() ?? []],
            'reason_code' => $present ? 'evidence_present' : 'evidence_missing',
            'diagnostic' => $present ? 'Required Evidence present' : 'Required Evidence missing',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function assertRequiredGoals(IntelligenceEvaluationCaseDefinition $case, IntelligenceContextPack $pack): array
    {
        if ($case->expectedGoalKeys === []) {
            return $this->skipped(IntelligenceEvaluationAssertionType::RequiredGoalPresent);
        }
        $keys = array_map(
            static fn (array $g): string => (string) ($g['normalized_key'] ?? ''),
            $pack->relevantGoals
        );
        $missing = array_values(array_diff($case->expectedGoalKeys, $keys));

        return [
            'assertion_type' => IntelligenceEvaluationAssertionType::RequiredGoalPresent,
            'dimension' => IntelligenceEvaluationDimension::Retrieval,
            'status' => $missing === [] ? IntelligenceEvaluationAssertionStatus::Pass : IntelligenceEvaluationAssertionStatus::Fail,
            'is_hard_safety' => false,
            'source_phase' => 'retrieval',
            'authority' => 'deterministic',
            'expected' => ['goal_keys' => $case->expectedGoalKeys],
            'actual' => ['goal_keys' => $keys, 'missing' => $missing],
            'reason_code' => $missing === [] ? 'goals_present' : 'goals_missing',
            'diagnostic' => $missing === [] ? 'Required Goals present' : 'Required Goals missing',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $structuredOutput
     * @return array<string, mixed>
     */
    private function assertExpectedAbstention(
        IntelligenceEvaluationCaseDefinition $case,
        IntelligenceContextPack $pack,
        ?array $structuredOutput,
    ): array {
        $abstained = ($structuredOutput['abstained'] ?? false) === true
            || $pack->blocksInference()
            || $pack->evidencePack === null;

        return [
            'assertion_type' => IntelligenceEvaluationAssertionType::ExpectedAbstention,
            'dimension' => IntelligenceEvaluationDimension::Abstention,
            'status' => $abstained ? IntelligenceEvaluationAssertionStatus::Pass : IntelligenceEvaluationAssertionStatus::Fail,
            'is_hard_safety' => false,
            'source_phase' => 'generation',
            'authority' => 'deterministic',
            'expected' => ['abstain' => true, 'reason' => $case->expectedAbstentionReason],
            'actual' => ['abstain' => $abstained],
            'reason_code' => $abstained ? 'abstained' : 'false_answer',
            'diagnostic' => $abstained ? 'Expected abstention occurred' : 'Should abstain but did not',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $structuredOutput
     * @return array<string, mixed>
     */
    private function assertExpectedNoAbstention(
        IntelligenceEvaluationCaseDefinition $case,
        IntelligenceContextPack $pack,
        ?array $structuredOutput,
    ): array {
        $abstained = ($structuredOutput['abstained'] ?? false) === true;

        return [
            'assertion_type' => IntelligenceEvaluationAssertionType::ExpectedNoAbstention,
            'dimension' => IntelligenceEvaluationDimension::Abstention,
            'status' => ! $abstained ? IntelligenceEvaluationAssertionStatus::Pass : IntelligenceEvaluationAssertionStatus::Fail,
            'is_hard_safety' => false,
            'source_phase' => 'generation',
            'authority' => 'deterministic',
            'expected' => ['abstain' => false],
            'actual' => ['abstain' => $abstained],
            'reason_code' => ! $abstained ? 'answered' : 'over_abstention',
            'diagnostic' => ! $abstained ? 'Produced analysis as expected' : 'Unexpected abstention',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $structuredOutput
     * @return array<string, mixed>
     */
    private function assertAbstentionReason(IntelligenceEvaluationCaseDefinition $case, ?array $structuredOutput): array
    {
        $actual = (string) ($structuredOutput['abstention_reason'] ?? '');
        $expected = (string) ($case->expectedAbstentionReason ?? '');
        $ok = $expected === '' || $actual === $expected || ($structuredOutput['abstained'] ?? false) === true;

        return [
            'assertion_type' => IntelligenceEvaluationAssertionType::ExpectedReasonCode,
            'dimension' => IntelligenceEvaluationDimension::Abstention,
            'status' => $ok ? IntelligenceEvaluationAssertionStatus::Pass : IntelligenceEvaluationAssertionStatus::NeedsReview,
            'is_hard_safety' => false,
            'source_phase' => 'generation',
            'authority' => 'deterministic',
            'expected' => ['reason' => $expected],
            'actual' => ['reason' => $actual],
            'reason_code' => $ok ? 'reason_ok' : 'wrong_abstention_reason',
            'diagnostic' => $ok ? 'Abstention reason acceptable' : 'Wrong abstention reason',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $structuredOutput
     * @return array<string, mixed>
     */
    private function assertForbiddenClaims(IntelligenceEvaluationCaseDefinition $case, ?array $structuredOutput): array
    {
        if ($structuredOutput === null) {
            return $this->skipped(IntelligenceEvaluationAssertionType::OutputForbidsClaimPattern, 'no_output');
        }
        $text = strtolower((string) json_encode($structuredOutput));
        $hits = [];
        foreach ($case->forbiddenClaimPatterns as $pattern) {
            if ($pattern !== '' && str_contains($text, strtolower($pattern))) {
                $hits[] = 'pattern_hit';
            }
        }

        return [
            'assertion_type' => IntelligenceEvaluationAssertionType::OutputForbidsClaimPattern,
            'dimension' => IntelligenceEvaluationDimension::Grounding,
            'status' => $hits === [] ? IntelligenceEvaluationAssertionStatus::Pass : IntelligenceEvaluationAssertionStatus::Fail,
            'is_hard_safety' => false,
            'source_phase' => 'generation',
            'authority' => 'deterministic',
            'expected' => ['forbidden_hits' => 0],
            'actual' => ['forbidden_hits' => count($hits)],
            'reason_code' => $hits === [] ? 'clean_claims' : 'forbidden_claim',
            'diagnostic' => $hits === [] ? 'No forbidden claim patterns' : 'Forbidden claim pattern detected',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $structuredOutput
     * @return array<string, mixed>
     */
    private function assertRequiredConclusions(IntelligenceEvaluationCaseDefinition $case, ?array $structuredOutput): array
    {
        if ($structuredOutput === null || $case->requiredConclusionTypes === []) {
            return $this->skipped(IntelligenceEvaluationAssertionType::OutputRequiresConclusionType);
        }
        $types = array_map(
            static fn ($c) => is_array($c) ? (string) ($c['type'] ?? '') : (string) $c,
            $structuredOutput['conclusions'] ?? []
        );
        $missing = array_values(array_diff($case->requiredConclusionTypes, $types));

        return [
            'assertion_type' => IntelligenceEvaluationAssertionType::OutputRequiresConclusionType,
            'dimension' => IntelligenceEvaluationDimension::Specificity,
            'status' => $missing === [] ? IntelligenceEvaluationAssertionStatus::Pass : IntelligenceEvaluationAssertionStatus::Fail,
            'is_hard_safety' => false,
            'source_phase' => 'generation',
            'authority' => 'deterministic',
            'expected' => ['types' => $case->requiredConclusionTypes],
            'actual' => ['types' => $types, 'missing' => $missing],
            'reason_code' => $missing === [] ? 'conclusions_ok' : 'missing_conclusion',
            'diagnostic' => $missing === [] ? 'Required conclusion types present' : 'Required conclusion types missing',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $structuredOutput
     * @return array<string, mixed>
     */
    private function assertEvidenceRefs(IntelligenceContextPack $pack, ?array $structuredOutput): array
    {
        if ($structuredOutput === null) {
            return $this->skipped(IntelligenceEvaluationAssertionType::OutputDoesNotReferenceUnknownEvidence);
        }
        $allowed = $pack->evidencePack?->evidenceIds() ?? [];
        $claimed = array_map('intval', $structuredOutput['evidence_refs'] ?? []);
        $unknown = array_values(array_diff($claimed, $allowed));

        return [
            'assertion_type' => IntelligenceEvaluationAssertionType::OutputDoesNotReferenceUnknownEvidence,
            'dimension' => IntelligenceEvaluationDimension::Grounding,
            'status' => $unknown === [] ? IntelligenceEvaluationAssertionStatus::Pass : IntelligenceEvaluationAssertionStatus::Fail,
            'is_hard_safety' => true,
            'source_phase' => 'generation',
            'authority' => 'deterministic',
            'expected' => ['unknown_evidence_refs' => 0],
            'actual' => ['unknown_evidence_refs' => count($unknown)],
            'reason_code' => $unknown === [] ? 'refs_ok' : 'unknown_evidence_ref',
            'diagnostic' => $unknown === [] ? 'Evidence refs valid' : 'Unknown Evidence reference',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $structuredOutput
     * @return array<string, mixed>
     */
    private function assertMemoryRefs(IntelligenceContextPack $pack, ?array $structuredOutput): array
    {
        if ($structuredOutput === null) {
            return $this->skipped(IntelligenceEvaluationAssertionType::OutputDoesNotReferenceUnknownMemory);
        }
        $allowed = [];
        foreach ($pack->memoryContextPack->brandExperiences as $item) {
            $allowed[] = $item->opaqueRef;
        }
        foreach ($pack->memoryContextPack->sectorPatterns as $item) {
            $allowed[] = 'sector_artifact:'.$item->artifact->artifactStableKey;
        }
        foreach ($pack->memoryContextPack->skillKnowledge as $item) {
            $allowed[] = $item->opaqueRef;
        }
        $claimed = array_values(array_filter($structuredOutput['memory_refs'] ?? [], 'is_string'));
        $unknown = array_values(array_diff($claimed, $allowed));

        return [
            'assertion_type' => IntelligenceEvaluationAssertionType::OutputDoesNotReferenceUnknownMemory,
            'dimension' => IntelligenceEvaluationDimension::Grounding,
            'status' => $unknown === [] ? IntelligenceEvaluationAssertionStatus::Pass : IntelligenceEvaluationAssertionStatus::Fail,
            'is_hard_safety' => true,
            'source_phase' => 'generation',
            'authority' => 'deterministic',
            'expected' => ['unknown_memory_refs' => 0],
            'actual' => ['unknown_memory_refs' => count($unknown)],
            'reason_code' => $unknown === [] ? 'refs_ok' : 'unknown_memory_ref',
            'diagnostic' => $unknown === [] ? 'Memory refs valid' : 'Unknown Memory reference',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $structuredOutput
     * @return array<string, mixed>
     */
    private function assertMemoryNotEvidence(?array $structuredOutput): array
    {
        if ($structuredOutput === null) {
            return $this->skipped(IntelligenceEvaluationAssertionType::MemoryNotAsEvidence);
        }
        $bad = false;
        foreach ($structuredOutput['evidence_refs'] ?? [] as $ref) {
            if (is_string($ref) && (
                str_starts_with($ref, 'brand_experience:')
                || str_starts_with($ref, 'sector_artifact:')
                || str_starts_with($ref, 'skill:')
            )) {
                $bad = true;
            }
        }

        return [
            'assertion_type' => IntelligenceEvaluationAssertionType::MemoryNotAsEvidence,
            'dimension' => IntelligenceEvaluationDimension::Grounding,
            'status' => $bad ? IntelligenceEvaluationAssertionStatus::Fail : IntelligenceEvaluationAssertionStatus::Pass,
            'is_hard_safety' => true,
            'source_phase' => 'generation',
            'authority' => 'deterministic',
            'expected' => ['memory_as_evidence' => 0],
            'actual' => ['memory_as_evidence' => $bad ? 1 : 0],
            'reason_code' => $bad ? 'memory_as_evidence' : 'ok',
            'diagnostic' => $bad ? 'Memory used as Evidence' : 'Memory/Evidence separation intact',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $structuredOutput
     * @param  array<string, mixed>  $fixtures
     * @return array<string, mixed>
     */
    private function assertCurrentTruth(
        IntelligenceEvaluationCaseDefinition $case,
        IntelligenceContextPack $pack,
        ?array $structuredOutput,
        array $fixtures,
    ): array {
        $currentMarket = (string) ($fixtures['fixture_map']['market'] ?? '');
        $text = strtolower((string) json_encode([
            'goals' => $pack->relevantGoals,
            'brand' => $pack->currentBrandContext,
            'output' => $structuredOutput,
        ]));
        $overridden = str_contains($text, 'primary market is germany')
            || str_contains($text, 'goal remains germany');

        return [
            'assertion_type' => IntelligenceEvaluationAssertionType::CurrentTruthAuthority,
            'dimension' => IntelligenceEvaluationDimension::CurrentTruth,
            'status' => $overridden ? IntelligenceEvaluationAssertionStatus::Fail : IntelligenceEvaluationAssertionStatus::Pass,
            'is_hard_safety' => false,
            'source_phase' => 'generation',
            'authority' => 'deterministic',
            'expected' => ['current_market' => $currentMarket, 'memory_override' => false],
            'actual' => ['memory_override' => $overridden],
            'reason_code' => $overridden ? 'memory_override' : 'current_wins',
            'diagnostic' => $overridden ? 'Historical Memory overrode current truth' : 'Current truth preserved',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $structuredOutput
     * @return array<string, mixed>
     */
    private function assertCurrentContext(IntelligenceContextPack $pack, ?array $structuredOutput): array
    {
        $ok = $pack->currentBrandContext !== [] || $pack->relevantGoals !== [];

        return [
            'assertion_type' => IntelligenceEvaluationAssertionType::OutputRequiresCurrentContext,
            'dimension' => IntelligenceEvaluationDimension::CurrentTruth,
            'status' => $ok ? IntelligenceEvaluationAssertionStatus::Pass : IntelligenceEvaluationAssertionStatus::Fail,
            'is_hard_safety' => false,
            'source_phase' => 'retrieval',
            'authority' => 'deterministic',
            'expected' => ['current_context' => true],
            'actual' => [
                'brand' => $pack->currentBrandContext !== [],
                'goals' => count($pack->relevantGoals),
                'policy' => IntelligenceRetrievalPolicy::VERSION,
            ],
            'reason_code' => $ok ? 'current_present' : 'current_missing',
            'diagnostic' => $ok ? 'Current context present' : 'Current context missing',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $structuredOutput
     * @return array<string, mixed>
     */
    private function assertNoInventedHistory(
        IntelligenceEvaluationCaseDefinition $case,
        IntelligenceContextPack $pack,
        ?array $structuredOutput,
    ): array {
        if ($case->expectBrandHistory) {
            return $this->skipped(IntelligenceEvaluationAssertionType::NoInventedBrandHistory);
        }
        $text = strtolower((string) json_encode($structuredOutput ?? []));
        $patterns = ['previously you', 'last time', 'we learned that your brand', 'your brand previously'];
        $hit = false;
        foreach ($patterns as $p) {
            if (str_contains($text, $p)) {
                $hit = true;
                break;
            }
        }
        $inventedViaRetrieval = $pack->memoryContextPack->brandExperiences !== [];

        return [
            'assertion_type' => IntelligenceEvaluationAssertionType::NoInventedBrandHistory,
            'dimension' => IntelligenceEvaluationDimension::Grounding,
            'status' => (! $hit && ! $inventedViaRetrieval)
                ? IntelligenceEvaluationAssertionStatus::Pass
                : IntelligenceEvaluationAssertionStatus::Fail,
            'is_hard_safety' => false,
            'source_phase' => 'generation',
            'authority' => 'deterministic',
            'expected' => ['invented_history' => false],
            'actual' => [
                'claim_hit' => $hit,
                'retrieved_experiences' => count($pack->memoryContextPack->brandExperiences),
            ],
            'reason_code' => (! $hit && ! $inventedViaRetrieval) ? 'no_history' : 'invented_history',
            'diagnostic' => (! $hit && ! $inventedViaRetrieval)
                ? 'No invented Brand history'
                : 'Invented or leaked Brand history',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $structuredOutput
     * @return array<string, mixed>
     */
    private function assertGenericityScaffold(IntelligenceEvaluationCaseDefinition $case, ?array $structuredOutput): array
    {
        if ($structuredOutput === null) {
            return $this->skipped(IntelligenceEvaluationAssertionType::NoGenericContextInsensitivity, 'no_output');
        }
        // Structural check: paired cases must declare required conclusion types.
        // Full pair comparison happens in runner comparison phase.
        $types = array_map(
            static fn ($c) => is_array($c) ? (string) ($c['type'] ?? '') : (string) $c,
            $structuredOutput['conclusions'] ?? []
        );
        $hasSpecific = $case->requiredConclusionTypes === []
            || array_intersect($case->requiredConclusionTypes, $types) !== [];

        return [
            'assertion_type' => IntelligenceEvaluationAssertionType::NoGenericContextInsensitivity,
            'dimension' => IntelligenceEvaluationDimension::Genericity,
            'status' => $hasSpecific ? IntelligenceEvaluationAssertionStatus::Pass : IntelligenceEvaluationAssertionStatus::NeedsReview,
            'is_hard_safety' => false,
            'source_phase' => 'generation',
            'authority' => 'deterministic',
            'expected' => ['specific_conclusions' => $case->requiredConclusionTypes, 'numeric_genericity_score' => null],
            'actual' => ['types' => $types],
            'reason_code' => $hasSpecific ? 'specific' : 'generic_context_insensitivity',
            'diagnostic' => $hasSpecific
                ? 'Structured conclusions appear context-specific'
                : 'GENERIC_CONTEXT_INSENSITIVITY flagged for review',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function boolGate(
        IntelligenceEvaluationAssertionType $type,
        IntelligenceEvaluationDimension $dimension,
        bool $ok,
        string $key,
        mixed $actual,
    ): array {
        return [
            'assertion_type' => $type,
            'dimension' => $dimension,
            'status' => $ok ? IntelligenceEvaluationAssertionStatus::Pass : IntelligenceEvaluationAssertionStatus::Fail,
            'is_hard_safety' => $type->isZeroToleranceSafety(),
            'source_phase' => 'boundary',
            'authority' => 'deterministic',
            'expected' => [$key => false],
            'actual' => [$key => $actual],
            'reason_code' => $ok ? $key.'_absent' : $key.'_present',
            'diagnostic' => $ok ? $key.' absent' : $key.' detected',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function skipped(IntelligenceEvaluationAssertionType $type, string $reason = 'skipped'): array
    {
        return [
            'assertion_type' => $type,
            'dimension' => IntelligenceEvaluationDimension::Retrieval,
            'status' => IntelligenceEvaluationAssertionStatus::Skipped,
            'is_hard_safety' => false,
            'source_phase' => 'n/a',
            'authority' => 'deterministic',
            'expected' => [],
            'actual' => [],
            'reason_code' => $reason,
            'diagnostic' => 'Assertion skipped',
        ];
    }
}
