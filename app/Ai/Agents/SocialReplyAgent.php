<?php

namespace App\Ai\Agents;

use App\Ai\Contracts\RegistryPrompted;
use App\Support\Ai\AiProviderOptions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Sosyal medya gelen kutusu (ADR-083, phase 2): one reply to a direct message or a comment on the agency's own
 * Instagram / Facebook (resources/prompts/social-reply.md). The Admin reads it and sends it with one click.
 */
class SocialReplyAgent implements Agent, HasProviderOptions, HasStructuredOutput, RegistryPrompted
{
    use Promptable;

    public const string VERSION = '1.0.0';

    public const string ROUTE = 'social.reply';

    public function instructions(): Stringable|string
    {
        return (string) file_get_contents(resource_path('prompts/social-reply.md'));
    }

    public function promptOperation(): string
    {
        return self::ROUTE;
    }

    public function promptVersionId(): ?int
    {
        return null;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['reply', 'ignore'])->required(),
            'reply' => $schema->string()->required(),
            'lead' => $schema->boolean()->required(),
            'note' => $schema->string()->required(),
        ];
    }

    public function providerOptions(Lab|string $provider): array
    {
        return AiProviderOptions::for($provider instanceof Lab ? $provider->value : $provider);
    }
}
