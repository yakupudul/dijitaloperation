<?php

namespace App\Services\Assistant;

use App\Contracts\IntelligenceMemory\SectorIdentityResolver;
use App\Enums\AssistantAnswerBlockType;
use App\Enums\AssistantAnswerStrategy;
use App\Enums\AssistantCapabilityId;
use App\Enums\AssistantClarificationReason;
use App\Enums\AssistantCoverageState;
use App\Enums\AssistantFreshnessState;
use App\Enums\AssistantIntentType;
use App\Enums\AssistantSourceClass;
use App\Enums\BusinessOutcomeAggregateStatus;
use App\Enums\BusinessOutcomeKind;
use App\Enums\IntelligenceMemoryLayer;
use App\Models\Brand;
use App\Models\BrandExperience;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Services\Assistant\Adapters\GoogleAdsAssistantReadAdapter;
use App\Services\BusinessOutcomes\BusinessOutcomeReadService;
use App\Services\Findings\FindingReadService;
use App\Services\IntelligenceRetrieval\IntelligenceRetrievalService;
use App\Services\Opportunities\OpportunityReadService;
use App\Services\SectorLearning\SectorMemoryReadService;
use App\Services\Work\WorkReadService;
use App\Support\Assistant\AssistantSourceAuthority;
use App\Support\Assistant\Dto\AssistantAnswer;
use App\Support\Assistant\Dto\AssistantAnswerSourceManifest;
use App\Support\Assistant\Dto\AssistantClaim;
use App\Support\Assistant\Dto\AssistantProviderMetricResult;
use App\Support\Assistant\Dto\AssistantQueryPlan;
use App\Support\Assistant\Dto\AssistantSourceRef;
use App\Support\IntelligenceMemory\Dto\AgentMemoryPermission;
use App\Support\IntelligenceMemory\Dto\SkillMemoryContract;
use App\Support\IntelligenceMemory\Dto\SkillMemoryLayerRequirement;
use App\Support\IntelligenceRetrieval\SkillRetrievalContract;
use App\Support\Opportunities\Dto\OpportunityReadDto;

/**
 * Executes bounded Assistant capabilities — never arbitrary Eloquent/SQL/tools.
 */
final class AssistantCapabilityExecutor
{
    public function __construct(
        private readonly GoogleAdsAssistantReadAdapter $googleAds,
        private readonly FindingReadService $findings,
        private readonly OpportunityReadService $opportunities,
        private readonly WorkReadService $work,
        private readonly SectorMemoryReadService $sectorMemory,
        private readonly SectorIdentityResolver $sectorIdentity,
        private readonly IntelligenceRetrievalService $retrieval,
        private readonly AssistantAnswerGroundingValidator $grounding,
        private readonly AssistantSourceAuthority $authority,
        private readonly BusinessOutcomeReadService $businessOutcomes,
    ) {}

    public function execute(AssistantQueryPlan $plan): AssistantAnswer
    {
        if (! $plan->validated) {
            throw new \InvalidArgumentException('Query plan must be validated before execution.');
        }

        if ($plan->answerStrategy === AssistantAnswerStrategy::Clarification) {
            return $this->clarificationAnswer($plan);
        }

        if ($plan->intentType === AssistantIntentType::UnsupportedWriteAction) {
            return $this->unsupportedWrite($plan);
        }

        if ($plan->intentType === AssistantIntentType::Unsupported
            || $plan->answerStrategy === AssistantAnswerStrategy::Unsupported) {
            return $this->unsupported($plan);
        }

        $capability = $plan->capabilities[0] ?? null;

        return match ($capability) {
            AssistantCapabilityId::ProviderMetricLookup => $this->executeProviderMetric($plan),
            AssistantCapabilityId::FindingLookup => $this->executeFindings($plan),
            AssistantCapabilityId::OpportunityLookup => $this->executeOpportunities($plan),
            AssistantCapabilityId::WorkLookup => $this->executeWork($plan),
            AssistantCapabilityId::EvidenceLookup => $this->executeEvidence($plan),
            AssistantCapabilityId::BrandExperienceLookup => $this->executeBrandHistory($plan),
            AssistantCapabilityId::SectorPatternLookup => $this->executeSector($plan),
            AssistantCapabilityId::SkillGuidance => $this->executeSkill($plan),
            AssistantCapabilityId::SpecialistAnalysis => $this->executeSpecialistRoute($plan),
            AssistantCapabilityId::BusinessOutcomeLookup => $this->executeBusinessOutcome($plan),
            default => $this->unavailable($plan, 'capability_not_executable'),
        };
    }

    private function executeBusinessOutcome(AssistantQueryPlan $plan): AssistantAnswer
    {
        if ($plan->scope->brandId === null || $plan->scope->customerId === null) {
            return $this->unavailable($plan, 'brand_scope_required');
        }
        $range = $plan->dateRange;
        if ($range === null) {
            return $this->unavailable($plan, 'date_range_missing');
        }

        $kindValue = (string) ($plan->parameters['business_outcome_kind'] ?? $plan->domainFilter ?? '');
        $kind = BusinessOutcomeKind::tryFrom($kindValue);
        if ($kind === null) {
            return $this->unavailable($plan, 'unsupported_metric');
        }

        $brand = Brand::query()->find((int) $plan->scope->brandId);
        if ($brand === null || (int) $brand->customer_id !== (int) $plan->scope->customerId) {
            return $this->unavailable($plan, 'brand_scope_required');
        }

        $result = $this->businessOutcomes->aggregate($brand, $kind, $range->startDate, $range->endDate);

        // Never fall back to provider conversions when Business Outcome data is absent.
        if ($result->value === null) {
            return $this->unavailable($plan, 'no_business_outcome_data');
        }

        $ref = new AssistantSourceRef(
            sourceClass: AssistantSourceClass::BusinessOutcome,
            opaqueRef: 'business_outcome:'.$kind->value.':'.$range->startDate.':'.$range->endDate,
            metadata: [
                'definition_id' => $result->definitionId,
                'status' => $result->status->value,
                'currency_code' => $result->currencyCode,
                'completeness' => $result->worstCompleteness?->value,
                'provider_conversion_fallback' => false,
            ],
        );

        $claim = new AssistantClaim(
            claimId: 'business_outcome_'.$kind->value,
            blockType: AssistantAnswerBlockType::Fact,
            statement: sprintf(
                'Reported %s for the requested period is %s%s (%s).',
                $kind->defaultLabel(),
                $result->value,
                $result->currencyCode ? ' '.$result->currencyCode : '',
                $result->status->value,
            ),
            requiredSourceClass: AssistantSourceClass::BusinessOutcome,
            sourceRefs: [$ref],
            limitations: $result->limitations,
            numericValue: is_numeric($result->value) ? (float) $result->value : null,
            unit: $result->currencyCode ?? $result->unit->value,
        );

        $answer = new AssistantAnswer(
            strategy: AssistantAnswerStrategy::DeterministicFact,
            intentType: AssistantIntentType::FactLookup,
            scope: $plan->scope,
            claims: [$claim],
            blocks: [[
                'type' => AssistantAnswerBlockType::Fact->value,
                'business_outcome' => $result->toArray(),
                'provider_conversion_fallback' => false,
            ]],
            sourceManifest: new AssistantAnswerSourceManifest([$ref], [
                'source_class' => AssistantSourceClass::BusinessOutcome->value,
                'live_provider_calls' => 0,
            ]),
            requestedPeriod: $range,
            coveredPeriod: $range,
            coverage: $result->status === BusinessOutcomeAggregateStatus::Complete
                ? AssistantCoverageState::Complete
                : ($result->status === BusinessOutcomeAggregateStatus::Partial
                    ? AssistantCoverageState::Partial
                    : AssistantCoverageState::Missing),
            limitations: $result->limitations,
            runtimeProvenance: [
                'ai_used' => false,
                'provider_calls' => 0,
                'provider_conversion_fallback' => false,
                'domain_writes' => 0,
            ],
            answeredAt: now()->toIso8601String(),
        );

        return $this->grounding->validate($answer, $plan);
    }

    private function executeProviderMetric(AssistantQueryPlan $plan): AssistantAnswer
    {
        $metricId = (string) $plan->metricId;
        $range = $plan->dateRange;
        if ($range === null) {
            return $this->unavailable($plan, 'date_range_missing');
        }

        $result = match (true) {
            str_starts_with($metricId, 'google_ads.') => $this->googleAds->lookupSpend($plan->scope, $range, $metricId),
            default => null,
        };

        if ($result === null) {
            return $this->unavailable($plan, 'unsupported_metric');
        }

        if ($result->unavailable) {
            return $this->unavailableFact($plan, $result);
        }

        if ($result->freshness === AssistantFreshnessState::Stale
            && ($plan->parameters['abstain_if_stale'] ?? false) === true) {
            return $this->abstain($plan, 'stale_source', [$result]);
        }

        $ref = new AssistantSourceRef(
            sourceClass: AssistantSourceClass::ProviderData,
            opaqueRef: $result->opaqueSourceRef,
            metadata: [
                'provider' => $result->provider,
                'digital_asset_id' => $result->digitalAssetId,
                'metric_id' => $result->metricId,
            ],
        );

        $statement = sprintf(
            'Canonical %s for the requested period is %s%s (%s coverage, %s freshness).',
            $result->metricId,
            $result->value === null ? 'unavailable' : (string) $result->value,
            $result->currency ? ' '.$result->currency : '',
            $result->coverage->value,
            $result->freshness->value,
        );

        $claim = new AssistantClaim(
            claimId: 'fact_'.$result->metricId,
            blockType: AssistantAnswerBlockType::Fact,
            statement: $statement,
            requiredSourceClass: AssistantSourceClass::ProviderData,
            sourceRefs: [$ref],
            limitations: $result->limitations,
            numericValue: $result->value,
            unit: $result->currency ?? $result->unit,
        );

        $manifest = new AssistantAnswerSourceManifest(
            sourceRefs: [$ref],
            pins: [
                'metric_id' => $result->metricId,
                'requested_period' => $range->toArray(),
                'covered_period' => $result->coveredPeriod?->toArray(),
                'live_provider_calls' => 0,
            ],
        );

        $answer = new AssistantAnswer(
            strategy: AssistantAnswerStrategy::DeterministicFact,
            intentType: AssistantIntentType::FactLookup,
            scope: $plan->scope,
            claims: [$claim],
            blocks: [[
                'type' => AssistantAnswerBlockType::Fact->value,
                'metric' => $result->toArray(),
            ]],
            sourceManifest: $manifest,
            requestedPeriod: $range,
            coveredPeriod: $result->coveredPeriod,
            freshness: $result->freshness,
            coverage: $result->coverage,
            limitations: $result->limitations,
            runtimeProvenance: [
                'ai_used' => false,
                'llm_arithmetic' => false,
                'provider_calls' => 0,
                'domain_writes' => 0,
            ],
            answeredAt: now()->toIso8601String(),
        );

        return $this->grounding->validate($answer, $plan);
    }

    private function executeFindings(AssistantQueryPlan $plan): AssistantAnswer
    {
        $rows = $this->findings->query([
            'customer_id' => $plan->scope->customerId,
            'brand_id' => $plan->scope->brandId,
        ], 50);

        $refs = [];
        $claims = [];
        foreach ($rows as $i => $dto) {
            $array = method_exists($dto, 'toArray') ? $dto->toArray() : (array) $dto;
            $id = (int) ($array['id'] ?? $i);
            $ref = new AssistantSourceRef(
                sourceClass: AssistantSourceClass::Finding,
                opaqueRef: 'finding:'.$id,
            );
            $refs[] = $ref;
            $claims[] = new AssistantClaim(
                claimId: 'finding_'.$id,
                blockType: AssistantAnswerBlockType::DomainRecord,
                statement: (string) ($array['title'] ?? $array['summary'] ?? 'Finding '.$id),
                requiredSourceClass: AssistantSourceClass::Finding,
                sourceRefs: [$ref],
            );
        }

        return $this->domainAnswer(
            $plan,
            AssistantSourceClass::Finding,
            $claims,
            $refs,
            ['findings' => array_map(static fn ($c) => $c->toArray(), $claims)],
            count($rows) === 0 ? ['no_findings'] : [],
        );
    }

    private function executeOpportunities(AssistantQueryPlan $plan): AssistantAnswer
    {
        $rows = $this->opportunities->query([
            'customer_id' => $plan->scope->customerId,
            'brand_id' => $plan->scope->brandId,
        ], 50);

        $mostImportant = (bool) ($plan->parameters['most_important'] ?? false);
        if ($mostImportant) {
            return $this->mostImportantOpportunity($plan, $rows);
        }

        $refs = [];
        $claims = [];
        foreach ($rows as $dto) {
            $array = $dto->toArray();
            $id = (int) ($array['id'] ?? 0);
            $ref = new AssistantSourceRef(
                sourceClass: AssistantSourceClass::Opportunity,
                opaqueRef: 'opportunity:'.$id,
                metadata: [
                    'qualitative_priority' => $array['qualitative_priority'] ?? null,
                ],
            );
            $refs[] = $ref;
            $claims[] = new AssistantClaim(
                claimId: 'opportunity_'.$id,
                blockType: AssistantAnswerBlockType::DomainRecord,
                statement: (string) ($array['title'] ?? 'Opportunity '.$id),
                requiredSourceClass: AssistantSourceClass::Opportunity,
                sourceRefs: [$ref],
            );
        }

        return $this->domainAnswer(
            $plan,
            AssistantSourceClass::Opportunity,
            $claims,
            $refs,
            ['opportunities' => array_map(static fn ($c) => $c->toArray(), $claims)],
            count($rows) === 0 ? ['no_opportunities'] : [],
        );
    }

    /**
     * @param  list<OpportunityReadDto>  $rows
     */
    private function mostImportantOpportunity(AssistantQueryPlan $plan, array $rows): AssistantAnswer
    {
        if ($rows === []) {
            return $this->unavailable($plan, 'no_opportunities');
        }

        if (count($rows) === 1) {
            $array = $rows[0]->toArray();
            $id = (int) $array['id'];
            $ref = new AssistantSourceRef(
                sourceClass: AssistantSourceClass::Opportunity,
                opaqueRef: 'opportunity:'.$id,
            );
            $claim = new AssistantClaim(
                claimId: 'opportunity_'.$id,
                blockType: AssistantAnswerBlockType::DomainRecord,
                statement: (string) ($array['title'] ?? 'Opportunity '.$id),
                requiredSourceClass: AssistantSourceClass::Opportunity,
                sourceRefs: [$ref],
            );

            return $this->domainAnswer($plan, AssistantSourceClass::Opportunity, [$claim], [$ref], [
                'opportunities' => [$claim->toArray()],
                'most_important' => true,
                'canonical_order_used' => true,
                'magic_score' => null,
                'first_row_fallback' => false,
            ]);
        }

        // Qualitative priority only — if all share same priority / no unique top, refuse arbitrary pick.
        $priorities = [];
        foreach ($rows as $dto) {
            $array = $dto->toArray();
            $priorities[(string) ($array['qualitative_priority'] ?? 'medium')][] = $array;
        }

        $order = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        ksort($priorities);
        $sortedKeys = array_keys($priorities);
        usort($sortedKeys, static fn ($a, $b) => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));
        $topKey = $sortedKeys[0] ?? null;
        $top = $topKey !== null ? $priorities[$topKey] : [];

        if (count($top) !== 1) {
            $refs = [];
            $claims = [];
            foreach ($rows as $dto) {
                $array = $dto->toArray();
                $id = (int) $array['id'];
                $ref = new AssistantSourceRef(
                    sourceClass: AssistantSourceClass::Opportunity,
                    opaqueRef: 'opportunity:'.$id,
                );
                $refs[] = $ref;
                $claims[] = new AssistantClaim(
                    claimId: 'opportunity_'.$id,
                    blockType: AssistantAnswerBlockType::DomainRecord,
                    statement: (string) ($array['title'] ?? 'Opportunity '.$id),
                    requiredSourceClass: AssistantSourceClass::Opportunity,
                    sourceRefs: [$ref],
                );
            }

            return new AssistantAnswer(
                strategy: AssistantAnswerStrategy::Clarification,
                intentType: $plan->intentType,
                scope: $plan->scope,
                claims: $claims,
                blocks: [[
                    'type' => AssistantAnswerBlockType::Clarification->value,
                    'message' => 'Multiple active Opportunities exist and MoxDOP has no canonical ordering that proves one is most important.',
                    'candidates' => array_map(static fn ($c) => $c->toArray(), $claims),
                    'magic_score' => null,
                    'first_row_fallback' => false,
                ]],
                sourceManifest: new AssistantAnswerSourceManifest($refs, [
                    'canonical_order_unavailable' => true,
                ]),
                clarificationReason: AssistantClarificationReason::CanonicalOrderUnavailable,
                limitations: ['no_canonical_most_important'],
                runtimeProvenance: [
                    'ai_used' => false,
                    'provider_calls' => 0,
                    'domain_writes' => 0,
                ],
                answeredAt: now()->toIso8601String(),
            );
        }

        $winner = $top[0];
        $id = (int) $winner['id'];
        $ref = new AssistantSourceRef(
            sourceClass: AssistantSourceClass::Opportunity,
            opaqueRef: 'opportunity:'.$id,
            metadata: ['qualitative_priority' => $topKey],
        );
        $claim = new AssistantClaim(
            claimId: 'opportunity_'.$id,
            blockType: AssistantAnswerBlockType::DomainRecord,
            statement: (string) ($winner['title'] ?? 'Opportunity '.$id),
            requiredSourceClass: AssistantSourceClass::Opportunity,
            sourceRefs: [$ref],
        );

        return $this->domainAnswer($plan, AssistantSourceClass::Opportunity, [$claim], [$ref], [
            'opportunities' => [$claim->toArray()],
            'most_important' => true,
            'canonical_order_used' => true,
            'qualitative_priority' => $topKey,
            'magic_score' => null,
            'first_row_fallback' => false,
        ]);
    }

    private function executeWork(AssistantQueryPlan $plan): AssistantAnswer
    {
        $items = array_values(array_filter(
            $this->work->workItems(),
            static fn (array $item): bool => (int) ($item['customer_id'] ?? 0) === (int) $plan->scope->customerId
                && ($plan->scope->brandId === null || (int) ($item['brand_id'] ?? 0) === (int) $plan->scope->brandId)
        ));

        $refs = [];
        $claims = [];
        $blocks = [];
        foreach ($items as $item) {
            $id = (string) ($item['id'] ?? '');
            $ref = new AssistantSourceRef(
                sourceClass: AssistantSourceClass::Work,
                opaqueRef: 'work:'.$id,
            );
            $refs[] = $ref;
            $qa = $item['qa_status'] ?? null;
            $approval = $item['current_approval']['status'] ?? null;
            $status = (string) ($item['status'] ?? '');
            $limitations = [];
            if ($status === 'done' && $qa !== null && $qa !== 'passed') {
                $limitations[] = 'task_done_does_not_mean_qa_passed';
            }
            if ($status === 'done' && $approval !== null && $approval !== 'approved') {
                $limitations[] = 'task_done_does_not_mean_approved';
            }
            $claims[] = new AssistantClaim(
                claimId: 'work_'.$id,
                blockType: AssistantAnswerBlockType::DomainRecord,
                statement: (string) ($item['title'] ?? 'Work '.$id),
                requiredSourceClass: AssistantSourceClass::Work,
                sourceRefs: [$ref],
                limitations: $limitations,
            );
            $blocks[] = [
                'id' => $id,
                'status' => $status,
                'qa_status' => $qa,
                'approval_status' => $approval,
                'task_done_equals_qa_passed' => false,
                'task_done_equals_approved' => false,
            ];
        }

        return $this->domainAnswer($plan, AssistantSourceClass::Work, $claims, $refs, [
            'work_items' => $blocks,
        ], $items === [] ? ['no_work_items'] : []);
    }

    private function executeEvidence(AssistantQueryPlan $plan): AssistantAnswer
    {
        $query = Evidence::query()->where('is_canonical', true)->limit(50);
        // Scope via digital assets of brand when available
        if ($plan->scope->digitalAssetId !== null) {
            $query->where('digital_asset_id', $plan->scope->digitalAssetId);
        } elseif ($plan->scope->brandId !== null) {
            $assetIds = DigitalAsset::query()
                ->where('brand_id', $plan->scope->brandId)
                ->pluck('id')
                ->all();
            $query->whereIn('digital_asset_id', $assetIds);
        }

        $rows = $query->get();
        $refs = [];
        $claims = [];
        foreach ($rows as $row) {
            $ref = new AssistantSourceRef(
                sourceClass: AssistantSourceClass::Evidence,
                opaqueRef: 'evidence:'.$row->id,
                fingerprint: $row->evidence_fingerprint,
            );
            $refs[] = $ref;
            $claims[] = new AssistantClaim(
                claimId: 'evidence_'.$row->id,
                blockType: AssistantAnswerBlockType::DomainRecord,
                statement: (string) ($row->title ?? 'Evidence '.$row->id),
                requiredSourceClass: AssistantSourceClass::Evidence,
                sourceRefs: [$ref],
            );
        }

        return $this->domainAnswer($plan, AssistantSourceClass::Evidence, $claims, $refs, [
            'evidence' => array_map(static fn ($c) => $c->toArray(), $claims),
        ], $rows->isEmpty() ? ['no_evidence'] : []);
    }

    private function executeBrandHistory(AssistantQueryPlan $plan): AssistantAnswer
    {
        $rows = BrandExperience::query()
            ->with('currentRevision')
            ->where('brand_id', $plan->scope->brandId)
            ->where('customer_id', $plan->scope->customerId)
            ->limit(10)
            ->get();

        $refs = [];
        $claims = [];
        foreach ($rows as $experience) {
            $revision = $experience->currentRevision;
            $ref = new AssistantSourceRef(
                sourceClass: AssistantSourceClass::BrandExperience,
                opaqueRef: 'brand_experience:'.($revision?->id ?? $experience->id),
            );
            $refs[] = $ref;
            $claims[] = new AssistantClaim(
                claimId: 'experience_'.$experience->id,
                blockType: AssistantAnswerBlockType::HistoricalContext,
                statement: (string) ($revision?->situation_summary ?? 'Brand Experience '.$experience->id),
                requiredSourceClass: AssistantSourceClass::BrandExperience,
                sourceRefs: [$ref],
                limitations: ['historical_context', 'causality_not_established', 'not_current_metric_source'],
            );
        }

        return $this->domainAnswer($plan, AssistantSourceClass::BrandExperience, $claims, $refs, [
            'experiences' => array_map(static fn ($c) => $c->toArray(), $claims),
            'same_brand_only' => true,
            'cross_brand' => false,
        ], $rows->isEmpty() ? ['no_brand_experiences'] : []);
    }

    private function executeSector(AssistantQueryPlan $plan): AssistantAnswer
    {
        $brand = Brand::query()->with('customer')->findOrFail((int) $plan->scope->brandId);
        $identity = $this->sectorIdentity->resolveForBrand($brand);
        if (! $identity->isPresent() || $identity->aiInferred) {
            return $this->unavailable($plan, 'no_canonical_sector');
        }

        $artifacts = $this->sectorMemory->listReleasedForSector((string) $identity->code, 5);
        if ($artifacts === []) {
            return $this->unavailable($plan, 'no_released_sector_artifact');
        }

        $refs = [];
        $claims = [];
        foreach ($artifacts as $artifact) {
            $ref = new AssistantSourceRef(
                sourceClass: AssistantSourceClass::SectorPattern,
                opaqueRef: 'sector_artifact:'.$artifact->artifactStableKey,
                metadata: [
                    'observational_label' => $artifact->observationalLabel,
                    'industry_benchmark_claim' => false,
                ],
            );
            $refs[] = $ref;
            $claims[] = new AssistantClaim(
                claimId: 'sector_'.$artifact->artifactStableKey,
                blockType: AssistantAnswerBlockType::SectorContext,
                statement: 'Within privacy-qualified MoxDOP cohort observations: '.$artifact->summaryText,
                requiredSourceClass: AssistantSourceClass::SectorPattern,
                sourceRefs: [$ref],
                limitations: array_merge($artifact->limitations, [
                    'not_industry_benchmark',
                    'observational_only',
                    'not_other_brand_experience',
                ]),
            );
        }

        $answer = $this->domainAnswer($plan, AssistantSourceClass::SectorPattern, $claims, $refs, [
            'sector_patterns' => array_map(static fn ($c) => $c->toArray(), $claims),
            'similar_means' => 'privacy_safe_sector_cohort',
            'raw_similar_customer' => false,
            'contributor_identities' => null,
        ]);

        // Guard: serialized answer must not contain contributor lineage payloads.
        // Match JSON keys precisely — do not false-positive on "sector_contributor_identities": null.
        $json = strtolower((string) json_encode($answer->toArray()));
        if (preg_match('/"(contributor_id|contributor_ids|lineage_entries)"\s*:/', $json) === 1) {
            return $this->unavailable($plan, 'sector_privacy_violation_blocked');
        }

        return $answer;
    }

    private function executeSkill(AssistantQueryPlan $plan): AssistantAnswer
    {
        $ref = new AssistantSourceRef(
            sourceClass: AssistantSourceClass::SkillKnowledge,
            opaqueRef: 'skill:methodology:general',
        );
        $claim = new AssistantClaim(
            claimId: 'skill_methodology',
            blockType: AssistantAnswerBlockType::Methodology,
            statement: 'General methodology guidance from Skill/Playbook knowledge. This is not a Customer fact.',
            requiredSourceClass: AssistantSourceClass::SkillKnowledge,
            sourceRefs: [$ref],
            limitations: ['methodology_only', 'not_customer_fact', 'not_provider_fact'],
        );

        return $this->domainAnswer($plan, AssistantSourceClass::SkillKnowledge, [$claim], [$ref], [
            'methodology' => true,
            'customer_facts' => false,
            'provider_facts' => false,
        ]);
    }

    private function executeSpecialistRoute(AssistantQueryPlan $plan): AssistantAnswer
    {
        // Architecture: reuse Prompt 54 retrieval assembly; do not invent parallel stack.
        // No live AI in this foundation path — returns retrieval provenance for analysis readiness.
        $memory = new SkillMemoryContract(
            $plan->skillDefinitionSignature ?? 'assistant.analysis@1.0.0',
            [
                new SkillMemoryLayerRequirement(
                    layer: IntelligenceMemoryLayer::Brand,
                    purpose: 'history',
                    maximumRetrievalCount: 3,
                ),
                new SkillMemoryLayerRequirement(
                    layer: IntelligenceMemoryLayer::Sector,
                    purpose: 'cohort',
                    maximumRetrievalCount: 2,
                    requiresPrivacyQualification: true,
                ),
                new SkillMemoryLayerRequirement(
                    layer: IntelligenceMemoryLayer::Skill,
                    purpose: 'methodology',
                    maximumRetrievalCount: 2,
                ),
            ]
        );
        $contract = new SkillRetrievalContract(
            skillSignature: $plan->skillDefinitionSignature ?? 'assistant.analysis@1.0.0',
            memoryContract: $memory,
        );

        $pack = $this->retrieval->retrieve(
            agentDefinitionSignature: $plan->agentDefinitionSignature ?? 'assistant-analysis@1.0.0',
            skillDefinitionSignature: $plan->skillDefinitionSignature ?? 'assistant.analysis@1.0.0',
            customerId: (int) $plan->scope->customerId,
            brandId: (int) $plan->scope->brandId,
            options: [
                'skill_memory_contract_override' => $memory,
                'agent_permission_override' => new AgentMemoryPermission(
                    $plan->agentDefinitionSignature ?? 'assistant-analysis@1.0.0',
                    [
                        IntelligenceMemoryLayer::Brand,
                        IntelligenceMemoryLayer::Sector,
                        IntelligenceMemoryLayer::Skill,
                    ]
                ),
                'retrieval_contract_override' => $contract,
            ],
        );

        $ref = new AssistantSourceRef(
            sourceClass: AssistantSourceClass::Evidence,
            opaqueRef: 'retrieval:'.$pack->retrievalFingerprint,
            fingerprint: $pack->retrievalFingerprint,
        );

        $claim = new AssistantClaim(
            claimId: 'specialist_analysis_ready',
            blockType: AssistantAnswerBlockType::Analysis,
            statement: 'Specialist analysis route assembled via Prompt 50/54 contracts. Live model inference is optional and not required for architecture validation.',
            requiredSourceClass: AssistantSourceClass::Evidence,
            sourceRefs: [$ref],
            limitations: ['analytical_interpretation', 'not_persisted_canonical_rank'],
            isAnalytical: true,
        );

        return new AssistantAnswer(
            strategy: AssistantAnswerStrategy::SpecialistStructuredAnalysis,
            intentType: AssistantIntentType::IntelligenceAnalysis,
            scope: $plan->scope,
            claims: [$claim],
            blocks: [[
                'type' => AssistantAnswerBlockType::Analysis->value,
                'retrieval_fingerprint' => $pack->retrievalFingerprint,
                'labelled_as' => 'analytical_prioritization',
                'persisted_canonical_rank' => false,
            ]],
            sourceManifest: new AssistantAnswerSourceManifest(
                sourceRefs: [$ref],
                pins: [
                    'agent' => $plan->agentDefinitionSignature,
                    'skill' => $plan->skillDefinitionSignature,
                    'retrieval_policy' => $pack->retrievalPolicyVersion,
                ],
                retrievalManifestFingerprint: $pack->retrievalFingerprint,
            ),
            limitations: ['analysis_requires_exact_skill_contract'],
            runtimeProvenance: [
                'ai_used' => false,
                'prompt_50_reuse' => true,
                'prompt_54_reuse' => true,
                'provider_calls' => 0,
                'domain_writes' => 0,
                'parallel_assistant_ai' => false,
            ],
            answeredAt: now()->toIso8601String(),
        );
    }

    /**
     * @param  list<AssistantClaim>  $claims
     * @param  list<AssistantSourceRef>  $refs
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $limitations
     */
    private function domainAnswer(
        AssistantQueryPlan $plan,
        AssistantSourceClass $class,
        array $claims,
        array $refs,
        array $payload,
        array $limitations = [],
    ): AssistantAnswer {
        $answer = new AssistantAnswer(
            strategy: AssistantAnswerStrategy::CanonicalDomainSummary,
            intentType: $plan->intentType,
            scope: $plan->scope,
            claims: $claims,
            blocks: [[
                'type' => AssistantAnswerBlockType::DomainRecord->value,
                'source_class' => $class->value,
                'payload' => $payload,
            ]],
            sourceManifest: new AssistantAnswerSourceManifest($refs, [
                'source_class' => $class->value,
                'authority' => $this->authority->matrix()[$class->value] ?? [],
            ]),
            limitations: $limitations,
            runtimeProvenance: [
                'ai_used' => false,
                'provider_calls' => 0,
                'domain_writes' => 0,
            ],
            answeredAt: now()->toIso8601String(),
        );

        return $this->grounding->validate($answer, $plan);
    }

    private function clarificationAnswer(AssistantQueryPlan $plan): AssistantAnswer
    {
        return new AssistantAnswer(
            strategy: AssistantAnswerStrategy::Clarification,
            intentType: AssistantIntentType::ClarificationRequired,
            scope: $plan->scope,
            claims: [],
            blocks: [[
                'type' => AssistantAnswerBlockType::Clarification->value,
                'reason' => $plan->clarificationReason?->value,
            ]],
            sourceManifest: new AssistantAnswerSourceManifest([]),
            clarificationReason: $plan->clarificationReason,
            runtimeProvenance: ['ai_used' => false, 'provider_calls' => 0, 'domain_writes' => 0],
            answeredAt: now()->toIso8601String(),
        );
    }

    private function unsupportedWrite(AssistantQueryPlan $plan): AssistantAnswer
    {
        return new AssistantAnswer(
            strategy: AssistantAnswerStrategy::Unsupported,
            intentType: AssistantIntentType::UnsupportedWriteAction,
            scope: $plan->scope,
            claims: [],
            blocks: [[
                'type' => AssistantAnswerBlockType::Limitation->value,
                'message' => 'Write/command actions are outside Prompt 56 Assistant capability.',
                'write_allowed' => false,
            ]],
            sourceManifest: new AssistantAnswerSourceManifest([]),
            limitations: ['read_only'],
            runtimeProvenance: [
                'ai_used' => false,
                'provider_calls' => 0,
                'domain_writes' => 0,
                'provider_writes' => 0,
            ],
            answeredAt: now()->toIso8601String(),
        );
    }

    private function unsupported(AssistantQueryPlan $plan): AssistantAnswer
    {
        return new AssistantAnswer(
            strategy: AssistantAnswerStrategy::Unsupported,
            intentType: AssistantIntentType::Unsupported,
            scope: $plan->scope,
            claims: [],
            blocks: [[
                'type' => AssistantAnswerBlockType::Limitation->value,
                'message' => 'This question is not supported by the current Assistant capability registry.',
            ]],
            sourceManifest: new AssistantAnswerSourceManifest([]),
            runtimeProvenance: ['ai_used' => false, 'provider_calls' => 0, 'domain_writes' => 0],
            answeredAt: now()->toIso8601String(),
        );
    }

    private function unavailable(AssistantQueryPlan $plan, string $reason): AssistantAnswer
    {
        return new AssistantAnswer(
            strategy: AssistantAnswerStrategy::Unavailable,
            intentType: $plan->intentType,
            scope: $plan->scope,
            claims: [],
            blocks: [[
                'type' => AssistantAnswerBlockType::Limitation->value,
                'message' => 'I do not have enough current MoxDOP data to answer that reliably.',
                'reason' => $reason,
            ]],
            sourceManifest: new AssistantAnswerSourceManifest([]),
            abstained: true,
            abstentionReason: $reason,
            limitations: [$reason],
            coverage: AssistantCoverageState::Missing,
            runtimeProvenance: ['ai_used' => false, 'provider_calls' => 0, 'domain_writes' => 0, 'model_guess' => false],
            answeredAt: now()->toIso8601String(),
        );
    }

    private function unavailableFact(AssistantQueryPlan $plan, AssistantProviderMetricResult $result): AssistantAnswer
    {
        // Missing ≠ zero
        return new AssistantAnswer(
            strategy: AssistantAnswerStrategy::Unavailable,
            intentType: AssistantIntentType::FactLookup,
            scope: $plan->scope,
            claims: [],
            blocks: [[
                'type' => AssistantAnswerBlockType::Limitation->value,
                'message' => 'The requested fact is unavailable in current MoxDOP data.',
                'metric' => $result->toArray(),
                'missing_as_zero' => false,
            ]],
            sourceManifest: new AssistantAnswerSourceManifest([
                new AssistantSourceRef(
                    sourceClass: AssistantSourceClass::ProviderData,
                    opaqueRef: $result->opaqueSourceRef,
                ),
            ]),
            requestedPeriod: $result->requestedPeriod,
            coveredPeriod: $result->coveredPeriod,
            freshness: $result->freshness,
            coverage: $result->coverage,
            abstained: true,
            abstentionReason: $result->unavailableReason,
            limitations: $result->limitations,
            runtimeProvenance: [
                'ai_used' => false,
                'llm_arithmetic' => false,
                'provider_calls' => 0,
                'missing_as_zero' => false,
            ],
            answeredAt: now()->toIso8601String(),
        );
    }

    /**
     * @param  list<AssistantProviderMetricResult>  $results
     */
    private function abstain(AssistantQueryPlan $plan, string $reason, array $results = []): AssistantAnswer
    {
        return new AssistantAnswer(
            strategy: AssistantAnswerStrategy::Unavailable,
            intentType: $plan->intentType,
            scope: $plan->scope,
            claims: [],
            blocks: [[
                'type' => AssistantAnswerBlockType::Limitation->value,
                'message' => 'I do not have enough current MoxDOP data to answer that reliably.',
                'reason' => $reason,
                'results' => array_map(static fn ($r) => $r->toArray(), $results),
            ]],
            sourceManifest: new AssistantAnswerSourceManifest([]),
            abstained: true,
            abstentionReason: $reason,
            runtimeProvenance: ['ai_used' => false, 'provider_calls' => 0, 'generic_fallback' => false],
            answeredAt: now()->toIso8601String(),
        );
    }
}
