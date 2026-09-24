<?php

namespace App\Ai\Agents\Insights;

/** Reads Meta campaign / ad set / ad names with country × city results to say which service, area and audience converted. */
final class MetaGeoAgent extends InsightAgent
{
    protected function task(): string
    {
        return <<<'TASK'
You analyse the Meta (Facebook / Instagram) ads of one advertiser. INPUT_JSON has the brand (sector, services, areas),
the last 90 days by country, `rows` = campaign / ad set / ad × country × city with spend, clicks and results
(leads, purchases, purchase_value, messages), and `adset_targeting` (age, gender, interests, custom audiences,
targeted cities) per ad set.
Infer the SERVICE from the campaign, ad set and ad names (e.g. "Implant – Kadıköy – 35+" → service implant) and the
brand's services; infer the AUDIENCE from the ad set name and its targeting. Then say, from the numbers only:
which service × city × audience brought results and at what cost per result, where money was spent with no result,
and what to try next. A "result" is a lead, purchase or message. Never invent numbers; say "veri az" when a
combination has too little spend to judge.
- `summary`: 2-3 sentences: the best service × city × audience and its cost per result, the biggest waste, the total picture.
- `items`: up to 10. `title` = "Hizmet · Şehir · Kitle" with the result count and cost per result
  (e.g. "İmplant · İstanbul · 35-55 kadın — 14 lead, 210 TL/lead"). `detail` = why and what to do (raise budget,
  exclude the city, split the ad set, new creative for that service…). Tag `winner`, `waste` or `test`.
Write in Turkish.
TASK;
    }

    public function tags(): array
    {
        return ['winner', 'waste', 'test'];
    }
}
