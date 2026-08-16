<?php

namespace App\Enums;

/**
 * Categorical human usefulness outcomes — no 1–100 AI score.
 */
enum IntelligenceEvaluationHumanRubricOutcome: string
{
    case Pass = 'pass';
    case NeedsReview = 'needs_review';
    case Fail = 'fail';
}
