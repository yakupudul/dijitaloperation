<?php

namespace App\Enums;

enum IntelligenceEvaluationAssertionStatus: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case NeedsReview = 'needs_review';
    case Skipped = 'skipped';

    public function isHardFail(): bool
    {
        return $this === self::Fail;
    }
}
