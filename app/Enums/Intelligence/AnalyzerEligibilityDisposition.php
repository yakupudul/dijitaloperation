<?php

namespace App\Enums\Intelligence;

enum AnalyzerEligibilityDisposition: string
{
    case Eligible = 'ELIGIBLE';
    case NoRelevantDependency = 'NO_RELEVANT_DEPENDENCY';
    case RequiredEvidenceMissing = 'REQUIRED_EVIDENCE_MISSING';
    case EvidenceStale = 'EVIDENCE_STALE';
    case IntegrityBlocked = 'INTEGRITY_BLOCKED';
    case CoverageInsufficient = 'COVERAGE_INSUFFICIENT';
    case ScopeNotApplicable = 'SCOPE_NOT_APPLICABLE';
    case ServiceScopeNotApplicable = 'SERVICE_SCOPE_NOT_APPLICABLE';
    case AutomationDisabled = 'AUTOMATION_DISABLED';
    case AiBudgetBlocked = 'AI_BUDGET_BLOCKED';
    case ActiveEquivalentExecution = 'ACTIVE_EQUIVALENT_EXECUTION';
    case UnchangedInput = 'UNCHANGED_INPUT';
}
