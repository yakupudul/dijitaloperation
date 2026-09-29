<?php

namespace App\Ai\Agents\Insights;

use App\Support\Ai\AiRouteKeys;

/** Reads Meta campaign / ad set / ad names with country × city results to say which service, area and audience converted. */
final class MetaGeoAgent extends InsightAgent
{
    public function promptOperation(): string
    {
        return AiRouteKeys::INSIGHT_META_GEO;
    }

    public function tags(): array
    {
        return ['winner', 'waste', 'test'];
    }
}
