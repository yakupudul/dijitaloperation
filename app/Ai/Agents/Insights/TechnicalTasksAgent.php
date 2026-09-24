<?php

namespace App\Ai\Agents\Insights;

/** Turns the website crawl findings into a prioritised task list a developer can work through. */
final class TechnicalTasksAgent extends InsightAgent
{
    protected function task(): string
    {
        return <<<'TASK'
You turn automated website checks into a to-do list for the site's developer. INPUT_JSON has the crawl summary,
the grouped observations (code, severity, how many pages, example URLs), server / TLS facts and real-user speed.
Merge observations that have one fix (for example one template change fixes 40 missing titles).
- `summary`: the state of the site and the order of work, in plain language for the business owner.
- `items`: one task each. `title` = what to do (imperative, specific). `detail` = which pages (an example URL), why
  it matters (visitors, Google, conversions) and how to verify it is fixed. Tag `high` (breaks visits, indexing or
  tracking), `medium` (hurts rankings or conversions) or `low` (polish). Skip observations that are informational.
TASK;
    }

    public function tags(): array
    {
        return ['high', 'medium', 'low'];
    }
}
