<?php

namespace App\Enums\Observability;

/**
 * High-level provider request categories for metrics (preserve raw code separately).
 */
enum ProviderRequestOutcome: string
{
    case Success = 'SUCCESS';
    case Auth = 'AUTH';
    case RateLimit = 'RATE_LIMIT';
    case Provider4xx = 'PROVIDER_4XX';
    case Provider5xx = 'PROVIDER_5XX';
    case Timeout = 'TIMEOUT';
    case Network = 'NETWORK';
    case Application = 'APPLICATION';
    case Unknown = 'UNKNOWN';
}
