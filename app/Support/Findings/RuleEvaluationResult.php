<?php

namespace App\Support\Findings;

use App\Models\DigitalAsset;
use App\Models\Run;
use DateTimeInterface;

/**
 * Explicit evaluated-rule scope for lifecycle-safe auto-resolution.
 */
final class RuleEvaluationResult
{
    /**
     * @param  list<string>  $evaluatedRuleIds  Rule IDs that were actually evaluated with valid Evidence.
     * @param  list<RuleMatch>  $matches
     */
    public function __construct(
        public readonly DigitalAsset $asset,
        public readonly string $sourceModule,
        public readonly Run $run,
        public readonly bool $evaluationSuccessful,
        public readonly array $evaluatedRuleIds,
        public readonly array $matches,
        public readonly DateTimeInterface $observedAt,
    ) {}
}
