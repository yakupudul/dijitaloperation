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
 * Drafts the business owner's public reply to one Google review (Faz 14). Copy-paste only: nothing is posted.
 * The reviewer's name is never sent; sector compliance notes and the owner's liked earlier replies are context.
 */
final class ReviewReplyAgent implements Agent, HasProviderOptions, HasStructuredOutput, RegistryPrompted
{
    use Promptable;
    use UsesPromptRegistry;

    public function promptOperation(): string
    {
        return AiRouteKeys::GBP_REVIEW_REPLY;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'reply' => $schema->string()->required(),
            'tone' => $schema->string()->enum(['thanks', 'apology', 'neutral'])->required(),
        ];
    }

    /** @return array<string, mixed> */
    public function providerOptions(Lab|string $provider): array
    {
        $key = $provider instanceof Lab ? $provider->value : $provider;

        return $key === Lab::OpenAI->value ? ['store' => false] : [];
    }
}
