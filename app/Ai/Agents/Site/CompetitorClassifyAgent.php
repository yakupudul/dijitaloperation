<?php

namespace App\Ai\Agents\Site;

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
 * Rakipler (operation `competitors.classify`): ONE call per batch places the SERP result domains the rules could not
 * (own site / known directory / news / information list) into ticari rakip · bilgi rakibi · dizin · haber.
 */
final class CompetitorClassifyAgent implements Agent, HasProviderOptions, HasStructuredOutput, RegistryPrompted
{
    use Promptable;
    use UsesPromptRegistry;

    public const string OPERATION = AiRouteKeys::COMPETITORS_CLASSIFY;

    public const string PROMPT_VERSION = 'competitors-classify-v1';

    public const array CLASSES = ['ticari', 'bilgi', 'dizin', 'haber'];

    public function promptOperation(): string
    {
        return self::OPERATION;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'domains' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'domain' => $row->string()->required(),
                'class' => $row->string()->enum(self::CLASSES)->required(),
                'reason' => $row->string()->required(),
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
