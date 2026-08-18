<?php

namespace App\Enums;

enum IntentRadarRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Partial = 'partial';
    case Completed = 'completed';
    case Failed = 'failed';
}
