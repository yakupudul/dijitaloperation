<?php

namespace App\Ai\Agents\Insights;

use App\Support\Ai\AiRouteKeys;

/** Sorts Google Ads search terms into irrelevant (negative candidates), fine, or to watch, using the brand's services. */
final class SearchTermTriageAgent extends InsightAgent
{
    public function promptOperation(): string
    {
        return AiRouteKeys::INSIGHT_SEARCH_TERM_TRIAGE;
    }

    public function tags(): array
    {
        return ['exclude', 'review', 'keep'];
    }
}
