<?php

namespace Tests\Feature\Site;

use App\Ai\Agents\Site\ClusterGapsAgent;
use App\Ai\Agents\Site\ClusterMatchAgent;
use App\Ai\Agents\Site\ClusterPagesAgent;
use App\Ai\Agents\Site\PageCategoriesAgent;
use App\Ai\Agents\Site\PageSummaryAgent;
use App\Ai\Agents\Site\ServicePagesAgent;
use App\Jobs\Site\RunSiteOperationJob;
use App\Livewire\Operator\Website\V2\ContentIdeasTab;
use App\Mcp\Servers\MoxdopServer;
use App\Mcp\Tools\SubmitResult;
use App\Models\AiTask;
use App\Models\BrandClusterPage;
use App\Models\Cluster;
use App\Models\CoreConnection;
use App\Models\Page;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Prompts\PromptRegistry;
use App\Services\Site\ClusterAudit;
use App\Services\Site\SiteFlow;
use App\Services\Site\SiteOperations;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * Eşleştir waiting for Claude (MCP) is still under way however long Claude takes: the flow (nightly, after a setup,
 * after clustering) and the «Eşleştir» button never start a second one, a re-run finds the match answer by its group
 * although the pages moved, and the weekly refresh leaves the cluster rows to it.
 */
final class SiteFlowClaudeWaitTest extends SiteTestCase
{
    private Page $implantPage;

    private Cluster $treatment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableAi();
        config(['moxdop-mcp.token' => 'test-mcp-token']);
        CoreConnection::factory()->create(['digital_asset_id' => $this->site->id, 'type' => 'wordpress_connector', 'enabled' => true,
            'config' => ['pairing_state' => 'paired']]);
        $this->implantPage = $this->page('/implant/', 'İmplant Tedavisi', ['category' => 'hizmet', 'content_text' => 'İmplant tedavisi adım adım anlatılır.']);
        $this->treatment = $this->cluster($this->implant, 'İmplant tedavisi', ['implant tedavisi', 'implant nasıl yapılır']);
        Cluster::query()->update(['ai_queries' => '[]']);
        SiteOperations::putStatus((int) $this->site->id, SiteOperations::SETUP, ['status' => 'ready'], ['unattended' => true]);
        $this->delegate(AiRouteKeys::SITE_CLUSTER_MATCH);
        ClusterMatchAgent::fake()->preventStrayPrompts();
        ClusterGapsAgent::fake(fn (): array => ['clusters' => []]);
    }

    public function test_eslestir_waiting_for_claude_is_running_however_long_claude_takes(): void
    {
        Queue::fake();
        $this->eslestir();
        $this->assertSame('queued', SiteOperations::status((int) $this->site->id, SiteOperations::CLUSTER_AUDIT)['status']);
        $this->assertSame(1, AiTask::query()->where('status', AiTask::PENDING)->count());

        // Past the stale mark of a running job and past the open pass: the open task keeps it under way.
        $this->travel(30)->hours();
        $this->assertFalse(ClusterAudit::passOpen($this->site));
        $this->assertTrue(SiteFlow::auditRunning((int) $this->site->id));
        $this->assertSame('running', SiteFlow::advance($this->site), 'nightly: no second Eşleştir');
        $this->assertSame('running', SiteFlow::advance($this->site, setup: false), 'after clustering: no second Eşleştir');
        Livewire::test(ContentIdeasTab::class, ['assetId' => $this->site->id])->call('matchAll')->assertSee('Eşleştirme zaten çalışıyor');
        Queue::assertNothingPushed();

        app(AiTaskQueue::class)->claim(AiTask::query()->sole());
        $this->assertTrue(SiteFlow::auditRunning((int) $this->site->id), 'claimed by Claude: still waiting');

        // Without the MCP server nothing can answer the task: it no longer holds the site.
        config(['moxdop-mcp.token' => '']);
        $this->assertFalse(SiteFlow::auditRunning((int) $this->site->id));
    }

    public function test_an_answered_eslestir_runs_until_its_job_ran_again_and_a_queued_mark_with_nothing_waiting_does_not(): void
    {
        Queue::fake();
        $this->eslestir();
        $this->travel(5)->hours();
        $this->answerMatch(AiTask::query()->where('status', AiTask::PENDING)->sole());
        Queue::assertPushed(RunSiteOperationJob::class, 1);

        // Answered, the job waits on the queue: the open pass keeps Eşleştir under way.
        $this->assertSame(0, AiTask::query()->whereIn('status', [AiTask::PENDING, AiTask::CLAIMED])->count());
        $this->assertTrue(SiteFlow::auditRunning((int) $this->site->id));
        $this->assertSame('running', SiteFlow::advance($this->site));

        $this->eslestir();
        $this->assertSame('ready', SiteOperations::status((int) $this->site->id, SiteOperations::CLUSTER_AUDIT)['status']);
        $this->assertFalse(SiteFlow::auditRunning((int) $this->site->id));
        $this->assertSame($this->implantPage->id, BrandClusterPage::query()->where('cluster_id', $this->treatment->id)->value('page_id'));

        // A queued mark left behind with no open task and no open pass is not a running Eşleştir.
        SiteOperations::putStatus((int) $this->site->id, SiteOperations::CLUSTER_AUDIT, ['status' => 'queued']);
        $this->assertFalse(SiteFlow::auditRunning((int) $this->site->id));
        // A running mark still counts for RUNNING_HOURS only (a job that died).
        SiteOperations::putStatus((int) $this->site->id, SiteOperations::CLUSTER_AUDIT, ['status' => 'running']);
        $this->assertTrue(SiteFlow::auditRunning((int) $this->site->id));
        $this->travel(4)->hours();
        $this->assertFalse(SiteFlow::auditRunning((int) $this->site->id));
    }

    public function test_a_finishing_setup_starts_eslestir_unless_one_waits_for_claude(): void
    {
        PageCategoriesAgent::fake(fn (): array => ['pages' => []]);
        ServicePagesAgent::fake(fn (): array => ['pages' => []]);
        Queue::fake();

        // The setup job's own "running" mark does not hold the next step back.
        SiteOperations::dispatch((int) $this->site->id, SiteOperations::SETUP, ['unattended' => true]);
        (new RunSiteOperationJob((int) $this->site->id, SiteOperations::SETUP, ['unattended' => true]))->handle(app(SiteOperations::class));
        $this->assertSame('audit', SiteOperations::status((int) $this->site->id, SiteOperations::SETUP)['flow']);
        Queue::assertPushed(RunSiteOperationJob::class, fn (RunSiteOperationJob $job): bool => $job->operation === SiteOperations::CLUSTER_AUDIT);

        // Eşleştir waits for Claude for hours; a setup finishing meanwhile (Claude answered its service ↔ page call) waits too.
        $this->eslestir();
        $this->travel(5)->hours();
        Queue::fake();
        SiteOperations::dispatch((int) $this->site->id, SiteOperations::SETUP, ['unattended' => true]);
        (new RunSiteOperationJob((int) $this->site->id, SiteOperations::SETUP, ['unattended' => true]))->handle(app(SiteOperations::class));
        $this->assertSame('running', SiteOperations::status((int) $this->site->id, SiteOperations::SETUP)['flow']);
        Queue::assertNotPushed(RunSiteOperationJob::class, fn (RunSiteOperationJob $job): bool => $job->operation === SiteOperations::CLUSTER_AUDIT);
        $this->assertSame(1, AiTask::query()->where('operation', AiRouteKeys::SITE_CLUSTER_MATCH)->count());
    }

    public function test_a_setup_waiting_for_claude_holds_the_nightly_flow_however_long_it_waits(): void
    {
        $this->delegate(AiRouteKeys::SITE_SERVICE_PAGES);
        ServicePagesAgent::fake()->preventStrayPrompts();
        $this->page('/vida-kok/', 'Vida Kök Uygulaması', ['category' => 'hizmet']); // no rule names its service: the AI is asked
        Queue::fake();

        (new RunSiteOperationJob((int) $this->site->id, SiteOperations::SETUP, ['unattended' => true]))->handle(app(SiteOperations::class));
        $this->assertSame('queued', SiteOperations::status((int) $this->site->id, SiteOperations::SETUP)['status']);
        $this->assertSame(AiRouteKeys::SITE_SERVICE_PAGES, AiTask::query()->where('status', AiTask::PENDING)->sole()->operation);
        $this->travel(5)->hours();

        $this->assertSame('running', SiteFlow::advance($this->site));
        Queue::assertNothingPushed();
    }

    public function test_a_setup_answered_by_claude_holds_the_flow_until_its_job_ran_again(): void
    {
        $task = $this->answeredSetup();

        // Answered, the setup's job waits on the queue: clustering approving new clusters meanwhile starts no Eşleştir.
        $this->travel(1)->hours();
        $this->assertSame('running', SiteFlow::advance($this->site, setup: false));
        Queue::assertNotPushed(RunSiteOperationJob::class, fn (RunSiteOperationJob $job): bool => $job->operation === SiteOperations::CLUSTER_AUDIT);

        // The job runs again with the answer: its own advance starts Eşleştir.
        (new RunSiteOperationJob((int) $this->site->id, SiteOperations::SETUP, ['unattended' => true]))->handle(app(SiteOperations::class));
        $this->assertSame(['ready', 'audit'], [SiteOperations::status((int) $this->site->id, SiteOperations::SETUP)['status'],
            SiteOperations::status((int) $this->site->id, SiteOperations::SETUP)['flow']]);
        $this->assertSame(AiTask::CONSUMED, $task->fresh()->status);
    }

    public function test_a_setup_answer_no_job_took_holds_the_flow_for_running_hours_only(): void
    {
        $this->answeredSetup();

        // The job dispatched again with the answer was lost: the flow goes on once a running job would be stale.
        $this->travel(4)->hours();
        $this->assertSame('audit', SiteFlow::advance($this->site, setup: false));
        Queue::assertPushed(RunSiteOperationJob::class, fn (RunSiteOperationJob $job): bool => $job->operation === SiteOperations::CLUSTER_AUDIT);
    }

    public function test_a_rerun_finds_the_match_answer_by_its_group_although_the_candidate_pages_moved(): void
    {
        Queue::fake();
        $this->eslestir();
        $first = AiTask::query()->where('status', AiTask::PENDING)->sole();

        // A new candidate page and a rewritten page text change the pack while Claude has not answered yet.
        $newPage = $this->page('/implant-tedavisi-fiyatlari/', 'İmplant tedavisi fiyatları', ['category' => 'hizmet']);
        Page::query()->whereKey($this->implantPage->id)->update(['content_text' => 'İmplant tedavisi yeniden yazıldı.']);
        $this->eslestir();
        $this->assertSame([$first->id], AiTask::query()->pluck('id')->all(), 'the same question: no second task');
        $this->assertStringNotContainsString((string) $newPage->url, (string) $first->fresh()->input, 'Claude answers the pack it was asked');

        $this->answerMatch($first);
        $this->eslestir();
        $this->assertSame('ready', SiteOperations::status((int) $this->site->id, SiteOperations::CLUSTER_AUDIT)['status']);
        $this->assertSame($this->implantPage->id, BrandClusterPage::query()->where('cluster_id', $this->treatment->id)->value('page_id'));
        $this->assertSame(AiTask::CONSUMED, $first->fresh()->status);
        ClusterMatchAgent::assertNeverPrompted();
    }

    public function test_a_new_cluster_of_the_service_while_claude_waits_is_a_new_question(): void
    {
        Queue::fake();
        $this->eslestir();
        $first = AiTask::query()->where('status', AiTask::PENDING)->sole();

        $aftercare = $this->cluster($this->implant, 'İmplant tedavisi sonrası', ['implant tedavisi sonrası bakım']);
        $aftercare->forceFill(['ai_queries' => []])->save();
        $this->eslestir();

        $second = AiTask::query()->where('status', AiTask::PENDING)->whereKeyNot($first->id)->sole();
        $this->assertNotSame($first->input_hash, $second->input_hash);
        $this->assertStringContainsString('İmplant tedavisi sonrası', (string) $second->input, 'the new cluster is read, not left without a page');
    }

    public function test_the_weekly_refresh_leaves_the_cluster_rows_to_a_waiting_eslestir(): void
    {
        // A cluster whose service has no page of its own and a blog page of the same name: the rule pass asks the AI judge.
        $this->cluster($this->zirkonyum, 'Zirkonyum kaplama', ['zirkonyum kaplama'])->forceFill(['ai_queries' => []])->save();
        $this->page('/blog/zirkonyum-kaplama/', 'Zirkonyum kaplama', ['category' => 'blog']);
        ServicePagesAgent::fake(fn (): array => ['pages' => []]);
        PageSummaryAgent::fake(fn (): array => ['pages' => []]);
        ClusterPagesAgent::fake(fn (): array => ['clusters' => []]);
        Queue::fake();

        $weekly = new RunSiteOperationJob((int) $this->site->id, SiteOperations::WEEKLY_REFRESH);
        $weekly->handle(app(SiteOperations::class));
        $this->assertSame('ready', SiteOperations::status((int) $this->site->id, SiteOperations::WEEKLY_REFRESH)['cluster_pages'], 'no Eşleştir: the step runs');
        ClusterPagesAgent::assertPrompted(fn (): bool => true);

        $this->eslestir();
        $this->travel(5)->hours();
        $this->delegate(AiRouteKeys::SITE_CLUSTER_PAGES);
        $weekly->handle(app(SiteOperations::class));

        $status = SiteOperations::status((int) $this->site->id, SiteOperations::WEEKLY_REFRESH);
        $this->assertSame(['ready', 'audit_running'], [$status['status'], $status['cluster_pages']]);
        $this->assertSame('ready', $status['summaries'], 'the later steps still run');
        $this->assertSame([AiRouteKeys::SITE_CLUSTER_MATCH], AiTask::query()->distinct()->pluck('operation')->all(), 'Claude is not asked about the clusters twice');
    }

    /** One Eşleştir job run (the first part, as the flow, the button and Claude's answers start it). */
    private function eslestir(): void
    {
        (new RunSiteOperationJob((int) $this->site->id, SiteOperations::CLUSTER_AUDIT))->handle(app(SiteOperations::class));
    }

    /** The flow's setup asked Claude its service ↔ page call hours ago and Claude has just answered it; its job is dispatched again. */
    private function answeredSetup(): AiTask
    {
        $this->delegate(AiRouteKeys::SITE_SERVICE_PAGES);
        ServicePagesAgent::fake()->preventStrayPrompts();
        $page = $this->page('/vida-kok/', 'Vida Kök Uygulaması', ['category' => 'hizmet']); // no rule names its service: the AI is asked
        Queue::fake();
        (new RunSiteOperationJob((int) $this->site->id, SiteOperations::SETUP, ['unattended' => true]))->handle(app(SiteOperations::class));
        $task = AiTask::query()->where('status', AiTask::PENDING)->sole();
        $this->travel(5)->hours();
        MoxdopServer::tool(SubmitResult::class, ['id' => $task->id, 'output' => ['pages' => [
            ['page_id' => $page->id, 'service_id' => $this->implantOffering->id]]]])->assertOk();
        Queue::assertPushed(RunSiteOperationJob::class, fn (RunSiteOperationJob $job): bool => $job->operation === SiteOperations::SETUP);
        $this->assertSame('queued', SiteOperations::status((int) $this->site->id, SiteOperations::SETUP)['status']);

        return $task;
    }

    private function answerMatch(AiTask $task): void
    {
        MoxdopServer::tool(SubmitResult::class, ['id' => $task->id, 'output' => ['clusters' => [
            ['cluster_id' => $this->treatment->id, 'page_id' => $this->implantPage->id, 'also_page_ids' => [], 'coverage' => 'full', 'reason' => 'Tedavi sayfası.']]]])->assertOk();
    }

    private function delegate(string $operation): void
    {
        $registry = app(PromptRegistry::class);
        $registry->publish($operation, ['template' => (string) $registry->current($operation)->template, 'model' => AiTaskQueue::MODEL], $this->admin);
    }
}
