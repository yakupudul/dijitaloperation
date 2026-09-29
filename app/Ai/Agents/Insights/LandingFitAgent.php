<?php

namespace App\Ai\Agents\Insights;

use App\Support\Ai\AiRouteKeys;

/** Checks whether the ads, keywords and landing pages of a Google Ads account tell the same story. */
final class LandingFitAgent extends InsightAgent
{
    public function promptOperation(): string
    {
        return AiRouteKeys::INSIGHT_LANDING_FIT;
    }

    public function tags(): array
    {
        return ['poor', 'partial', 'good'];
    }
}
