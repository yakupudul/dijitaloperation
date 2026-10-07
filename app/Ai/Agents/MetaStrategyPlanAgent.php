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
 * Strateji öner (operation `meta.strategy_plan`): a Meta campaign plan draft for one brand service, written from the
 * rule-built recipe of the winning campaigns of other brands. Names, structure and ad texts only; every number (budget,
 * expected cost) comes from the rules. Lands in the brand's Meta Yapılacaklar as a suggestion; nothing goes to Meta.
 */
final class MetaStrategyPlanAgent implements Agent, HasProviderOptions, HasStructuredOutput, RegistryPrompted
{
    use Promptable;
    use UsesPromptRegistry;

    public const string OPERATION = AiRouteKeys::META_STRATEGY_PLAN;

    public function promptOperation(): string
    {
        return self::OPERATION;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->string()->required(),
            'campaign_name' => $schema->string()->required(),
            'structure' => $schema->string()->required(),
            'adsets' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'name' => $row->string()->required(),
                'audience' => $row->string()->required(),
            ]))->required(),
            'ads' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'angle' => $row->string()->required(),
                'headline' => $row->string()->required(),
                'primary_text' => $row->string()->required(),
            ]))->required(),
            'watch' => $schema->array()->items($schema->string())->required(),
        ];
    }

    /** @return array<string, mixed> */
    public function providerOptions(Lab|string $provider): array
    {
        $key = $provider instanceof Lab ? $provider->value : $provider;

        return AiProviderOptions::for((string) $key);
    }
}
