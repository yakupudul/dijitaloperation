<?php

namespace App\Ai\Agents\Content;

use App\Ai\Agents\SiteFixes\SiteFixAgent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Stringable;

/** Faz 4: writes one blog article / page of the content studio from an idea (SEO fields, HTML body, FAQ, links). */
final class ArticleWriterAgent extends SiteFixAgent
{
    public const string PROMPT_VERSION = 'content-article-v1';

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
You write one web article in `language` (Turkish unless told otherwise) for a local business. INPUT_JSON has the
business facts, the idea (title, focus keyword, the searches it must answer, page type, H2/H3 outline, FAQ questions),
`internal_links` (real URLs of the site with their titles), the site's categories, the sector compliance rules,
`words` (min / max), and `disclaimer` (a closing note, may be null).
- Write for people first: clear, accurate, useful, in a calm professional tone. Never invent facts, prices, statistics,
  credentials, guarantees or results. Do not name competitors. No first-person medical promises.
- Length: between words.min and words.max words of body text. Use the outline: <h2> sections with <h3> where useful,
  short paragraphs, at least one <ul> or <ol> list. Put the focus keyword naturally in the first paragraph.
- FAQ: end the body with <h2>Sık sorulan sorular</h2> (in the article language) and 3–4 questions as <h3> each followed
  by a short <p> answer; use the given FAQ questions when they fit.
- Internal links: link 2–3 of `internal_links` in the text with natural anchors, using the exact `url` values. Never
  link any other URL of the site and never invent URLs. External links only to authoritative sources, rarely.
- Follow every rule in `compliance` (health: no superlatives, guarantees, "painless / comfortable / fast / safe"
  promises, prices or discounts). If `violations` is present, the previous version broke those rules with the listed
  phrases: write it again so none of those phrases (or any variant or suffixed form) appear anywhere.
- If `disclaimer` is given, end the body with it as the last paragraph: <p><em>…</em></p>.
- `html`: clean HTML only with <h2>, <h3>, <p>, <ul>, <ol>, <li>, <strong>, <em>, <a href> (no <h1>, styles, scripts).
- `meta_title` at most 60 characters with the focus keyword near the start; `meta_description` at most 155 characters;
  `slug` short, lowercase, hyphenated ASCII; `excerpt` 1–2 sentences; `categories`: 1–2 names, preferably from the
  site's categories; `title`: the article title (H1) — may differ slightly from the idea title.
INSTRUCTIONS;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required(),
            'meta_title' => $schema->string()->required(),
            'meta_description' => $schema->string()->required(),
            'focus_keyword' => $schema->string()->required(),
            'slug' => $schema->string()->required(),
            'excerpt' => $schema->string()->required(),
            'html' => $schema->string()->required(),
            'categories' => $schema->array()->items($schema->string())->required(),
        ];
    }
}
