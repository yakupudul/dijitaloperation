<?php

namespace App\Enums\DataPool;

enum MaterializationStatus: string
{
    case NotCollected = 'NOT_COLLECTED';
    case Available = 'AVAILABLE';
    case Partial = 'PARTIAL';
    case Stale = 'STALE';
    case Unavailable = 'UNAVAILABLE';
}
