<?php

namespace App\Ai\Agents\Content;

use App\Ai\Agents\SiteFixes\SiteFixAgent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Stringable;

/** ADR-076: localizes (not translates word for word) one article into another language of the site. */
final class ContentLocalizerAgent extends SiteFixAgent
{
    public const string PROMPT_VERSION = 'content-localize-v1';

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
You localize a web article of a local business into `target_language` (ISO code) for people who search in that
language. INPUT_JSON has the source article (title, slug, meta title / description, focus keyword, excerpt, HTML body,
categories), `source_language`, `target_language`, business facts, the sector compliance rules, `link_map` (source URL →
the same page in the target language) and `language_home`.
- Localize, do not translate literally: natural phrasing, the terms people really search for in that language, units
  and conventions of the target audience. Keep every fact (names, services, addresses, phone numbers); never invent new
  facts, prices, credentials, guarantees or claims.
- Follow every rule in `compliance`. In health texts never use superlatives, guarantees, "painless / comfortable / fast /
  safe" promises, prices or discounts (e.g. English: best, painless, guarantee, safe, comfortable, fast, price, expert).
- If `violations` is present, the previous version broke those rules: write it again so none of those phrases (or any
  variant) appear.
- `html`: same structure as the source (<h2>, <h3>, <p>, <ul>, <li>, <strong>, <a>); no <h1>, styles or scripts. Links:
  use `link_map` for a source URL, otherwise link to `language_home`; keep external links.
- `slug`: short, lowercase, hyphenated, in the target language (ASCII). `meta_title` at most 60 characters,
  `meta_description` at most 155. `categories`: the same categories in the target language, same order.
INSTRUCTIONS;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required(),
            'slug' => $schema->string()->required(),
            'meta_title' => $schema->string()->required(),
            'meta_description' => $schema->string()->required(),
            'focus_keyword' => $schema->string()->required(),
            'excerpt' => $schema->string()->required(),
            'html' => $schema->string()->required(),
            'categories' => $schema->array()->items($schema->string())->required(),
        ];
    }
}
