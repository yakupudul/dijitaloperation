<?php

namespace App\Ai\Agents\Insights;

use App\Support\Ai\AiRouteKeys;

/** Finds the most likely cause of one alert from the brand's daily numbers across channels. */
final class AlertCauseAgent extends InsightAgent
{
    public function promptOperation(): string
    {
        return AiRouteKeys::INSIGHT_ALERT_CAUSE;
    }

    public function tags(): array
    {
        return ['likely', 'possible', 'ruled_out'];
    }
}
