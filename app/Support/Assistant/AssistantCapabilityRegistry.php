<?php

namespace App\Support\Assistant;

use App\Enums\AssistantCapabilityId;
use App\Enums\AssistantSourceClass;

/**
 * Bounded Assistant Capability Registry (Prompt 56).
 * No DATABASE_QUERY / ALL_MEMORY_SEARCH / CROSS_CUSTOMER_SEARCH.
 */
final class AssistantCapabilityRegistry
{
    public const string VERSION = 'assistant_capability_registry_v1';

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return [
            AssistantCapabilityId::ProviderMetricLookup->value => [
                'id' => AssistantCapabilityId::ProviderMetricLookup->value,
                'source_class' => AssistantSourceClass::ProviderData->value,
                'ai_required' => false,
                'deterministic' => true,
                'read_only' => true,
                'requires_customer' => true,
                'requires_brand' => true,
                'requires_digital_asset' => true,
                'supports_period' => true,
                'max_cardinality' => 1,
                'live_provider_calls' => false,
                'domain_writes' => false,
            ],
            AssistantCapabilityId::EvidenceLookup->value => [
                'id' => AssistantCapabilityId::EvidenceLookup->value,
                'source_class' => AssistantSourceClass::Evidence->value,
                'ai_required' => false,
                'deterministic' => true,
                'read_only' => true,
                'requires_customer' => true,
                'requires_brand' => true,
                'requires_digital_asset' => false,
                'supports_period' => false,
                'max_cardinality' => 50,
                'live_provider_calls' => false,
                'domain_writes' => false,
            ],
            AssistantCapabilityId::FindingLookup->value => [
                'id' => AssistantCapabilityId::FindingLookup->value,
                'source_class' => AssistantSourceClass::Finding->value,
                'ai_required' => false,
                'deterministic' => true,
                'read_only' => true,
                'requires_customer' => true,
                'requires_brand' => true,
                'requires_digital_asset' => false,
                'supports_period' => false,
                'max_cardinality' => 50,
                'live_provider_calls' => false,
                'domain_writes' => false,
            ],
            AssistantCapabilityId::OpportunityLookup->value => [
                'id' => AssistantCapabilityId::OpportunityLookup->value,
                'source_class' => AssistantSourceClass::Opportunity->value,
                'ai_required' => false,
                'deterministic' => true,
                'read_only' => true,
                'requires_customer' => true,
                'requires_brand' => true,
                'requires_digital_asset' => false,
                'supports_period' => false,
                'max_cardinality' => 50,
                'live_provider_calls' => false,
                'domain_writes' => false,
            ],
            AssistantCapabilityId::WorkLookup->value => [
                'id' => AssistantCapabilityId::WorkLookup->value,
                'source_class' => AssistantSourceClass::Work->value,
                'ai_required' => false,
                'deterministic' => true,
                'read_only' => true,
                'requires_customer' => true,
                'requires_brand' => false,
                'requires_digital_asset' => false,
                'supports_period' => false,
                'max_cardinality' => 100,
                'live_provider_calls' => false,
                'domain_writes' => false,
            ],
            AssistantCapabilityId::BrandExperienceLookup->value => [
                'id' => AssistantCapabilityId::BrandExperienceLookup->value,
                'source_class' => AssistantSourceClass::BrandExperience->value,
                'ai_required' => false,
                'deterministic' => true,
                'read_only' => true,
                'requires_customer' => true,
                'requires_brand' => true,
                'requires_digital_asset' => false,
                'supports_period' => false,
                'max_cardinality' => 10,
                'live_provider_calls' => false,
                'domain_writes' => false,
            ],
            AssistantCapabilityId::SectorPatternLookup->value => [
                'id' => AssistantCapabilityId::SectorPatternLookup->value,
                'source_class' => AssistantSourceClass::SectorPattern->value,
                'ai_required' => false,
                'deterministic' => true,
                'read_only' => true,
                'requires_customer' => true,
                'requires_brand' => true,
                'requires_digital_asset' => false,
                'supports_period' => false,
                'max_cardinality' => 5,
                'live_provider_calls' => false,
                'domain_writes' => false,
            ],
            AssistantCapabilityId::SkillGuidance->value => [
                'id' => AssistantCapabilityId::SkillGuidance->value,
                'source_class' => AssistantSourceClass::SkillKnowledge->value,
                'ai_required' => false,
                'deterministic' => true,
                'read_only' => true,
                'requires_customer' => false,
                'requires_brand' => false,
                'requires_digital_asset' => false,
                'supports_period' => false,
                'max_cardinality' => 10,
                'live_provider_calls' => false,
                'domain_writes' => false,
            ],
            AssistantCapabilityId::SpecialistAnalysis->value => [
                'id' => AssistantCapabilityId::SpecialistAnalysis->value,
                'source_class' => AssistantSourceClass::Evidence->value,
                'ai_required' => true,
                'deterministic' => false,
                'read_only' => true,
                'requires_customer' => true,
                'requires_brand' => true,
                'requires_digital_asset' => false,
                'supports_period' => false,
                'max_cardinality' => 1,
                'live_provider_calls' => false,
                'domain_writes' => false,
                'reuses_prompt_50' => true,
                'reuses_prompt_54' => true,
            ],
        ];
    }

    public function has(string $capabilityId): bool
    {
        return array_key_exists($capabilityId, $this->all());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $capabilityId): ?array
    {
        return $this->all()[$capabilityId] ?? null;
    }

    /**
     * Forbidden capability names that must never exist.
     *
     * @return list<string>
     */
    public function forbiddenCapabilityIds(): array
    {
        return [
            'database_query',
            'all_memory_search',
            'cross_customer_search',
            'run_sql',
            'query_database',
            'search_everything',
            'search_all_customers',
            'search_all_memory',
            'arbitrary_eloquent',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'version' => self::VERSION,
            'capabilities' => array_keys($this->all()),
            'forbidden' => $this->forbiddenCapabilityIds(),
            'fine_tuning' => false,
            'embeddings' => false,
            'vector_db' => false,
            'similar_customer' => false,
        ];
    }
}
