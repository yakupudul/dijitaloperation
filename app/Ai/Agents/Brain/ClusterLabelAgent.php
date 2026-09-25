<?php

namespace App\Ai\Agents\Brain;

use Illuminate\Contracts\JsonSchema\JsonSchema;

/** Brain: names the page-sized query clusters of one service and checks their page type and intent. */
final class ClusterLabelAgent extends BrainAgent
{
    public const array PAGE_TYPES = ['main', 'landing', 'support', 'faq'];

    protected function task(): string
    {
        return <<<'TASK'
A service's search queries were grouped by a similarity algorithm into clusters; each cluster should be answerable
by ONE web page. INPUT_JSON has the `service` name and `clusters` (key, the most searched query, sample queries,
the algorithm's intent and page type guess).
For every cluster return: `key` (unchanged), `name` = a short Turkish page topic (2–5 words, like a page H1 topic, no
brand, no city), `page_type`:
- main: the service's main page (what it is, who it is for, process, why here) — only ONE cluster may be main
- landing: a separate buying page for a sub-service or specific offer
- support: an article that answers a learning question in depth
- faq: small questions that belong as FAQ entries on the main page
`intent` (transactional, local, commercial, informational, navigational) and `reason`. Keep the algorithm's guess
unless the queries clearly say otherwise.
TASK;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'items' => $schema->array()->items($schema->object(fn (JsonSchema $item): array => [
                'key' => $item->string()->required(),
                'name' => $item->string()->required(),
                'page_type' => $item->string()->enum(self::PAGE_TYPES)->required(),
                'intent' => $item->string()->enum(QueryServiceClassifierAgent::INTENTS)->required(),
                'reason' => $item->string()->required(),
            ]))->required(),
        ];
    }
}
