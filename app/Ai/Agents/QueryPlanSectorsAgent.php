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
 * "AI ile planla" adım 1 (operation `queries.plan_sectors`): batched calls (25 brands each) assign a sector to every brand without
 * one and proposes asset overrides only when an asset's signal clearly differs. Proposal only.
 */
final class QueryPlanSectorsAgent implements Agent, HasProviderOptions, HasStructuredOutput, RegistryPrompted
{
    use Promptable;
    use UsesPromptRegistry;

    public const string OPERATION = AiRouteKeys::QUERIES_PLAN_SECTORS;

    public const string PROMPT_VERSION = 'queries-plan-sectors-v2';

    public function promptOperation(): string
    {
        return self::OPERATION;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        $row = fn (string $id): mixed => $schema->array()->items($schema->object(fn (JsonSchema $r): array => [
            $id => $r->integer()->required(),
            'sector_id' => $r->integer()->nullable()->required(),
            'new_sector' => $r->string()->nullable()->required(),
            'reason' => $r->string()->required(),
        ]))->required();

        return [
            'brands' => $row('brand_id'),
            'assets' => $row('asset_id'),
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
