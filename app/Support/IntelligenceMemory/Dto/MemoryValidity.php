<?php

namespace App\Support\IntelligenceMemory\Dto;

use App\Enums\MemoryValidityState;

/**
 * Temporal validity envelope for future memory artifacts.
 */
final class MemoryValidity
{
    public function __construct(
        public readonly MemoryValidityState $state,
        public readonly ?string $sourceOccurredAt = null,
        public readonly ?string $recordedAt = null,
        public readonly ?string $effectiveAt = null,
        public readonly ?string $supersededAt = null,
        public readonly ?string $expiresAt = null,
    ) {}

    public function isEligibleForCurrentAgentContext(): bool
    {
        return $this->state->isEligibleForCurrentAgentContext();
    }

    /**
     * @return array{
     *     state: string,
     *     source_occurred_at: string|null,
     *     recorded_at: string|null,
     *     effective_at: string|null,
     *     superseded_at: string|null,
     *     expires_at: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'source_occurred_at' => $this->sourceOccurredAt,
            'recorded_at' => $this->recordedAt,
            'effective_at' => $this->effectiveAt,
            'superseded_at' => $this->supersededAt,
            'expires_at' => $this->expiresAt,
        ];
    }
}
