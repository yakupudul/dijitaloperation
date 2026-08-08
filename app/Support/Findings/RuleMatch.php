<?php

namespace App\Support\Findings;

/**
 * One matched Finding candidate from a module rule evaluation.
 * Fingerprint must be stable issue identity (never include current metric values).
 */
final class RuleMatch
{
    public function __construct(
        public readonly string $ruleId,
        public readonly string $fingerprint,
        public readonly string $category,
        public readonly string $severity,
        public readonly string $title,
        public readonly string $summary,
        public readonly float $confidence,
        public readonly ?string $recommendationTitle = null,
        public readonly ?string $recommendationAction = null,
    ) {}
}
