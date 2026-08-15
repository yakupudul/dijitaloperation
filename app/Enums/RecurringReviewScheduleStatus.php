<?php

namespace App\Enums;

enum RecurringReviewScheduleStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Ended = 'ended';
}
