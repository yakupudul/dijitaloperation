<?php

namespace App\Ai\Agents\Insights;

use App\Ai\Concerns\UsesPromptRegistry;
use App\Ai\Contracts\RegistryPrompted;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * Shared shape of the on-click AI insights: a short summary plus a list of items (title, detail, tag). The
 * subclass names its AI operation (prompt: PromptRegistry) and the allowed tags; the input is always INPUT_JSON.
 */
abstract class InsightAgent implements Agent, HasProviderOptions, HasStructuredOutput, RegistryPrompted
{
    use Promptable;
    use UsesPromptRegistry;

    /** @return list<string> */
    abstract public function tags(): array;

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->string()->required(),
            'items' => $schema->array()->items($schema->object(fn (JsonSchema $item): array => [
                'title' => $item->string()->required(),
                'detail' => $item->string()->required(),
                'tag' => $item->string()->enum($this->tags())->required(),
            ]))->required(),
        ];
    }

    /** @return array<string, mixed> */
    public function providerOptions(Lab|string $provider): array
    {
        $key = $provider instanceof Lab ? $provider->value : $provider;

        return $key === Lab::OpenAI->value ? ['store' => false] : [];
    }
}
