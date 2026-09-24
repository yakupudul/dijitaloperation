<?php

namespace App\Ai\Agents\SiteFixes;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Stringable;

/** Proposes the new value of each website fix (SEO title, meta description, alt text, schema, redirect target). */
final class SiteFixValuesAgent extends SiteFixAgent
{
    public const string PROMPT_VERSION = 'site-fix-values-v1';

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
You fix SEO problems on a Turkish local business website. INPUT_JSON has the business facts, the site's published
pages (for redirect targets) and a list of `items`, each with an id, type, page URL, current value, page title / H1
and the problem. Return one entry per item id with the new `value`:
- seo_title: 30–60 characters, the page's main service + place + brand when it fits, natural Turkish, no ALL CAPS,
  no keyword stuffing, different from other pages' titles.
- seo_description: 120–155 characters, what the page offers and a reason to click (no invented prices, discounts,
  guarantees or medical claims), may end with a soft call to action.
- alt_text: describe what the image most likely shows from its file name and page, max 120 characters; empty string
  if it looks decorative.
- schema: one JSON-LD object (as a JSON string) of the most specific schema.org business type, using ONLY the given
  business facts (name, address, phone, url, opening hours, geo). Never invent missing facts; omit them.
- redirect: the single best matching URL from `published_pages` (same topic / service); the home page only if
  nothing is close.
Follow every rule in `compliance`. Keep the brand's tone. `note`: one short sentence why.
INSTRUCTIONS;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'items' => $schema->array()->items($schema->object(fn (JsonSchema $item): array => [
                'id' => $item->integer()->required(),
                'value' => $item->string()->required(),
                'note' => $item->string()->required(),
            ]))->required(),
        ];
    }
}
