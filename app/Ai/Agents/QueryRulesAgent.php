<?php

namespace App\Ai\Agents;

use App\Ai\Concerns\UsesPromptRegistry;
use App\Ai\Contracts\RegistryPrompted;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * Sorgular "AI ile kural üret" (operation `queries.filter_rules`): ONE call over the operator's selected queries
 * proposes (a) negative filter terms (a query containing one is deleted) and (b) new matching keywords per service. Every proposal is
 * checked against the selected queries, the sectors and the services given before the operator sees it.
 */
final class QueryRulesAgent implements Agent, HasProviderOptions, HasStructuredOutput, RegistryPrompted
{
    use Promptable;
    use UsesPromptRegistry;

    public const string OPERATION = AiRouteKeys::QUERIES_FILTER_RULES;

    public const string PROMPT_VERSION = 'queries-filter-rules-v2';

    public function promptOperation(): string
    {
        return self::OPERATION;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'filter_terms' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'term' => $row->string()->required(),
                'sector_id' => $row->integer()->nullable()->required(),
                'reason' => $row->string()->required(),
            ]))->required(),
            'keywords' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'service_id' => $row->integer()->required(),
                'keyword' => $row->string()->required(),
                'reason' => $row->string()->required(),
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
