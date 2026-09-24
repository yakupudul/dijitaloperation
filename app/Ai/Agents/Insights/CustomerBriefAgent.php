<?php

namespace App\Ai\Agents\Insights;

/** A short status brief of one client before a call or meeting. */
final class CustomerBriefAgent extends InsightAgent
{
    protected function task(): string
    {
        return <<<'TASK'
You prepare a digital marketing agency owner for a call with one client. INPUT_JSON has the client's health score
and its reasons, fee and ad budgets with spend this month and the month-end projection, open advisor work, open
alerts, the latest monthly report commentary and upcoming renewals.
- `summary`: the client's situation in 3 sentences: how things are going, what money is at stake, the mood risk.
- `items`: talking points. Tag `good_news` (results to share), `issue` (a problem to raise before the client does),
  `decision` (something the client must decide or approve), `upsell` (a service that would clearly help, only when
  the data supports it).
TASK;
    }

    public function tags(): array
    {
        return ['issue', 'decision', 'good_news', 'upsell'];
    }
}
