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
 * Sorgular "AI ile kümele", last step (operation `queries.cluster_review`): the service's clusters are reviewed once —
 * clusters one page would cover are merged (never from a locked one), unclear names / needs of unlocked clusters are
 * rewritten. Ids are checked against the input.
 */
final class QueryClusterReviewAgent implements Agent, HasProviderOptions, HasStructuredOutput, RegistryPrompted
{
    use Promptable;
    use UsesPromptRegistry;

    public const string OPERATION = AiRouteKeys::QUERIES_CLUSTER_REVIEW;

    public const string PROMPT_VERSION = 'queries-cluster-review-v1';

    public function promptOperation(): string
    {
        return self::OPERATION;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'merges' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'into_id' => $row->integer()->required(),
                'from_ids' => $row->array()->items($row->integer())->required(),
            ]))->required(),
            'updates' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'id' => $row->integer()->required(),
                'name' => $row->string()->required(),
                'intent' => $row->string()->enum(Cluster::INTENTS)->required(),
                'page_type' => $row->string()->enum(Cluster::PAGE_TYPES)->required(),
                'user_need' => $row->string()->required(),
                'subtopics' => $row->array()->items($row->string())->required(),
                'exclusions' => $row->array()->items($row->string())->required(),
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
