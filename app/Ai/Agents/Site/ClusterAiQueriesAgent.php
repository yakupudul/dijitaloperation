<?php

namespace App\Ai\Agents\Site;

use App\Support\Ai\AiRouteKeys;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/** `queries.ai_queries`: questions people ask AI assistants (ChatGPT, Gemini…) for each cluster of one service. */
final class ClusterAiQueriesAgent extends SiteAgent
{
    public function promptOperation(): string
    {
        return AiRouteKeys::QUERIES_AI_QUERIES;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'clusters' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'cluster_id' => $row->integer()->required(),
                'questions' => $row->array()->items($row->string())->required(),
            ]))->required(),
        ];
    }
}
