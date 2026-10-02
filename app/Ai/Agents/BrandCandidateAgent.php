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
 * Faz 2 "Keşfedilen varlıklar": one call per batch — groups the discovered accounts the deterministic signals could
 * not place, and proposes each brand candidate's sector from its most reliable signal. Output is validated against the
 * batch (unknown keys / sectors are dropped) and only proposed; the operator approves.
 */
final class BrandCandidateAgent implements Agent, HasProviderOptions, HasStructuredOutput, RegistryPrompted
{
    use Promptable;
    use UsesPromptRegistry;

    public const string PROMPT_VERSION = 'brand-candidates-v1';

    public function promptOperation(): string
    {
        return AiRouteKeys::BRAND_CANDIDATES;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'groups' => $schema->array()->items($schema->object(fn (JsonSchema $group): array => [
                'candidate_key' => $group->string()->nullable()->required(),
                'name' => $group->string()->required(),
                'account_keys' => $group->array()->items($group->string())->required(),
            ]))->required(),
            'sectors' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'key' => $row->string()->required(),
                'sector_code' => $row->string()->nullable()->required(),
                'signal' => $row->string()->enum(['gbp_category', 'site', 'ads'])->required(),
                'reason' => $row->string()->required(),
                'confidence' => $row->number()->required(),
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
