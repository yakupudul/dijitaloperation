<?php

namespace App\Enums;

enum IntelligenceEvaluationRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case SafetyFail = 'safety_fail';
    case NeedsReview = 'needs_review';
}
