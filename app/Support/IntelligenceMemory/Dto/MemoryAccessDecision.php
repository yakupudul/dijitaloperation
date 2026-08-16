<?php

namespace App\Support\IntelligenceMemory\Dto;

use App\Enums\IntelligenceMemoryLayer;
use App\Enums\MemoryAccessDenialReason;

/**
 * Decision returned by the Intelligence Memory access policy / gateway.
 *
 * Does not contain memory content payloads (retrieval is Prompt 54).
 */
final class MemoryAccessDecision
{
    /**
     * @param  list<MemoryAccessDenialReason>  $denialReasons
     * @param  list<string>  $notes
     */
    public function __construct(
        public readonly bool $allowed,
        public readonly IntelligenceMemoryLayer $layer,
        public readonly array $denialReasons = [],
        public readonly array $notes = [],
    ) {}

    public static function allow(IntelligenceMemoryLayer $layer, string ...$notes): self
    {
        return new self(true, $layer, [], array_values($notes));
    }

    public static function deny(IntelligenceMemoryLayer $layer, MemoryAccessDenialReason ...$reasons): self
    {
        return new self(false, $layer, array_values($reasons), []);
    }

    /**
     * @return array{
     *     allowed: bool,
     *     layer: string,
     *     denial_reasons: list<string>,
     *     notes: list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'layer' => $this->layer->value,
            'denial_reasons' => array_map(
                static fn (MemoryAccessDenialReason $reason): string => $reason->value,
                $this->denialReasons,
            ),
            'notes' => $this->notes,
        ];
    }
}
