<?php

namespace App\Support\Assistant\Dto;

use App\Enums\AssistantAnswerStrategy;
use App\Enums\AssistantClarificationReason;
use App\Enums\AssistantCoverageState;
use App\Enums\AssistantFreshnessState;
use App\Enums\AssistantIntentType;

/**
 * Typed Assistant Answer — never Markdown-only truth.
 */
final class AssistantAnswer
{
    /**
     * @param  list<AssistantClaim>  $claims
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<string>  $limitations
     * @param  array<string, mixed>  $runtimeProvenance
     */
    public function __construct(
        public readonly AssistantAnswerStrategy $strategy,
        public readonly AssistantIntentType $intentType,
        public readonly AssistantSessionScope $scope,
        public readonly array $claims,
        public readonly array $blocks,
        public readonly AssistantAnswerSourceManifest $sourceManifest,
        public readonly ?AssistantDateRange $requestedPeriod = null,
        public readonly ?AssistantDateRange $coveredPeriod = null,
        public readonly AssistantFreshnessState $freshness = AssistantFreshnessState::NotApplicable,
        public readonly AssistantCoverageState $coverage = AssistantCoverageState::NotApplicable,
        public readonly array $limitations = [],
        public readonly ?AssistantClarificationReason $clarificationReason = null,
        public readonly bool $abstained = false,
        public readonly ?string $abstentionReason = null,
        public readonly array $runtimeProvenance = [],
        public readonly string $answeredAt = '',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'strategy' => $this->strategy->value,
            'intent' => $this->intentType->value,
            'scope' => $this->scope->toArray(),
            'claims' => array_map(static fn (AssistantClaim $c) => $c->toArray(), $this->claims),
            'blocks' => $this->blocks,
            'source_manifest' => $this->sourceManifest->toArray(),
            'requested_period' => $this->requestedPeriod?->toArray(),
            'covered_period' => $this->coveredPeriod?->toArray(),
            'freshness' => $this->freshness->value,
            'coverage' => $this->coverage->value,
            'limitations' => $this->limitations,
            'clarification_reason' => $this->clarificationReason?->value,
            'abstained' => $this->abstained,
            'abstention_reason' => $this->abstentionReason,
            'runtime_provenance' => $this->runtimeProvenance,
            'answered_at' => $this->answeredAt,
            'markdown_only_truth' => false,
            'chain_of_thought' => null,
        ];
    }
}
