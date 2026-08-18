<?php

namespace App\Enums;

enum BusinessOutcomeRecheckResultStatus: string
{
    case Complete = 'complete';
    case Partial = 'partial';
    case UnknownCompleteness = 'unknown_completeness';
    case NoData = 'no_data';
    case NotApplicable = 'not_applicable';
    case IntegrityBlocked = 'integrity_blocked';
}
