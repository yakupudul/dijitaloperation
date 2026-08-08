<?php

namespace MoxDop\Website\Opportunities;

/**
 * Heuristic product defaults for GSC striking-distance opportunities.
 *
 * Striking distance is a MoxDOP heuristic, not a Google-defined metric.
 */
final class GscStrikingDistanceConfig
{
    /** @var float Inclusive lower bound (heuristic). */
    public const float POSITION_MIN = 5.0;

    /** @var float Inclusive upper bound (heuristic). */
    public const float POSITION_MAX = 20.0;

    /** Minimum impressions in the Evidence period to surface an opportunity. */
    public const int MINIMUM_IMPRESSIONS = 20;

    /** Maximum opportunities returned to the workspace. */
    public const int MAX_OPPORTUNITIES = 15;

    /** Overview compact summary row count. */
    public const int OVERVIEW_TOP = 3;
}
