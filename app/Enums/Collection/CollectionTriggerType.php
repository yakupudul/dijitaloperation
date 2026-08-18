<?php

namespace App\Enums\Collection;

enum CollectionTriggerType: string
{
    case Manual = 'manual';
    case InitialBackfill = 'initial_backfill';
    case Incremental = 'incremental';
    case Replay = 'replay';
    case Retry = 'retry';
    case System = 'system';
}
