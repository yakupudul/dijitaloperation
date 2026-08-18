<?php

namespace App\Enums;

/**
 * Distinguishes structural CI success from live-model usefulness claims.
 */
enum IntelligenceEvaluationLiveModelStatus: string
{
    case StructuralPass = 'structural_pass';
    case LiveModelNotEvaluated = 'live_model_not_evaluated';
    case LiveModelEvaluated = 'live_model_evaluated';
    case HumanReviewPending = 'human_review_pending';
    case HumanReviewed = 'human_reviewed';
}
