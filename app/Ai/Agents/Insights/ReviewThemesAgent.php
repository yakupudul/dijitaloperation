<?php

namespace App\Ai\Agents\Insights;

/** Themes in the brand's own and its competitors' reviews: what customers praise and complain about. */
final class ReviewThemesAgent extends InsightAgent
{
    protected function task(): string
    {
        return <<<'TASK'
You analyse customer reviews of a local business and its competitors. INPUT_JSON has, per profile (own business
first, then competitors), the rating, review count and recent review texts with their stars.
Group what people say into themes (waiting time, price, staff attitude, result quality, cleanliness, parking,
communication…). Compare the business with competitors.
- `summary`: the business's biggest strength and biggest weakness versus competitors.
- `items`: one per theme. `title` = the theme in 2–4 words; `detail` = how often and how strongly it appears, with
  a short paraphrase (never quote personal names or health details), and what to do. Tag `strength` (the business
  is praised for it), `complaint` (the business is criticised for it), or `competitor_edge` (competitors are
  praised for something the business is not).
TASK;
    }

    public function tags(): array
    {
        return ['complaint', 'competitor_edge', 'strength'];
    }
}
