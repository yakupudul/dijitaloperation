<?php

namespace App\Enums;

enum RecurringReviewCadence: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
}
