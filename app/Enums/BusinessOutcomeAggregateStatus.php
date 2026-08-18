<?php

namespace App\Enums;

enum BusinessOutcomeAggregateStatus: string
{
    case Complete = 'complete';
    case Partial = 'partial';
    case UnknownCompleteness = 'unknown_completeness';
    case NoData = 'no_data';
    case IncompatibleCurrency = 'incompatible_currency';
    case OverlapConflict = 'overlap_conflict';
    case UnsupportedGrain = 'unsupported_grain';
}
