<?php

namespace App\Enums;

enum RecurringReviewOccurrenceKind: string
{
    case Scheduled = 'scheduled';
    case Manual = 'manual';
}
