<?php

namespace App\Services\Assistant;

use App\Enums\AssistantAnswerStrategy;
use App\Enums\AssistantCapabilityId;
use App\Enums\AssistantClarificationReason;
use App\Enums\AssistantIntentType;
use App\Models\DigitalAsset;
use App\Support\Assistant\AssistantCapabilityRegistry;
use App\Support\Assistant\AssistantMetricRegistry;
use App\Support\Assistant\Dto\AssistantIntentCandidate;
use App\Support\Assistant\Dto\AssistantQueryPlan;
use App\Support\Assistant\Dto\AssistantSessionScope;
use App\Support\Assistant\Dto\AssistantThreadState;

/**
 * Builds typed Assistant Query Plans — server-controlled, validated before read.
 */
final class AssistantQueryPlanner
{
    public function __construct(
        private readonly AssistantCapabilityRegistry $capabilities,
        private readonly AssistantMetricRegistry $metrics,
        private readonly AssistantDateRangeResolver $dates,
        private readonly AssistantScopeResolver $scopes,
    ) {}

    public function plan(
        AssistantSessionScope $scope,
        AssistantIntentCandidate $candidate,
        ?AssistantThreadState $threadState = null,
    ): AssistantQueryPlan {
        if ($candidate->intentType === AssistantIntentType::UnsupportedWriteAction
            || $candidate->requestsWrite) {
            return new AssistantQueryPlan(
                scope: $scope,
                intentType: AssistantIntentType::UnsupportedWriteAction,
                capabilities: [],
                answerStrategy: AssistantAnswerStrategy::Unsupported,
                validated: true,
                parameters: ['write_blocked' => true],
            );
        }

        if ($candidate->intentType === AssistantIntentType::Unsupported) {
            return new AssistantQueryPlan(
                scope: $scope,
                intentType: AssistantIntentType::Unsupported,
                capabilities: [],
                answerStrategy: AssistantAnswerStrategy::Unsupported,
                validated: true,
            );
        }

        $periodToken = $candidate->periodToken ?? $threadState?->periodToken;
        $metricId = $candidate->metricId ?? $threadState?->metricId;

        $dateRange = null;
        if (is_string($periodToken) && $periodToken !== '') {
            if (! $this->dates->supports($periodToken)) {
                return new AssistantQueryPlan(
                    scope: $scope,
                    intentType: AssistantIntentType::ClarificationRequired,
                    capabilities: [],
                    answerStrategy: AssistantAnswerStrategy::Clarification,
                    clarificationReason: AssistantClarificationReason::DateRangeRequired,
                    validated: true,
                );
            }
            $dateRange = $this->dates->resolve($periodToken, $scope->timezone);
        }

        return match ($candidate->intentType) {
            AssistantIntentType::FactLookup => $this->planFactLookup($scope, $metricId, $dateRange, $periodToken, $candidate),
            AssistantIntentType::DomainLookup => $this->planDomain($scope, $candidate),
            AssistantIntentType::IntelligenceSummary => $this->planValueStorySummary($scope, $dateRange, $periodToken, $candidate),
            AssistantIntentType::WorkStatus => $this->simplePlan(
                $scope,
                AssistantIntentType::WorkStatus,
                [AssistantCapabilityId::WorkLookup],
                AssistantAnswerStrategy::CanonicalDomainSummary,
            ),
            AssistantIntentType::HistoricalContext => $this->planHistorical($scope, $dateRange, $periodToken, $candidate),
            AssistantIntentType::SectorContext => $this->requireBrandPlan(
                $scope,
                AssistantIntentType::SectorContext,
                [AssistantCapabilityId::SectorPatternLookup],
                AssistantAnswerStrategy::CanonicalDomainSummary,
            ),
            AssistantIntentType::MethodologyGuidance => $this->simplePlan(
                $scope,
                AssistantIntentType::MethodologyGuidance,
                [AssistantCapabilityId::SkillGuidance],
                AssistantAnswerStrategy::MethodologyGuidance,
            ),
            AssistantIntentType::IntelligenceAnalysis => $this->requireBrandPlan(
                $scope,
                AssistantIntentType::IntelligenceAnalysis,
                [AssistantCapabilityId::SpecialistAnalysis],
                AssistantAnswerStrategy::SpecialistStructuredAnalysis,
                agent: 'website-seo-analyst@1.0.0',
                skill: 'website.technical-seo-analysis@1.1.0',
            ),
            default => new AssistantQueryPlan(
                scope: $scope,
                intentType: AssistantIntentType::ClarificationRequired,
                capabilities: [],
                answerStrategy: AssistantAnswerStrategy::Clarification,
                clarificationReason: AssistantClarificationReason::AmbiguousIntent,
                validated: true,
            ),
        };
    }

    private function planFactLookup(
        AssistantSessionScope $scope,
        ?string $metricId,
        $dateRange,
        ?string $periodToken,
        ?AssistantIntentCandidate $candidate = null,
    ): AssistantQueryPlan {
        // Business Outcome facts — never provider conversion fallback.
        if (is_string($metricId) && str_starts_with($metricId, 'business_outcome.')) {
            $kind = substr($metricId, strlen('business_outcome.'));
            if ($dateRange === null) {
                return new AssistantQueryPlan(
                    scope: $scope,
                    intentType: AssistantIntentType::ClarificationRequired,
                    capabilities: [],
                    answerStrategy: AssistantAnswerStrategy::Clarification,
                    clarificationReason: AssistantClarificationReason::DateRangeRequired,
                    validated: true,
                );
            }
            if (! $scope->hasBrand()) {
                return new AssistantQueryPlan(
                    scope: $scope,
                    intentType: AssistantIntentType::ClarificationRequired,
                    capabilities: [],
                    answerStrategy: AssistantAnswerStrategy::Clarification,
                    clarificationReason: AssistantClarificationReason::BrandScopeRequired,
                    validated: true,
                );
            }

            return new AssistantQueryPlan(
                scope: $scope,
                intentType: AssistantIntentType::FactLookup,
                capabilities: [AssistantCapabilityId::BusinessOutcomeLookup],
                answerStrategy: AssistantAnswerStrategy::DeterministicFact,
                dateRange: $dateRange,
                metricId: $metricId,
                sourceRequirements: ['business_outcome'],
                parameters: [
                    'period_token' => $periodToken,
                    'business_outcome_kind' => $kind,
                    'provider_conversion_fallback' => false,
                ],
                validated: true,
            );
        }

        if ($metricId === null || ! $this->metrics->has($metricId)) {
            return new AssistantQueryPlan(
                scope: $scope,
                intentType: AssistantIntentType::ClarificationRequired,
                capabilities: [],
                answerStrategy: AssistantAnswerStrategy::Clarification,
                clarificationReason: AssistantClarificationReason::MetricRequired,
                validated: true,
            );
        }

        if ($dateRange === null) {
            return new AssistantQueryPlan(
                scope: $scope,
                intentType: AssistantIntentType::ClarificationRequired,
                capabilities: [],
                answerStrategy: AssistantAnswerStrategy::Clarification,
                clarificationReason: AssistantClarificationReason::DateRangeRequired,
                validated: true,
            );
        }

        if (! $scope->hasBrand()) {
            $reason = $this->scopes->requireBrandIfAmbiguous(
                (int) $scope->customerId,
                $scope->authorizedBrandIds,
                $scope->brandId,
            );

            return new AssistantQueryPlan(
                scope: $scope,
                intentType: AssistantIntentType::ClarificationRequired,
                capabilities: [],
                answerStrategy: AssistantAnswerStrategy::Clarification,
                clarificationReason: $reason ?? AssistantClarificationReason::BrandScopeRequired,
                validated: true,
            );
        }

        if (! $scope->hasDigitalAsset()) {
            $metric = $this->metrics->get($metricId);
            $type = (string) ($metric['digital_asset_type'] ?? '');
            $assets = DigitalAsset::query()
                ->where('brand_id', $scope->brandId)
                ->whereIn('id', $scope->authorizedDigitalAssetIds)
                ->when($type !== '', static fn ($q) => $q->where('type', $type))
                ->get(['id']);

            if ($assets->count() !== 1) {
                return new AssistantQueryPlan(
                    scope: $scope,
                    intentType: AssistantIntentType::ClarificationRequired,
                    capabilities: [],
                    answerStrategy: AssistantAnswerStrategy::Clarification,
                    clarificationReason: AssistantClarificationReason::DigitalAssetScopeRequired,
                    validated: true,
                );
            }

            return new AssistantQueryPlan(
                scope: $scope,
                intentType: AssistantIntentType::ClarificationRequired,
                capabilities: [],
                answerStrategy: AssistantAnswerStrategy::Clarification,
                clarificationReason: AssistantClarificationReason::DigitalAssetScopeRequired,
                validated: true,
            );
        }

        return new AssistantQueryPlan(
            scope: $scope,
            intentType: AssistantIntentType::FactLookup,
            capabilities: [AssistantCapabilityId::ProviderMetricLookup],
            answerStrategy: AssistantAnswerStrategy::DeterministicFact,
            dateRange: $dateRange,
            metricId: $metricId,
            sourceRequirements: ['provider_data'],
            parameters: ['period_token' => $periodToken],
            validated: true,
        );
    }

    private function planHistorical(
        AssistantSessionScope $scope,
        $dateRange,
        ?string $periodToken,
        AssistantIntentCandidate $candidate,
    ): AssistantQueryPlan {
        $filter = strtolower((string) ($candidate->domainFilter ?? ''));
        $wantsReport = $candidate->capabilityId === AssistantCapabilityId::ReportSnapshotLookup
            || str_contains($filter, 'report')
            || str_contains($filter, 'snapshot')
            || (bool) ($candidate->parameters['historical_report'] ?? false);

        if ($wantsReport) {
            if (! $scope->hasBrand()) {
                return new AssistantQueryPlan(
                    scope: $scope,
                    intentType: AssistantIntentType::ClarificationRequired,
                    capabilities: [],
                    answerStrategy: AssistantAnswerStrategy::Clarification,
                    clarificationReason: AssistantClarificationReason::BrandScopeRequired,
                    validated: true,
                );
            }

            return new AssistantQueryPlan(
                scope: $scope,
                intentType: AssistantIntentType::HistoricalContext,
                capabilities: [AssistantCapabilityId::ReportSnapshotLookup],
                answerStrategy: AssistantAnswerStrategy::CanonicalDomainSummary,
                dateRange: $dateRange,
                sourceRequirements: ['report_snapshot'],
                parameters: [
                    'period_token' => $periodToken,
                    'historical_report' => true,
                    'overrides_current_canonical_domains' => false,
                    'ai_required' => false,
                ],
                validated: true,
            );
        }

        return $this->requireBrandPlan(
            $scope,
            AssistantIntentType::HistoricalContext,
            [AssistantCapabilityId::BrandExperienceLookup],
            AssistantAnswerStrategy::CanonicalDomainSummary,
        );
    }

    private function planValueStorySummary(
        AssistantSessionScope $scope,
        $dateRange,
        ?string $periodToken,
        AssistantIntentCandidate $candidate,
    ): AssistantQueryPlan {
        if ($dateRange === null) {
            return new AssistantQueryPlan(
                scope: $scope,
                intentType: AssistantIntentType::ClarificationRequired,
                capabilities: [],
                answerStrategy: AssistantAnswerStrategy::Clarification,
                clarificationReason: AssistantClarificationReason::DateRangeRequired,
                validated: true,
            );
        }
        if (! $scope->hasBrand()) {
            return new AssistantQueryPlan(
                scope: $scope,
                intentType: AssistantIntentType::ClarificationRequired,
                capabilities: [],
                answerStrategy: AssistantAnswerStrategy::Clarification,
                clarificationReason: AssistantClarificationReason::BrandScopeRequired,
                validated: true,
            );
        }

        return new AssistantQueryPlan(
            scope: $scope,
            intentType: AssistantIntentType::IntelligenceSummary,
            capabilities: [AssistantCapabilityId::ClientValueStorySummary],
            answerStrategy: AssistantAnswerStrategy::CanonicalDomainSummary,
            dateRange: $dateRange,
            sourceRequirements: ['client_value_story'],
            parameters: [
                'period_token' => $periodToken,
                'attribution_established' => false,
                'causality_established' => false,
                'provider_conversion_fallback' => false,
            ],
            validated: true,
        );
    }

    private function planDomain(AssistantSessionScope $scope, AssistantIntentCandidate $candidate): AssistantQueryPlan
    {
        $filter = strtolower((string) ($candidate->domainFilter ?? ''));
        $capability = match (true) {
            str_contains($filter, 'report'), str_contains($filter, 'snapshot') => AssistantCapabilityId::ReportSnapshotLookup,
            str_contains($filter, 'value_story'), str_contains($filter, 'client_value') => AssistantCapabilityId::ClientValueStorySummary,
            str_contains($filter, 'finding') => AssistantCapabilityId::FindingLookup,
            str_contains($filter, 'opportunit') => AssistantCapabilityId::OpportunityLookup,
            str_contains($filter, 'evidence') => AssistantCapabilityId::EvidenceLookup,
            str_contains($filter, 'work'), str_contains($filter, 'task') => AssistantCapabilityId::WorkLookup,
            default => null,
        };

        if ($capability === null && $candidate->capabilityId !== null) {
            $capability = $candidate->capabilityId;
        }

        if ($capability === null) {
            return new AssistantQueryPlan(
                scope: $scope,
                intentType: AssistantIntentType::ClarificationRequired,
                capabilities: [],
                answerStrategy: AssistantAnswerStrategy::Clarification,
                clarificationReason: AssistantClarificationReason::AmbiguousIntent,
                validated: true,
            );
        }

        if (in_array($capability, [
            AssistantCapabilityId::FindingLookup,
            AssistantCapabilityId::OpportunityLookup,
            AssistantCapabilityId::EvidenceLookup,
            AssistantCapabilityId::ClientValueStorySummary,
        ], true) && ! $scope->hasBrand()) {
            return new AssistantQueryPlan(
                scope: $scope,
                intentType: AssistantIntentType::ClarificationRequired,
                capabilities: [],
                answerStrategy: AssistantAnswerStrategy::Clarification,
                clarificationReason: AssistantClarificationReason::BrandScopeRequired,
                validated: true,
            );
        }

        return new AssistantQueryPlan(
            scope: $scope,
            intentType: AssistantIntentType::DomainLookup,
            capabilities: [$capability],
            answerStrategy: AssistantAnswerStrategy::CanonicalDomainSummary,
            domainFilter: $candidate->domainFilter,
            sourceRequirements: [$this->capabilities->get($capability->value)['source_class'] ?? ''],
            parameters: [
                'most_important' => (bool) ($candidate->parameters['most_important'] ?? false),
            ],
            validated: true,
        );
    }

    /**
     * @param  list<AssistantCapabilityId>  $capabilities
     */
    private function simplePlan(
        AssistantSessionScope $scope,
        AssistantIntentType $intent,
        array $capabilities,
        AssistantAnswerStrategy $strategy,
    ): AssistantQueryPlan {
        return new AssistantQueryPlan(
            scope: $scope,
            intentType: $intent,
            capabilities: $capabilities,
            answerStrategy: $strategy,
            validated: true,
        );
    }

    /**
     * @param  list<AssistantCapabilityId>  $capabilities
     */
    private function requireBrandPlan(
        AssistantSessionScope $scope,
        AssistantIntentType $intent,
        array $capabilities,
        AssistantAnswerStrategy $strategy,
        ?string $agent = null,
        ?string $skill = null,
    ): AssistantQueryPlan {
        if (! $scope->hasBrand()) {
            return new AssistantQueryPlan(
                scope: $scope,
                intentType: AssistantIntentType::ClarificationRequired,
                capabilities: [],
                answerStrategy: AssistantAnswerStrategy::Clarification,
                clarificationReason: AssistantClarificationReason::BrandScopeRequired,
                validated: true,
            );
        }

        return new AssistantQueryPlan(
            scope: $scope,
            intentType: $intent,
            capabilities: $capabilities,
            answerStrategy: $strategy,
            agentDefinitionSignature: $agent,
            skillDefinitionSignature: $skill,
            validated: true,
        );
    }
}
