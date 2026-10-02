<?php

namespace App\Enums\Intelligence;

enum IntelligencePlanStatus: string
{
    case Planned = 'PLANNED';
    case Running = 'RUNNING';
    case Completed = 'COMPLETED';
    case Failed = 'FAILED';
    case Blocked = 'BLOCKED';
    case Coalesced = 'COALESCED';
    case Superseded = 'SUPERSEDED';
    case NoRelevantAnalyzer = 'NO_RELEVANT_ANALYZER';
}
