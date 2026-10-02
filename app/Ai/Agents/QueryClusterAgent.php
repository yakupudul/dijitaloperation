<?php

namespace App\Ai\Agents;

use App\Ai\Concerns\UsesPromptRegistry;
use App\Ai\Contracts\RegistryPrompted;
use App\Models\Cluster;
use App\Support\Ai\AiProviderOptions;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * Sorgular "AI ile kümele" (operation `queries.cluster`): one part of a service's topics (most searched first) goes
 * into clusters — topics that satisfy the same user need on the same page type. The first part builds the skeleton,
 * later parts join existing clusters (`existing_cluster_id`) or open new ones. Ids are checked against the input; new
 * queries the model adds are stored as "önerilen" without metrics.
 */
final class QueryClusterAgent implements Agent, HasProviderOptions, HasStructuredOutput, RegistryPrompted
{
    use Promptable;
    use UsesPromptRegistry;

    public const string OPERATION = AiRouteKeys::QUERIES_CLUSTER;

    public const string PROMPT_VERSION = 'queries-cluster-v5';

    public function promptOperation(): string
    {
        return self::OPERATION;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'clusters' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'existing_cluster_id' => $row->integer()->nullable()->required(),
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
            'skipped' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'id' => $row->integer()->required(),
                'reason' => $row->string()->enum(['other_service', 'not_relevant'])->required(),
                'service' => $row->string()->nullable()->required(),
            ]))->required(),
            'prompt_version' => $schema->string()->required(),
        ];
    }

    /** @return array<string, mixed> */
    public function providerOptions(Lab|string $provider): array
    {
        $key = $provider instanceof Lab ? $provider->value : $provider;

        return AiProviderOptions::for((string) $key);
    }
}
