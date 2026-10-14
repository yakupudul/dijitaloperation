<?php

namespace App\Ai\Agents\Site;

use App\Support\Ai\AiRouteKeys;

/** `content.review` (otopilot): an article or Business Profile posts MoxDOP prepared by itself, read once more before the daily send. */
final class ContentReviewAgent extends ReviewVerdictAgent
{
    public function promptOperation(): string
    {
        return AiRouteKeys::CONTENT_REVIEW;
    }
}
