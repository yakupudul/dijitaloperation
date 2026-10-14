<?php

namespace App\Ai\Agents\Site;

use App\Support\Ai\AiRouteKeys;

/** `repair.review` (otomatik onarım): prepared repair-desk rows of one site, read once more before the daily send. */
final class RepairReviewAgent extends ReviewVerdictAgent
{
    public function promptOperation(): string
    {
        return AiRouteKeys::REPAIR_REVIEW;
    }
}
