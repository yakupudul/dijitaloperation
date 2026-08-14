<?php

namespace App\Enums;

enum FindingEligibilityDisposition: string
{
    case Eligible = 'eligible';
    case BlockedIntegrity = 'blocked_integrity';
    case BlockedStale = 'blocked_stale';
    case BlockedPartial = 'blocked_partial';
    case BlockedProviderLimited = 'blocked_provider_limited';
    case BlockedUnverified = 'blocked_unverified';
    case MissingEvidence = 'missing_evidence';
    case ScopeMismatch = 'scope_mismatch';
    case PeriodMismatch = 'period_mismatch';
    case CurrencyMismatch = 'currency_mismatch';
    case AttributionMismatch = 'attribution_mismatch';
    case RuleDisabled = 'rule_disabled';
    case Unsupported = 'unsupported';
    case IncompleteOperands = 'incomplete_operands';

    public function isEligible(): bool
    {
        return $this === self::Eligible;
    }

    public function isClearingProof(): bool
    {
        return $this === self::Eligible;
    }
}
