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
 * Satış (yakup 2026-11-04: "önerdiğin mesajlar çok kötü, standart kalıptan çıkmış gibi"): the next WhatsApp message to
 * a prospect, written from the whole conversation, the site scan and the operator's note, with the rules and the
 * examples yakup approved (resources/prompts/sales-reply.md). Never a template; MoxDOP never sends it.
 */
class SalesReplyAgent implements Agent, HasProviderOptions, HasStructuredOutput, RegistryPrompted
{
    use Promptable;

    public const string VERSION = '1.0.0';

    public const string ROUTE = 'sales.reply';

    public function instructions(): Stringable|string
    {
        return (string) file_get_contents(resource_path('prompts/sales-reply.md'));
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
            'action' => $schema->string()->enum(['reply', 'wait'])->required(),
            'reply' => $schema->string()->required(),
            'send_on' => $schema->string()->required(),
            'note' => $schema->string()->required(),
            'summary' => $schema->string()->required(),
        ];
    }

    public function providerOptions(Lab|string $provider): array
    {
        return AiProviderOptions::for($provider instanceof Lab ? $provider->value : $provider);
    }
}
