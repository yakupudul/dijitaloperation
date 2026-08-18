<?php

namespace App\Services\Assistant;

use App\Enums\AssistantCapabilityId;
use App\Enums\AssistantIntentType;
use App\Support\Assistant\AssistantMetricRegistry;
use App\Support\Assistant\Dto\AssistantIntentCandidate;

/**
 * Optional structured interpretation helper (Prompt 56).
 *
 * Produces typed candidate intents only — never executes data access,
 * never invents Customer/Brand/table/column IDs, never generates SQL.
 *
 * Natural-language model interpretation (when used) must emit the same
 * candidate shape; server validation remains mandatory.
 */
final class AssistantIntentInterpreter
{
    /**
     * Deterministic keyword → candidate mapping for architecture tests and
     * future UI stubs. Does not call an LLM.
     */
    public function interpretDeterministic(string $question): AssistantIntentCandidate
    {
        $q = strtolower(trim($question));

        if (preg_match('/\b(pause|create task|accept recommendation|change budget|write)\b/', $q) === 1) {
            return new AssistantIntentCandidate(
                intentType: AssistantIntentType::UnsupportedWriteAction,
                requestsWrite: true,
                parameters: ['raw_question_hash' => hash('sha256', $q)],
            );
        }

        if (str_contains($q, 'similar') && (str_contains($q, 'sector') || str_contains($q, 'brand') || str_contains($q, 'health'))) {
            return new AssistantIntentCandidate(
                intentType: AssistantIntentType::SectorContext,
                capabilityId: AssistantCapabilityId::SectorPatternLookup,
            );
        }

        if (str_contains($q, 'most important') && str_contains($q, 'opportunit')) {
            return new AssistantIntentCandidate(
                intentType: AssistantIntentType::DomainLookup,
                capabilityId: AssistantCapabilityId::OpportunityLookup,
                domainFilter: 'opportunity',
                parameters: ['most_important' => true],
            );
        }

        if (str_contains($q, 'google ads') && (str_contains($q, 'spend') || str_contains($q, 'cost'))) {
            $period = 'last_30_days';
            if (str_contains($q, 'last 7')) {
                $period = 'last_7_days';
            } elseif (str_contains($q, 'last month')) {
                $period = 'last_month';
            }

            return new AssistantIntentCandidate(
                intentType: AssistantIntentType::FactLookup,
                capabilityId: AssistantCapabilityId::ProviderMetricLookup,
                metricId: AssistantMetricRegistry::GOOGLE_ADS_SPEND,
                periodToken: $period,
            );
        }

        if (str_contains($q, 'report said') || str_contains($q, 'report show') || str_contains($q, 'july report')
            || (str_contains($q, 'report') && (str_contains($q, 'historical') || str_contains($q, 'snapshot') || str_contains($q, 'generated')))) {
            $period = 'last_month';
            if (str_contains($q, 'july')) {
                $period = 'last_month';
            }

            return new AssistantIntentCandidate(
                intentType: AssistantIntentType::HistoricalContext,
                capabilityId: AssistantCapabilityId::ReportSnapshotLookup,
                periodToken: $period,
                domainFilter: 'report_snapshot',
                parameters: ['historical_report' => true],
            );
        }

        if (str_contains($q, 'finding') || str_contains($q, 'problem')) {
            return new AssistantIntentCandidate(
                intentType: AssistantIntentType::DomainLookup,
                capabilityId: AssistantCapabilityId::FindingLookup,
                domainFilter: 'finding',
            );
        }

        if (str_contains($q, 'working on') || str_contains($q, 'task')) {
            return new AssistantIntentCandidate(
                intentType: AssistantIntentType::WorkStatus,
                capabilityId: AssistantCapabilityId::WorkLookup,
                domainFilter: 'work',
            );
        }

        if (str_contains($q, 'how should') || str_contains($q, 'methodology') || str_contains($q, 'investigate')) {
            return new AssistantIntentCandidate(
                intentType: AssistantIntentType::MethodologyGuidance,
                capabilityId: AssistantCapabilityId::SkillGuidance,
            );
        }

        return new AssistantIntentCandidate(
            intentType: AssistantIntentType::Unsupported,
        );
    }
}
