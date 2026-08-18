<?php

namespace App\Enums;

enum BrandExperienceEvidenceRole: string
{
    case Situation = 'situation';
    case Baseline = 'baseline';
    case ActionSupport = 'action_support';
    case FollowUp = 'follow_up';
    case Outcome = 'outcome';
    case Context = 'context';
}
