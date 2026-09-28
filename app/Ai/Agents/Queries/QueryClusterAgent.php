<?php

namespace App\Ai\Agents\Queries;

use App\Ai\Agents\Brain\BrainAgent;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/** Sorgu hattı: groups one service's core queries into topic clusters and guesses each cluster's page type. */
final class QueryClusterAgent extends BrainAgent
{
    public const string PROMPT_VERSION = 'query-clusters-v1';

    public const array PAGE_TYPES = ['hizmet', 'blog', 'sss', 'karsilastirma'];

    protected function task(): string
    {
        return <<<'TASK'
Group the search queries of ONE service into topic clusters. INPUT_JSON has `sector`, `service`, `clusters` (existing
cluster names to reuse when a query fits) and `queries` (id, text, demand). Places and brand names were already
removed.
Rules:
- One cluster = one topic that ONE web page can fully answer: same intent, same user question. Do not mix buying
  queries ("implant fiyatı") with learning queries ("implant ağrılı mı") unless one page answers both naturally.
- Reuse an existing cluster name exactly when the topic is the same; otherwise name a new cluster (2–5 Turkish words,
  no brand, no city).
- Every query id goes to exactly one cluster. `head_query_id` = the most representative, most searched query.
- `page_type`: hizmet (service / sales page), blog (in-depth article), sss (short questions answered as FAQ),
  karsilastirma (X mi Y mi, differences, best-of comparisons).
Return `clusters` with name, head_query_id, query_ids, page_type and reason.
TASK;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'clusters' => $schema->array()->items($schema->object(fn (JsonSchema $item): array => [
                'name' => $item->string()->required(),
                'head_query_id' => $item->integer()->required(),
                'query_ids' => $item->array()->items($item->integer())->required(),
                'page_type' => $item->string()->enum(self::PAGE_TYPES)->required(),
                'reason' => $item->string()->required(),
            ]))->required(),
        ];
    }
}
