<?php

namespace App\Enums\Collection;

/**
 * Planner decision action for a Resource × Dataset (or asset-scoped fan-out).
 */
enum CollectionLifecycleAction: string
{
    case InitialBackfill = 'INITIAL_BACKFILL';
    case CatchUp = 'CATCH_UP';
    case Incremental = 'INCREMENTAL';
    case LateDataRepair = 'LATE_DATA_REPAIR';
    case NoWork = 'NO_WORK';
    case Blocked = 'BLOCKED';
}
