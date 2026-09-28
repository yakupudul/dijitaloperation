<?php

namespace App\Ai\Agents\Content;

use App\Ai\Agents\SiteFixes\SiteFixAgent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Stringable;

/** Faz 4: proposes a batch of new article topics for chosen services, avoiding what the site already has. */
final class ContentIdeaAgent extends SiteFixAgent
{
    public const string PROMPT_VERSION = 'content-ideas-v1';

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
You plan blog articles in Turkish for a local business website. INPUT_JSON has the business facts, `services` (the
axes to write about), `count` (how many ideas), `demand` (real search queries and topic clusters per service),
`existing_titles` (everything the site already published or planned) and the sector compliance rules.
- Propose exactly `count` distinct ideas spread evenly over the services. Each idea answers a real question patients /
  customers ask; prefer topics backed by `demand`. Mix page types: "guide" (how-to / what is), "faq", "comparison".
- Never repeat or closely paraphrase an existing title, and no two ideas may overlap in intent.
- Titles: natural, specific, 40–70 characters, no clickbait, no superlatives, no prices, no guarantees; follow every
  rule in `compliance`.
- For each idea give: `service` (exactly one of the given service names), `title`, `focus_keyword` (a real search
  phrase), `page_type`, `target_queries` (2–5 search phrases), `outline` (4–6 H2 headings, each with 0–3 H3), `faq`
  (3–4 questions).
INSTRUCTIONS;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'ideas' => $schema->array()->items($schema->object(fn (JsonSchema $item): array => [
                'service' => $item->string()->required(),
                'title' => $item->string()->required(),
                'focus_keyword' => $item->string()->required(),
                'page_type' => $item->string()->enum(['guide', 'faq', 'comparison'])->required(),
                'target_queries' => $item->array()->items($item->string())->required(),
                'outline' => $item->array()->items($item->object(fn (JsonSchema $section): array => [
                    'h2' => $section->string()->required(),
                    'h3' => $section->array()->items($section->string())->required(),
                ]))->required(),
                'faq' => $item->array()->items($item->string())->required(),
            ]))->required(),
        ];
    }
}
