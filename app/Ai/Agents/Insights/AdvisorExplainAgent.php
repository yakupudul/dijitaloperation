<?php

namespace App\Ai\Agents\Insights;

/** Explains one advisor finding: why it matters for this business and the concrete steps to act on it. */
final class AdvisorExplainAgent extends InsightAgent
{
    protected function task(): string
    {
        return <<<'TASK'
You explain one rule-based advisor finding for a digital marketing agency working on a client's account.
INPUT_JSON has the finding (rule, title, reason, evidence numbers, checklist), the channel and the brand facts.
- `summary`: what is happening and why it matters for this business, with the numbers from the evidence.
- `items`: the concrete steps to act on it, in order, where to click in the ad platform or site, and what to check
  afterwards. Tag each step `now` (do today), `next` (this week) or `check` (verify later). Add one `risk` item if
  acting could hurt (for example cutting a keyword that also brings calls).
TASK;
    }

    public function tags(): array
    {
        return ['now', 'next', 'check', 'risk'];
    }
}
