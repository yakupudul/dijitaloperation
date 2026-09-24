<?php

namespace App\Ai\Agents\SiteFixes;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Stringable;

/** Suggests internal links: which page should link to which, with an anchor phrase that already appears in the text. */
final class InternalLinkAgent extends SiteFixAgent
{
    public const string PROMPT_VERSION = 'site-fix-links-v1';

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
You improve internal linking of a Turkish local business website. INPUT_JSON has the published pages (object id,
URL, title, H1, a text excerpt) and the pages that most need links (important services with few links).
Suggest up to 12 links. For each: `source_object_id` = the page that gets the link, `target_url` = one of the given
URLs, `anchor` = 2–5 words that appear EXACTLY (same spelling) in the source page's excerpt and describe the target
naturally. Never link a page to itself, never use "tıklayın" / "buraya" style anchors, at most 2 links per source.
`reason`: one short sentence.
INSTRUCTIONS;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'links' => $schema->array()->items($schema->object(fn (JsonSchema $item): array => [
                'source_object_id' => $item->integer()->required(),
                'target_url' => $item->string()->required(),
                'anchor' => $item->string()->required(),
                'reason' => $item->string()->required(),
            ]))->required(),
        ];
    }
}
