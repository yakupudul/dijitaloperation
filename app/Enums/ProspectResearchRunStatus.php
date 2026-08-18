<?php

namespace App\Enums;

enum ProspectResearchRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Partial = 'partial';
    case Completed = 'completed';
    case Failed = 'failed';
}
