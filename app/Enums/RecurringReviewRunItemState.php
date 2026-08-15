<?php

namespace App\Enums;

enum RecurringReviewRunItemState: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Skipped = 'skipped';
    case NotApplicable = 'not_applicable';
}
