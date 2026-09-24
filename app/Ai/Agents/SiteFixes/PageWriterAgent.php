<?php

namespace App\Ai\Agents\SiteFixes;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Stringable;

/** Writes the new version of a thin page, or a new service / location page from an SEO brief. */
final class PageWriterAgent extends SiteFixAgent
{
    public const string PROMPT_VERSION = 'site-fix-page-v1';

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
You write web page content in Turkish for a local business. INPUT_JSON has `mode` ("rewrite" an existing page or
"new" page from a brief), the business facts, the current page text (rewrite) or the brief (new), the searches the
page should answer and the sector compliance rules.
- Write for people first: what the service is, who it is for, how it works, why this business, where it serves, a
  short FAQ (3–5 questions) and a clear way to call / book. 500–900 words.
- Rewrite mode: keep every true fact of the current text (names, services, prices, addresses); improve and extend,
  never remove real information and never invent new facts, prices, credentials or guarantees.
- Follow every rule in `compliance` (no guarantees, no superlatives, no treatment promises when listed).
- `html`: clean HTML only with <h2>, <h3>, <p>, <ul>, <li>, <strong>, <a> (no <h1>, no inline styles, no scripts).
- `title`: the page title. `summary`: 2 sentences on what changed / what the page covers.
INSTRUCTIONS;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required(),
            'html' => $schema->string()->required(),
            'summary' => $schema->string()->required(),
        ];
    }
}
