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
 * Faz 2 "Hizmet keşfi": one call proposes a brand's services from its own service-page titles / H1s, matched to the
 * sector's service catalog when one fits. Page ids and catalog ids are validated against the input before display.
 */
final class BrandServiceAgent implements Agent, HasProviderOptions, HasStructuredOutput, RegistryPrompted
{
    use Promptable;
    use UsesPromptRegistry;

    public const string PROMPT_VERSION = 'brand-services-v1';

    public function promptOperation(): string
    {
        return AiRouteKeys::BRAND_SERVICES;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'services' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'name' => $row->string()->required(),
                'catalog_item_id' => $row->integer()->nullable()->required(),
                'page_ids' => $row->array()->items($row->integer())->required(),
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
