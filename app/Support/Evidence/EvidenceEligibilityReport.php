<?php

namespace App\Support\Evidence;

use App\Enums\EvidenceEligibilityStatus;

final class EvidenceEligibilityReport
{
    /**
     * @param  array<string, bool>  $gates
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly EvidenceEligibilityStatus $status,
        public readonly string $reason,
        public readonly array $gates,
        public readonly array $details = [],
    ) {}

    public function isEligible(): bool
    {
        return $this->status->isEligible();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'reason' => $this->reason,
            'gates' => $this->gates,
            'details' => $this->details,
        ];
    }
}
