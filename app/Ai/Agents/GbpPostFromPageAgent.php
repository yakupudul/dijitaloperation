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
 * İşletme Profili "Siteden paylaş" (operation `gbp.post_from_page`): one post from one page of the brand's site. The
 * call-to-action link is the page URL, set by code; the text is checked (length, no links / phones, compliance) by
 * GbpAssistant. Publishing needs Admin approval (ADR-073).
 */
final class GbpPostFromPageAgent implements Agent, HasProviderOptions, HasStructuredOutput, RegistryPrompted
{
    use Promptable;
    use UsesPromptRegistry;

    public const string OPERATION = AiRouteKeys::GBP_POST_FROM_PAGE;

    public function promptOperation(): string
    {
        return self::OPERATION;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'text' => $schema->string()->required(),
            'action_type' => $schema->string()->enum(['LEARN_MORE', 'BOOK', 'CALL', 'SIGN_UP'])->required(),
        ];
    }

    /** @return array<string, mixed> */
    public function providerOptions(Lab|string $provider): array
    {
        $key = $provider instanceof Lab ? $provider->value : $provider;

        return AiProviderOptions::for((string) $key);
    }
}
