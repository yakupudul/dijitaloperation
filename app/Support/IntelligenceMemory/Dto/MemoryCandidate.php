<?php

namespace App\Support\IntelligenceMemory\Dto;

/**
 * Future AI memory candidate — never trusted Memory by itself.
 */
final class MemoryCandidate
{
    /**
     * @param  array<string, mixed>  $proposedPayload
     */
    public function __construct(
        public readonly string $proposedLayer,
        public readonly string $sourceAgentRunId,
        public readonly array $proposedPayload,
        public readonly string $status = 'memory_candidate',
    ) {
        if ($this->status !== 'memory_candidate') {
            throw new \InvalidArgumentException('AI proposals must remain status=memory_candidate.');
        }
    }

    public function isTrustedMemory(): bool
    {
        return false;
    }
}
