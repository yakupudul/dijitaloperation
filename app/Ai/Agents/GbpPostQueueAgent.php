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
 * Otomatik İşletme Profili gönderileri (operation `gbp.post_queue`, ADR-078): one post per given slot (a page of the
 * brand's site and an angle), grounded in that page only. Texts are checked by GbpPostQueue (length, contact data,
 * sector compliance, similarity to recent posts) before they wait for the Admin's bulk approval.
 */
final class GbpPostQueueAgent implements Agent, HasProviderOptions, HasStructuredOutput, RegistryPrompted
{
    use Promptable;
    use UsesPromptRegistry;

    public const string OPERATION = AiRouteKeys::GBP_POST_QUEUE;

    public function promptOperation(): string
    {
        return self::OPERATION;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'posts' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'slot' => $row->integer()->required(),
                'text' => $row->string()->required(),
                'action_type' => $row->string()->enum(['LEARN_MORE', 'BOOK', 'CALL'])->required(),
            ]))->required(),
        ];
    }

    /** @return array<string, mixed> */
    public function providerOptions(Lab|string $provider): array
    {
        $key = $provider instanceof Lab ? $provider->value : $provider;

        return AiProviderOptions::for((string) $key);
    }
}
