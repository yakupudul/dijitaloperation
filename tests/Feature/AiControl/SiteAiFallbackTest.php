<?php

namespace Tests\Feature\AiControl;

use App\Ai\Agents\Site\WeeklyContentAgent;
use App\Jobs\Site\RunSiteOperationJob;
use App\Models\AiTask;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Site\SiteAi;
use App\Services\Site\SiteOperations;
use RuntimeException;
use Tests\Feature\Site\SiteTestCase;

/**
 * yakup, 2026-10-09 ("halen içerik fikirleri havuza dolmuyor"): every pool run said "AI yanıt vermedi" with no reason.
 * A failed provider call now says why. Content work never falls back to the Claude (MCP) queue (yakup, 2026-10-12).
 */
final class SiteAiFallbackTest extends SiteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enableAi();
        WeeklyContentAgent::fake(function (): array {
            throw new RuntimeException('Your credit balance is too low to access the Anthropic API.');
        });
    }

    public function test_a_failed_provider_call_says_why(): void
    {
        $result = app(SiteAi::class)->run(new WeeklyContentAgent, ['candidates' => []], 240, 'weekly');

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('kredisi bitti', $result['message']);
    }

    public function test_content_work_never_falls_back_to_the_claude_queue(): void
    {
        config(['moxdop-mcp.token' => 'test-mcp-token']);
        $tasks = app(AiTaskQueue::class);
        $tasks->begin(new RunSiteOperationJob((int) $this->site->id, SiteOperations::WEEKLY_CONTENT, []), (int) $this->brand->id, 'panorama.com.tr');

        $result = app(SiteAi::class)->run(new WeeklyContentAgent, ['candidates' => []], 240, 'weekly');
        $tasks->settle();

        // yakup, 2026-10-12: content ideas, writing and sending never wait in a queue; the error shows on the board instead.
        $this->assertSame('error', $result['status']);
        $this->assertSame(0, AiTask::query()->count());
    }
}
