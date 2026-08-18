<?php

namespace App\Enums;

enum BusinessOutcomeRecheckScheduleStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Archived = 'archived';
}
