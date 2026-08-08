<?php

namespace App\Contracts\Findings;

use App\Models\DigitalAsset;
use App\Models\Run;
use App\Support\Findings\RuleEvaluationResult;

/**
 * Module-owned deterministic Evidence → Finding rules.
 * Core applies lifecycle; modules own thresholds and product language.
 */
interface EvaluatesBoundEvidence
{
    public function sourceModule(): string;

    /**
     * @param  list<Run>  $runs  Collection runs for the asset (may include other modules).
     */
    public function evaluate(DigitalAsset $asset, array $runs): RuleEvaluationResult;
}
