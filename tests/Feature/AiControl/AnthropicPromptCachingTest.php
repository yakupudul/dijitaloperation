<?php

namespace Tests\Feature\AiControl;

use App\Ai\Gateway\CachingAnthropicGateway;
use App\Support\Ai\AiDefaultSteps;
use Laravel\Ai\AiManager;
use Laravel\Ai\Providers\Provider;
use Tests\TestCase;

/**
 * API tasarrufu (yakup, 2026-10-14): Claude calls send the system prompt as a cached block, and the fallback steps use
 * the current, cheaper Claude models (Haiku 5.5 instead of Haiku 4.5, Sonnet 5.5 instead of Sonnet 5).
 */
final class AnthropicPromptCachingTest extends TestCase
{
    public function test_claude_requests_mark_the_system_prompt_as_cached(): void
    {
        /** @var Provider $provider */
        $provider = app(AiManager::class)->textProvider('anthropic');
        $gateway = $provider->textGateway();
        $this->assertInstanceOf(CachingAnthropicGateway::class, $gateway);

        $body = (fn (): array => $this->buildTextRequestBody($provider, 'claude-haiku-5-5', 'Uzun talimat', [], [], null, null))->call($gateway);

        $this->assertSame([['type' => 'text', 'text' => 'Uzun talimat', 'cache_control' => ['type' => 'ephemeral']]], $body['system']);

        $empty = (fn (): array => $this->buildTextRequestBody($provider, 'claude-haiku-5-5', null, [], [], null, null))->call($gateway);
        $this->assertArrayNotHasKey('system', $empty);
    }

    public function test_fallback_steps_use_the_current_claude_models(): void
    {
        $this->assertSame('claude-haiku-5-5', AiDefaultSteps::classification()[0]['model']);
        $this->assertSame('claude-haiku-5-5', AiDefaultSteps::publicData()[1]['model']);
    }
}
