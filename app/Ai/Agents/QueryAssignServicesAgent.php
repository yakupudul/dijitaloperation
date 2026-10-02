<?php

namespace App\Ai\Agents;

use App\Ai\Concerns\UsesPromptRegistry;
use App\Ai\Contracts\RegistryPrompted;
use App\Support\Ai\AiProviderOptions;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * Sorgular "AI ile hizmet öner" (operation `queries.assign_services`): one call per batch of unassigned queries of ONE
 * sector proposes an existing service of that sector (or none) per query and optional new matching keywords. Proposal
 * only; the operator approves the checklist.
 */
final class QueryAssignServicesAgent implements Agent, HasProviderOptions, HasStructuredOutput, RegistryPrompted
{
    use Promptable;
    use UsesPromptRegistry;

    public const string OPERATION = AiRouteKeys::QUERIES_ASSIGN_SERVICES;

    public const string PROMPT_VERSION = 'queries-assign-services-v1';

    public function promptOperation(): string
    {
        return self::OPERATION;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'assignments' => $schema->array()->items($schema->object(fn (JsonSchema $r): array => [
                'query_id' => $r->integer()->required(),
                'service_id' => $r->integer()->nullable()->required(),
                'reason' => $r->string()->required(),
            ]))->required(),
            'keywords' => $schema->array()->items($schema->object(fn (JsonSchema $r): array => [
                'service_id' => $r->integer()->required(),
                'keyword' => $r->string()->required(),
                'reason' => $r->string()->required(),
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
