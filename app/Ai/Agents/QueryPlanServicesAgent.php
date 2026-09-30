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
 * "AI ile planla" adım 2 (operation `queries.plan_services`): one call per used sector proposes missing
 * services and matching keyword fixes (add / remove / move). Proposal only; the operator ticks what to apply.
 */
final class QueryPlanServicesAgent implements Agent, HasProviderOptions, HasStructuredOutput, RegistryPrompted
{
    use Promptable;
    use UsesPromptRegistry;

    public const string OPERATION = AiRouteKeys::QUERIES_PLAN_SERVICES;

    public const string PROMPT_VERSION = 'queries-plan-services-v2';

    public function promptOperation(): string
    {
        return self::OPERATION;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'new_services' => $schema->array()->items($schema->object(fn (JsonSchema $r): array => [
                'sector_id' => $r->integer()->required(),
                'name' => $r->string()->required(),
                'keywords' => $r->array()->items($r->string())->required(),
                'reason' => $r->string()->required(),
            ]))->required(),
            'add_keywords' => $schema->array()->items($schema->object(fn (JsonSchema $r): array => [
                'service_id' => $r->integer()->required(),
                'keyword' => $r->string()->required(),
                'reason' => $r->string()->required(),
            ]))->required(),
            'remove_keywords' => $schema->array()->items($schema->object(fn (JsonSchema $r): array => [
                'keyword_id' => $r->integer()->required(),
                'reason' => $r->string()->required(),
            ]))->required(),
            'move_keywords' => $schema->array()->items($schema->object(fn (JsonSchema $r): array => [
                'keyword_id' => $r->integer()->required(),
                'to_service_id' => $r->integer()->required(),
                'reason' => $r->string()->required(),
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
