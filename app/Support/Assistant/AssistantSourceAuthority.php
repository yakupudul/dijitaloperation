<?php

namespace App\Support\Assistant;

use App\Enums\AssistantSourceClass;

/**
 * Exact semantic authority of Assistant sources (Prompt 56).
 * No numeric authority score.
 */
final class AssistantSourceAuthority
{
    public const string VERSION = 'assistant_source_authority_v1';

    /**
     * @return array<string, array<string, mixed>>
     */
    public function matrix(): array
    {
        return [
            AssistantSourceClass::ProviderData->value => [
                'current_measured_fact' => true,
                'current_condition' => false,
                'potential' => false,
                'execution' => false,
                'historical' => false,
                'sector' => false,
                'methodology' => false,
                'can_satisfy_provider_metric' => true,
                'can_override_current_evidence' => false,
            ],
            AssistantSourceClass::Evidence->value => [
                'current_measured_fact' => true,
                'current_condition' => false,
                'potential' => false,
                'execution' => false,
                'historical' => false,
                'sector' => false,
                'methodology' => false,
                'can_satisfy_provider_metric' => false,
                'can_override_current_evidence' => false,
            ],
            AssistantSourceClass::Finding->value => [
                'current_measured_fact' => false,
                'current_condition' => true,
                'potential' => false,
                'execution' => false,
                'historical' => false,
                'sector' => false,
                'methodology' => false,
                'can_satisfy_provider_metric' => false,
                'can_override_current_evidence' => false,
            ],
            AssistantSourceClass::Opportunity->value => [
                'current_measured_fact' => false,
                'current_condition' => false,
                'potential' => true,
                'execution' => false,
                'historical' => false,
                'sector' => false,
                'methodology' => false,
                'can_satisfy_provider_metric' => false,
                'can_override_current_evidence' => false,
            ],
            AssistantSourceClass::Recommendation->value => [
                'current_measured_fact' => false,
                'current_condition' => false,
                'potential' => false,
                'execution' => false,
                'historical' => false,
                'sector' => false,
                'methodology' => false,
                'can_satisfy_provider_metric' => false,
                'can_override_current_evidence' => false,
            ],
            AssistantSourceClass::Work->value => [
                'current_measured_fact' => false,
                'current_condition' => false,
                'potential' => false,
                'execution' => true,
                'historical' => false,
                'sector' => false,
                'methodology' => false,
                'can_satisfy_provider_metric' => false,
                'can_override_current_evidence' => false,
            ],
            AssistantSourceClass::BrandExperience->value => [
                'current_measured_fact' => false,
                'current_condition' => false,
                'potential' => false,
                'execution' => false,
                'historical' => true,
                'sector' => false,
                'methodology' => false,
                'can_satisfy_provider_metric' => false,
                'can_override_current_evidence' => false,
            ],
            AssistantSourceClass::SectorPattern->value => [
                'current_measured_fact' => false,
                'current_condition' => false,
                'potential' => false,
                'execution' => false,
                'historical' => false,
                'sector' => true,
                'methodology' => false,
                'can_satisfy_provider_metric' => false,
                'can_override_current_evidence' => false,
            ],
            AssistantSourceClass::SkillKnowledge->value => [
                'current_measured_fact' => false,
                'current_condition' => false,
                'potential' => false,
                'execution' => false,
                'historical' => false,
                'sector' => false,
                'methodology' => true,
                'can_satisfy_provider_metric' => false,
                'can_override_current_evidence' => false,
            ],
            AssistantSourceClass::BusinessOutcome->value => [
                'current_measured_fact' => true,
                'current_condition' => false,
                'potential' => false,
                'execution' => false,
                'historical' => false,
                'sector' => false,
                'methodology' => false,
                'can_satisfy_provider_metric' => false,
                'can_override_current_evidence' => false,
                'is_provider_conversion' => false,
                'is_crm_record' => false,
            ],
            AssistantSourceClass::ClientValueStory->value => [
                'current_measured_fact' => false,
                'current_condition' => false,
                'potential' => false,
                'execution' => false,
                'historical' => false,
                'sector' => false,
                'methodology' => false,
                'can_satisfy_provider_metric' => false,
                'can_override_current_evidence' => false,
                'is_read_projection' => true,
                'is_writable_truth' => false,
                'attribution_established' => false,
                'causality_established' => false,
                'prefer_underlying_sources_for_precise_facts' => true,
            ],
            AssistantSourceClass::ReportSnapshot->value => [
                'current_measured_fact' => false,
                'current_condition' => false,
                'potential' => false,
                'execution' => false,
                'historical' => true,
                'sector' => false,
                'methodology' => false,
                'can_satisfy_provider_metric' => false,
                'can_override_current_evidence' => false,
                'is_immutable_historical_report' => true,
                'is_writable_truth' => false,
                'overrides_current_canonical_domains' => false,
                'attribution_established' => false,
                'causality_established' => false,
            ],
        ];
    }

    public function canSatisfy(AssistantSourceClass $claimClass, AssistantSourceClass $provided): bool
    {
        if ($claimClass === $provided) {
            return true;
        }

        // Evidence may support some factual assertions but never provider metrics via Sector/Skill/Memory.
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'version' => self::VERSION,
            'numeric_authority_score' => null,
            'matrix' => $this->matrix(),
            'current_fact_wins_over_history' => true,
            'brand_fact_wins_over_sector' => true,
        ];
    }
}
