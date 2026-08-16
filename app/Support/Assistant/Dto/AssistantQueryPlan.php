<?php

namespace App\Support\Assistant\Dto;

use App\Enums\AssistantAnswerStrategy;
use App\Enums\AssistantCapabilityId;
use App\Enums\AssistantClarificationReason;
use App\Enums\AssistantIntentType;

/**
 * Server-controlled Assistant Query Plan — validated before any data access.
 */
final class AssistantQueryPlan
{
    /**
     * @param  list<AssistantCapabilityId>  $capabilities
     * @param  list<string>  $sourceRequirements
     * @param  array<string, mixed>  $parameters
     */
    public function __construct(
        public readonly AssistantSessionScope $scope,
        public readonly AssistantIntentType $intentType,
        public readonly array $capabilities,
        public readonly AssistantAnswerStrategy $answerStrategy,
        public readonly ?AssistantDateRange $dateRange = null,
        public readonly ?string $metricId = null,
        public readonly ?string $domainFilter = null,
        public readonly ?string $agentDefinitionSignature = null,
        public readonly ?string $skillDefinitionSignature = null,
        public readonly ?AssistantClarificationReason $clarificationReason = null,
        public readonly array $sourceRequirements = [],
        public readonly array $parameters = [],
        public readonly bool $validated = false,
    ) {}

    public function withValidated(bool $validated = true): self
    {
        return new self(
            scope: $this->scope,
            intentType: $this->intentType,
            capabilities: $this->capabilities,
            answerStrategy: $this->answerStrategy,
            dateRange: $this->dateRange,
            metricId: $this->metricId,
            domainFilter: $this->domainFilter,
            agentDefinitionSignature: $this->agentDefinitionSignature,
            skillDefinitionSignature: $this->skillDefinitionSignature,
            clarificationReason: $this->clarificationReason,
            sourceRequirements: $this->sourceRequirements,
            parameters: $this->parameters,
            validated: $validated,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'scope' => $this->scope->toArray(),
            'intent' => $this->intentType->value,
            'capabilities' => array_map(
                static fn (AssistantCapabilityId $c) => $c->value,
                $this->capabilities
            ),
            'answer_strategy' => $this->answerStrategy->value,
            'date_range' => $this->dateRange?->toArray(),
            'metric_id' => $this->metricId,
            'domain_filter' => $this->domainFilter,
            'agent_definition_signature' => $this->agentDefinitionSignature,
            'skill_definition_signature' => $this->skillDefinitionSignature,
            'clarification_reason' => $this->clarificationReason?->value,
            'source_requirements' => $this->sourceRequirements,
            'parameters' => $this->parameters,
            'validated' => $this->validated,
            'llm_direct_execution' => false,
        ];
    }
}
