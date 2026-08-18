<?php

namespace App\Enums;

enum RecurringReviewRunStatus: string
{
    case Scheduled = 'scheduled';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Skipped = 'skipped';
    case Cancelled = 'cancelled';
}
