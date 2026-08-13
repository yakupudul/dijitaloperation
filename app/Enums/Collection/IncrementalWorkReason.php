<?php

namespace App\Enums\Collection;

/**
 * Why an incremental Dataset plan interval exists.
 * Distinct from CollectionTriggerType and PlanDisposition.
 */
enum IncrementalWorkReason: string
{
    case NewCoverage = 'NEW_COVERAGE';
    case CatchUp = 'CATCH_UP';
    case LateDataReprocess = 'LATE_DATA_REPROCESS';
    case GapRecovery = 'GAP_RECOVERY';
    case SnapshotRefresh = 'SNAPSHOT_REFRESH';
    case ContractUpgrade = 'CONTRACT_UPGRADE';
    case ManualReplay = 'MANUAL_REPLAY';
}
