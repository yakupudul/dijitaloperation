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
 * "Otomatik kur": reads a brand's own website/search data and proposes its services, matched to the
 * agency's service catalog where possible. Output is a proposal the operator approves.
 */
final class BrandSetupAgent implements Agent, HasProviderOptions, HasStructuredOutput, RegistryPrompted
{
    use Promptable;
    use UsesPromptRegistry;

    public const string PROMPT_VERSION = 'brand-setup-v4';

    public function promptOperation(): string
    {
        return AiRouteKeys::BRAND_SETUP;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'brand_summary' => $schema->string()->required(),
            'sector_code' => $schema->string()->nullable()->required(),
            'business_context' => $schema->object(fn (JsonSchema $context): array => [
                'business_summary' => $context->string()->nullable()->required(),
                'business_model' => $context->string()->nullable()->required(),
                'target_audiences' => $context->array()->items($context->string())->required(),
                'positioning' => $context->string()->nullable()->required(),
                'differentiators' => $context->array()->items($context->string())->required(),
            ])->required(),
            'services' => $schema->array()->items(
                $schema->object(fn (JsonSchema $item): array => [
                    'name' => $item->string()->required(),
                    'catalog_name' => $item->string()->nullable()->required(),
                    'sector_code' => $item->string()->nullable()->required(),
                    'aliases' => $item->array()->items($item->string())->required(),
                    'matching_phrases' => $item->array()->items($item->string())->required(),
                    'is_core' => $item->boolean()->required(),
                    'evidence' => $item->string()->required(),
                ])
            )->required(),
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
