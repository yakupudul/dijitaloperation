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
 * Drafts one Google Business Profile post (update) on operator click. The draft goes into the post form; the operator
 * edits it, and publishing needs Admin approval (ADR-073).
 */
final class GbpPostAgent implements Agent, HasProviderOptions, HasStructuredOutput, RegistryPrompted
{
    use Promptable;
    use UsesPromptRegistry;

    public const string PROMPT_VERSION = 'gbp-post-v1';

    public function promptOperation(): string
    {
        return AiRouteKeys::GBP_POST_DRAFT;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required(),
            'body' => $schema->string()->required(),
            'action_type' => $schema->string()->enum(['LEARN_MORE', 'BOOK', 'CALL', 'ORDER', 'SIGN_UP'])->required(),
            'service' => $schema->string()->required(),
        ];
    }

    /** @return array<string, mixed> */
    public function providerOptions(Lab|string $provider): array
    {
        $key = $provider instanceof Lab ? $provider->value : $provider;

        return $key === Lab::OpenAI->value ? ['store' => false] : [];
    }
}
