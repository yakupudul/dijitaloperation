<?php

namespace App\Ai\Agents\Insights;

/** Scores one incoming request to the agency and suggests the first reply. */
final class LeadScoreAgent extends InsightAgent
{
    protected function task(): string
    {
        return <<<'TASK'
You help a digital marketing agency triage one incoming request (web form, Meta lead form, phone note).
INPUT_JSON has the request (company, message, source, campaign, page) and the agency's services.
- `summary`: how promising the request is and why, in 2 sentences.
- `items`: first the score item: `title` = "Sıcak", "Ilık" or "Soğuk", `detail` = the reason; tag it `hot`, `warm` or
  `cold`. Then up to 3 items tagged `ask`: questions to ask on the first call. Then one item tagged `reply`: a short,
  polite first WhatsApp / e-mail message in Turkish (no invented prices or promises).
Mark obvious spam or job applications as `cold` and say so.
TASK;
    }

    public function tags(): array
    {
        return ['hot', 'warm', 'cold', 'ask', 'reply'];
    }
}
