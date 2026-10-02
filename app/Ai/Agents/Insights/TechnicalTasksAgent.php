<?php

namespace App\Ai\Agents\Insights;

use App\Support\Ai\AiRouteKeys;

/** Turns the website crawl findings into a prioritised task list a developer can work through. */
final class TechnicalTasksAgent extends InsightAgent
{
    public function promptOperation(): string
    {
        return AiRouteKeys::INSIGHT_TECHNICAL_TASKS;
    }

    public function tags(): array
    {
        return ['high', 'medium', 'low'];
    }
}
