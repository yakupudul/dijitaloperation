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
 * A failed provider call now says why, and inside a job the Claude (MCP) queue takes the work over instead of failing.
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

    public function test_inside_a_job_the_claude_queue_takes_the_work_over(): void
    {
        config(['moxdop-mcp.token' => 'test-mcp-token']);
        $tasks = app(AiTaskQueue::class);
        $tasks->begin(new RunSiteOperationJob((int) $this->site->id, SiteOperations::WEEKLY_CONTENT, []), (int) $this->brand->id, 'panorama.com.tr');

        $result = app(SiteAi::class)->run(new WeeklyContentAgent, ['candidates' => []], 240, 'weekly');
        $tasks->settle();

        $this->assertSame('queued', $result['status']);
        $this->assertSame(1, AiTask::query()->where('operation', 'site.weekly_content')->where('status', AiTask::PENDING)->count());
    }
}
