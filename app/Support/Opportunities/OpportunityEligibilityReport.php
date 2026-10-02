<?php

namespace App\Support\Opportunities;

use App\Enums\OpportunityEligibilityDisposition;
use App\Models\Finding;
use App\Support\Evidence\Dto\CanonicalEvidenceDto;

final class OpportunityEligibilityReport
{
    /**
     * @param  list<CanonicalEvidenceDto>  $evidence
     * @param  list<Finding>  $findings
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly OpportunityEligibilityDisposition $disposition,
        public readonly array $evidence,
        public readonly array $findings = [],
        public readonly array $details = [],
    ) {}

    public function isEligible(): bool
    {
        return $this->disposition->isEligible();
    }
}
