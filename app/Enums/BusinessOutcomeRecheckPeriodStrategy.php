<?php

namespace App\Enums;

enum BusinessOutcomeRecheckPeriodStrategy: string
{
    case PreviousCalendarMonth = 'previous_calendar_month';
    case PreviousCalendarWeek = 'previous_calendar_week';
}
