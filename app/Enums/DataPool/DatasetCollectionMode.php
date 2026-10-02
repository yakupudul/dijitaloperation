<?php

namespace App\Enums\DataPool;

enum DatasetCollectionMode: string
{
    case HistoricalIncremental = 'HISTORICAL_INCREMENTAL';
    case CurrentSnapshot = 'CURRENT_SNAPSHOT';
    case PeriodObservation = 'PERIOD_OBSERVATION';
    case ControlledOnDemand = 'CONTROLLED_ON_DEMAND';
    case StaticOrSlowMetadata = 'STATIC_OR_SLOW_METADATA';

    public function usesDailyWatermark(): bool
    {
        return $this === self::HistoricalIncremental || $this === self::PeriodObservation;
    }
}
