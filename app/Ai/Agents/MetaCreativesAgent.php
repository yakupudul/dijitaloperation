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
 * Meta "Kreatif öner" (operation `meta.creatives`): per main service, ad texts (primary text, headline, description),
 * a video hook and what the variant tests, from the account's own creatives and results. Service names, numbers and
 * URLs are checked against the data pack and the sector compliance gate by MetaAssistant.
 */
final class MetaCreativesAgent implements Agent, HasProviderOptions, HasStructuredOutput, RegistryPrompted
{
    use Promptable;
    use UsesPromptRegistry;

    public const string OPERATION = AiRouteKeys::META_CREATIVES;

    public function promptOperation(): string
    {
        return self::OPERATION;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'items' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'service' => $row->string()->required(),
                'angle' => $row->string()->required(),
                'primary_text' => $row->string()->required(),
                'headline' => $row->string()->required(),
                'description' => $row->string()->required(),
                'video_hook' => $row->string()->required(),
                'test' => $row->string()->required(),
            ]))->required(),
        ];
    }

    /** @return array<string, mixed> */
    public function providerOptions(Lab|string $provider): array
    {
        $key = $provider instanceof Lab ? $provider->value : $provider;

        return AiProviderOptions::for((string) $key);
    }
}
