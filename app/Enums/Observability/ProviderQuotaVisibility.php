<?php

namespace App\Enums\Observability;

/**
 * Provider quota visibility — never invent percentages.
 */
enum ProviderQuotaVisibility: string
{
    case ProviderReportedUsageAndLimit = 'PROVIDER_REPORTED_USAGE_AND_LIMIT';
    case ProviderReportedRemaining = 'PROVIDER_REPORTED_REMAINING';
    case ProviderReportedReset = 'PROVIDER_REPORTED_RESET';
    case RateLimitSignalOnly = 'RATE_LIMIT_SIGNAL_ONLY';
    case NotExposed = 'NOT_EXPOSED';
    case Unknown = 'UNKNOWN';
}
