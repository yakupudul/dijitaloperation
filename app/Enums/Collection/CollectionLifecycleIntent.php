<?php

namespace App\Enums\Collection;

/**
 * Bounded collection lifecycle intents (Prompt 62).
 * Distinct from CollectionTriggerType and IncrementalWorkReason.
 */
enum CollectionLifecycleIntent: string
{
    case InitialBackfill = 'INITIAL_BACKFILL';
    case Incremental = 'INCREMENTAL';
    case LateDataRepair = 'LATE_DATA_REPAIR';
    case CatchUp = 'CATCH_UP';
    case Manual = 'MANUAL';

    public function label(): string
    {
        return match ($this) {
            self::InitialBackfill => 'Initial Backfill',
            self::Incremental => 'Incremental',
            self::LateDataRepair => 'Late Data Repair',
            self::CatchUp => 'Catch-Up',
            self::Manual => 'Manual',
        };
    }

    /**
     * Deterministic scheduling priority (lower = higher priority).
     * 1) Initial coverage 2) Catch-Up gap 3) Incremental 4) Late repair.
     */
    public function priorityRank(): int
    {
        return match ($this) {
            self::InitialBackfill => 1,
            self::CatchUp => 2,
            self::Incremental => 3,
            self::LateDataRepair => 4,
            self::Manual => 5,
        };
    }
}
