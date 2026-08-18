<?php

namespace App\Enums\Observability;

/**
 * Categorical health — never averaged into a numeric score.
 */
enum OperationalHealthStatus: string
{
    case Healthy = 'HEALTHY';
    case Degraded = 'DEGRADED';
    case Unhealthy = 'UNHEALTHY';
    case Unknown = 'UNKNOWN';
}
