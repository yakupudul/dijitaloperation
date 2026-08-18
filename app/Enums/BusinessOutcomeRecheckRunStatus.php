<?php

namespace App\Enums;

enum BusinessOutcomeRecheckRunStatus: string
{
    case Completed = 'completed';
    case Failed = 'failed';
}
