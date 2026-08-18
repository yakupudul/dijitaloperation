<?php

namespace App\Enums;

/**
 * Action execution provenance kinds.
 * Recommendation acceptance alone is NOT an action kind.
 */
enum BrandExperienceActionKind: string
{
    case TaskCompleted = 'task_completed';
    case ExternalOperatorConfirmed = 'external_operator_confirmed';
}
