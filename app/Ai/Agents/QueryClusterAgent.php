<?php

namespace App\Ai\Agents;

use App\Ai\Concerns\UsesPromptRegistry;
use App\Ai\Contracts\RegistryPrompted;
use App\Models\Cluster;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * Sorgular "AI ile kümele" (operation `queries.cluster`): ONE call groups one service's queries into clusters — queries
 * that satisfy the same user need on the same page type. Query ids are checked against the input; new queries the model
 * adds are stored as "önerilen" without metrics.
 */
final class QueryClusterAgent implements Agent, HasProviderOptions, HasStructuredOutput, RegistryPrompted
{
    use Promptable;
    use UsesPromptRegistry;

    public const string OPERATION = AiRouteKeys::QUERIES_CLUSTER;

    public const string PROMPT_VERSION = 'queries-cluster-v2';

    public function promptOperation(): string
    {
        return self::OPERATION;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'clusters' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'name' => $row->string()->required(),
                'intent' => $row->string()->enum(Cluster::INTENTS)->required(),
                'user_need' => $row->string()->required(),
                'page_type' => $row->string()->enum(Cluster::PAGE_TYPES)->required(),
                'query_ids' => $row->array()->items($row->integer())->required(),
                'main_query_id' => $row->integer()->nullable()->required(),
                'representative_query_ids' => $row->array()->items($row->integer())->required(),
                'new_queries' => $row->array()->items($row->string())->required(),
                'subtopics' => $row->array()->items($row->string())->required(),
                'exclusions' => $row->array()->items($row->string())->required(),
                'reasoning' => $row->string()->required(),
            ]))->required(),
            'prompt_version' => $schema->string()->required(),
        ];
    }

    /** @return array<string, mixed> */
    public function providerOptions(Lab|string $provider): array
    {
        $key = $provider instanceof Lab ? $provider->value : $provider;

        return $key === Lab::OpenAI->value ? ['store' => false] : [];
    }
}
