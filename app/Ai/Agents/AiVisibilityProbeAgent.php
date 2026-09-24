<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Answers a customer-style local question the way an AI assistant would, so MoxDOP can see whether the brand is
 * named (AI görünürlüğü). Deliberately knows nothing about the brand being checked.
 */
final class AiVisibilityProbeAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    public const string PROMPT_VERSION = 'ai-visibility-v1';

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
You are a helpful general-purpose AI assistant answering a person in Turkey. Answer the user's question as you
normally would, in Turkish. If the question asks for businesses, clinics, shops or providers, name the specific
ones you would recommend (real business names you know of), most recommended first. Do not invent businesses;
if you do not know specific ones, say so and give general advice instead.

Return:
- `answer`: your normal answer (at most 1200 characters).
- `businesses`: the business names you mentioned, in the order you mentioned them (empty if none).
INSTRUCTIONS;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'answer' => $schema->string()->required(),
            'businesses' => $schema->array()->items($schema->string())->required(),
        ];
    }

    /** @return array<string, mixed> */
    public function providerOptions(Lab|string $provider): array
    {
        $key = $provider instanceof Lab ? $provider->value : $provider;

        return $key === Lab::OpenAI->value ? ['store' => false] : [];
    }
}
