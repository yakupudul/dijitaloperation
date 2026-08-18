<?php

namespace App\Enums\Intelligence;

enum IntelligenceTriggerStatus: string
{
    case Pending = 'PENDING';
    case Planned = 'PLANNED';
    case Completed = 'COMPLETED';
    case Coalesced = 'COALESCED';
    case Superseded = 'SUPERSEDED';
    case Ignored = 'IGNORED';
}
