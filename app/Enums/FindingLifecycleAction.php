<?php

namespace App\Enums;

enum FindingLifecycleAction: string
{
    case Created = 'created';
    case Reconfirmed = 'reconfirmed';
    case ReusedEvaluation = 'reused_evaluation';
    case Resolved = 'resolved';
    case Reopened = 'reopened';
    case Blocked = 'blocked';
    case ConditionFalseNoFinding = 'condition_false_no_finding';
    case None = 'none';
}
