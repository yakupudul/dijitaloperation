<?php

namespace App\Enums;

enum RecurringMisfirePolicy: string
{
    case SkipMissed = 'skip_missed';
    case RunLatestMissed = 'run_latest_missed';
    case CatchUpBounded = 'catch_up_bounded';
}
