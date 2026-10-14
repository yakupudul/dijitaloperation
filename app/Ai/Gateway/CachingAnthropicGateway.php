<?php

namespace App\Ai\Gateway;

use Laravel\Ai\Gateway\Anthropic\AnthropicGateway;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Providers\Provider;

/**
 * Anthropic gateway with prompt caching (yakup, 2026-10-14 "API tasarrufu"): the system prompt (operator-approved
 * instructions, brand rules) is sent as a cached block, so repeated calls of one operation read it at about a tenth
 * of the input price. Prompts under the model's minimum cacheable length are simply not cached (no extra cost).
 */
class CachingAnthropicGateway extends AnthropicGateway
{
    /**
     * @param  array<int, mixed>  $messages
     * @param  array<int, mixed>  $tools
     * @param  array<string, mixed>|null  $schema
     * @return array<string, mixed>
     */
    protected function buildTextRequestBody(
        Provider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
    ): array {
        $body = parent::buildTextRequestBody($provider, $model, $instructions, $messages, $tools, $schema, $options);

        if (is_string($body['system'] ?? null) && $body['system'] !== '') {
            $body['system'] = [['type' => 'text', 'text' => $body['system'], 'cache_control' => ['type' => 'ephemeral']]];
        }

        return $body;
    }
}
