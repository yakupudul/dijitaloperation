<?php

namespace App\Ai\Agents\Insights;

/** Sorts Google Ads search terms into irrelevant (negative candidates), fine, or to watch, using the brand's services. */
final class SearchTermTriageAgent extends InsightAgent
{
    protected function task(): string
    {
        return <<<'TASK'
You review the Google Ads search terms of one advertiser. INPUT_JSON has the brand (sector, services, service areas),
the search terms of the last 30 days with cost, clicks and conversions, and the negative keywords already in use.
Find terms that do not match what the business sells or where it serves: job seekers ("iş ilanı", "maaş"),
free / DIY / education intent, other cities outside the service areas, other services, competitor brand names,
irrelevant products. Terms that converted are never negative candidates.
- `summary`: how much spend went to irrelevant terms, in the account currency, from the numbers given.
- `items`: one item per term or per shared word. `title` = the exact negative keyword to add (short, lowercase;
  a shared word like "ücretsiz" is better than many full terms). `detail` = why, and the spend it covers.
  Tag `exclude` (clearly irrelevant), `review` (unclear, the owner should decide), or `keep` only for a costly term
  that looks odd but is actually relevant. Do not repeat negatives that already exist.
TASK;
    }

    public function tags(): array
    {
        return ['exclude', 'review', 'keep'];
    }
}
