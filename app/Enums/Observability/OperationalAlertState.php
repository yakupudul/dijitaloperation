<?php

namespace App\Enums\Observability;

enum OperationalAlertState: string
{
    case Open = 'OPEN';
    case Acknowledged = 'ACKNOWLEDGED';
    case Resolved = 'RESOLVED';
    case Suppressed = 'SUPPRESSED';
}
