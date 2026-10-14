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
 * A failed provider call now says why. Content work falls back to the Claude (MCP) queue only on a used-up credit (yakup, 2026-10-14).
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

    public function test_content_work_goes_to_claude_only_when_the_credit_is_used_up(): void
    {
        config(['moxdop-mcp.token' => 'test-mcp-token']);
        $tasks = app(AiTaskQueue::class);

        // yakup, 2026-10-12: any other provider error shows on the board; the work never waits in a queue for it.
        WeeklyContentAgent::fake(function (): array {
            throw new RuntimeException('Rate limit reached for requests.');
        });
        $tasks->begin(new RunSiteOperationJob((int) $this->site->id, SiteOperations::WEEKLY_CONTENT, []), (int) $this->brand->id, 'panorama.com.tr');
        $result = app(SiteAi::class)->run(new WeeklyContentAgent, ['candidates' => []], 240, 'weekly');
        $tasks->settle();
        $this->assertSame('error', $result['status']);
        $this->assertSame(0, AiTask::query()->count());

        // yakup, 2026-10-14 ("Claude API bittiğinde sen devral"): a used-up credit hands it to Claude over MCP.
        WeeklyContentAgent::fake(function (): array {
            throw new RuntimeException('Your credit balance is too low to access the Anthropic API.');
        });
        $tasks->begin(new RunSiteOperationJob((int) $this->site->id, SiteOperations::WEEKLY_CONTENT, []), (int) $this->brand->id, 'panorama.com.tr');
        $result = app(SiteAi::class)->run(new WeeklyContentAgent, ['candidates' => []], 240, 'weekly');
        $tasks->settle();
        $this->assertSame('queued', $result['status']);
        $this->assertSame(1, AiTask::query()->count());
    }
}
