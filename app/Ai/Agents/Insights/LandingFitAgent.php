<?php

namespace App\Ai\Agents\Insights;

/** Checks whether the ads, keywords and landing pages of a Google Ads account tell the same story. */
final class LandingFitAgent extends InsightAgent
{
    protected function task(): string
    {
        return <<<'TASK'
You check message match between Google Ads and landing pages for one advertiser. INPUT_JSON has the landing pages
with spend, clicks and conversions, what each page says (title, description, headings, word count, whether a phone
number or form was seen) when the site was crawled, the top ad texts and the top keywords.
For each costly page decide: does the page answer what the ad and keyword promise (same service, same place, a clear
offer and a way to call / book)? Pages without crawled content: say the page could not be checked.
- `summary`: the overall fit and the one change with the biggest effect.
- `items`: one per page (title = the page path) or per cross-page issue; `detail` = what does not match and the fix.
  Tag `poor`, `partial` or `good`.
TASK;
    }

    public function tags(): array
    {
        return ['poor', 'partial', 'good'];
    }
}
