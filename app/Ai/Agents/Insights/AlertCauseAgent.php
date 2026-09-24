<?php

namespace App\Ai\Agents\Insights;

/** Finds the most likely cause of one alert from the brand's daily numbers across channels. */
final class AlertCauseAgent extends InsightAgent
{
    protected function task(): string
    {
        return <<<'TASK'
You investigate one alert of a client (for example "site conversions dropped"). INPUT_JSON has the alert, the
daily series of the last 42 days for the brand's channels (website sessions and conversions, Google search clicks,
Google Ads spend / clicks / conversions, Meta spend / clicks), recent uptime failures, speed measurements, notes
the team wrote on the charts (campaign changes, site changes, holidays) and other open alerts.
Compare the days when the drop started with what changed at the same time in the other series.
- `summary`: the most likely cause in one sentence, and how sure you are (say "kesin değil" when the data does not
  show it).
- `items`: candidate causes, most likely first. `detail` = which numbers support or contradict it, and what to check
  to confirm. Tag `likely`, `possible`, or `ruled_out` (a cause the data rules out, so nobody wastes time on it).
TASK;
    }

    public function tags(): array
    {
        return ['likely', 'possible', 'ruled_out'];
    }
}
