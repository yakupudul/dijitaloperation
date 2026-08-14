<?php

namespace App\Enums;

enum OpportunityLifecycleAction: string
{
    case Created = 'created';
    case Reconfirmed = 'reconfirmed';
    case ReusedEvaluation = 'reused_evaluation';
    case Closed = 'closed';
    case Reopened = 'reopened';
    case Blocked = 'blocked';
    case ConditionFalseNoOpportunity = 'condition_false_no_opportunity';
    case ContextChanged = 'context_changed';
    case None = 'none';
}
