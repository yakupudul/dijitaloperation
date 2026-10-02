<?php

namespace App\Enums\Intelligence;

enum IntelligenceTriggerSource: string
{
    case EvidenceAnalyticalStateChanged = 'EVIDENCE_ANALYTICAL_STATE_CHANGED';
    case FindingStateChanged = 'FINDING_STATE_CHANGED';
    case ScheduledEvidenceValidityRecheck = 'SCHEDULED_EVIDENCE_VALIDITY_RECHECK';
    case ManualReevaluation = 'MANUAL_REEVALUATION';
}
