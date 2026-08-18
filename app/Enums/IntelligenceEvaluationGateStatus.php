<?php

namespace App\Enums;

/**
 * Policy gate outcome — not a weighted intelligence score.
 */
enum IntelligenceEvaluationGateStatus: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case NeedsReview = 'needs_review';
    case NotEvaluated = 'not_evaluated';
}
