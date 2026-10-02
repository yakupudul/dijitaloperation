<?php

namespace App\Support\Findings;

use App\Enums\FindingEligibilityDisposition;
use App\Support\Evidence\Dto\CanonicalEvidenceDto;

final class FindingEligibilityReport
{
    /**
     * @param  list<CanonicalEvidenceDto>  $evidence
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly FindingEligibilityDisposition $disposition,
        public readonly array $evidence,
        public readonly array $details = [],
    ) {}

    public function isEligible(): bool
    {
        return $this->disposition->isEligible();
    }
}
