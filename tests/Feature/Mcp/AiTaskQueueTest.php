<?php

namespace Tests\Feature\Mcp;

use App\Ai\Agents\Site\ClusterMatchAgent;
use App\Ai\Agents\Site\WriteArticleAgent;
use App\Jobs\Site\RunSiteOperationJob;
use App\Livewire\Operator\Settings\AiOperationsPage;
use App\Mcp\Servers\MoxdopServer;
use App\Mcp\Tools\FailTask;
use App\Models\AiTask;
use App\Models\Suggestion;
use App\Services\Ai\AiBudget;
use App\Services\Ai\AiSchedule;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Prompts\PromptRegistry;
use App\Services\Site\SiteAi;
use App\Services\Site\SiteOperations;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Site\SiteTestCase;

/**
 * AI iş kuyruğu (MCP yol haritası, Faz 1): an operation delegated to "Claude (MCP)" waits in ai_tasks instead of
 * calling a provider, Claude reads and answers it through the MoxDOP MCP server, the answer is checked against the
 * agent schema, and the asking job runs again and continues exactly like a provider response.
 */
final class AiTaskQueueTest extends SiteTestCase
{
    private const array ARTICLE = [
        'title' => 'İmplant sonrası ilk hafta', 'slug' => 'implant-sonrasi-ilk-hafta', 'meta_title' => 'İmplant sonrası ilk hafta',
        'meta_description' => 'İlk hafta nelere dikkat edilir?', 'excerpt' => 'İlk hafta rehberi.',
        'html' => '<h2>İlk 24 saat</h2><p>Soğuk uygulama yapılır.</p><p>Bkz. <a href="https://panorama.com.tr/implant/">implant</a></p>',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableAi();
        config(['moxdop-mcp.token' => 'test-mcp-token']);
        $this->page('/implant/', 'Ankara İmplant Tedavisi', ['category' => 'hizmet']);
    }

    public function test_content_writing_never_waits_for_claude_even_when_its_prompt_says_claude(): void
    {
        // yakup, 2026-10-12: "İçerik fikri, üretimi gönderimi vs. hiçbir zaman kuyruğa girmesin."
        $this->delegate(AiRouteKeys::SITE_WRITE_ARTICLE);
        WriteArticleAgent::fake([self::ARTICLE]);
        $suggestion = $this->contentSuggestion();

        (new RunSiteOperationJob($this->site->id, SiteOperations::WRITE_ARTICLE, ['suggestion_id' => $suggestion->id]))
            ->handle(app(SiteOperations::class));

        $this->assertSame('ready', SiteOperations::status($this->site->id, SiteOperations::WRITE_ARTICLE, ['suggestion_id' => $suggestion->id])['status']);
        $this->assertSame('İmplant sonrası ilk hafta', $suggestion->fresh()->action['article']['title']);
        $this->assertSame(0, AiTask::query()->count());
    }

    public function test_failed_task_hands_its_run_back_with_an_error(): void
    {
        $task = AiTask::query()->create(['operation' => 'site.cluster_match', 'resume_key' => hash('sha256', 'run'), 'sequence' => 1, 'input_hash' => 'h',
            'status' => AiTask::PENDING, 'instructions' => 'x', 'input' => 'DATA_JSON {}', 'output_schema' => [],
            'resume' => serialize(new RunSiteOperationJob($this->site->id, SiteOperations::CLUSTER_PAGES, []))]);

        Queue::fake();
        MoxdopServer::tool(FailTask::class, ['id' => $task->id, 'reason' => 'Girdide küme sorgusu yok.'])->assertOk();

        Queue::assertPushed(RunSiteOperationJob::class);
        $this->assertSame(AiTask::FAILED, $task->fresh()->status);
        $this->assertSame('Girdide küme sorgusu yok.', $task->fresh()->error);
    }

    public function test_operation_not_delegated_keeps_the_provider_route(): void
    {
        WriteArticleAgent::fake([self::ARTICLE]);
        $suggestion = $this->contentSuggestion();

        (new RunSiteOperationJob($this->site->id, SiteOperations::WRITE_ARTICLE, ['suggestion_id' => $suggestion->id]))
            ->handle(app(SiteOperations::class));

        $this->assertSame(0, AiTask::query()->count());
        $this->assertSame('İmplant sonrası ilk hafta', $suggestion->fresh()->action['article']['title']);
    }

    public function test_delegation_needs_the_mcp_token_and_a_supported_operation(): void
    {
        $queue = app(AiTaskQueue::class);
        $this->delegate('site.cluster_match');
        $this->assertTrue($queue->delegated('site.cluster_match'));
        $this->assertFalse($queue->supports(AiRouteKeys::QUERIES_TRIAGE), 'query autopilot stays on the API');
        $this->assertFalse($queue->supports(AiRouteKeys::SITE_WRITE_ARTICLE), 'content work never waits for Claude');
        $this->assertFalse($queue->supports(AiRouteKeys::SITE_CONTENT_RECIPE));

        config(['moxdop-mcp.token' => '']);
        $this->assertFalse($queue->delegated('site.cluster_match'));
    }

    public function test_ai_operations_screen_offers_claude_mcp_only_where_it_works_and_shows_the_queue(): void
    {
        Livewire::test(AiOperationsPage::class)->call('open', 'site.cluster_match')->assertSee('Claude (MCP, abonelik)')
            ->set('model', AiTaskQueue::MODEL)->call('save')->assertHasNoErrors();
        $this->assertTrue(app(AiTaskQueue::class)->delegated('site.cluster_match'));
        Livewire::test(AiOperationsPage::class)->call('open', AiRouteKeys::QUERIES_TRIAGE)->assertDontSee('Claude (MCP, abonelik)');
        Livewire::test(AiOperationsPage::class)->call('open', AiRouteKeys::SITE_WRITE_ARTICLE)->assertDontSee('Claude (MCP, abonelik)');

        AiTask::query()->create(['operation' => 'site.cluster_match', 'resume_key' => hash('sha256', 'run'), 'sequence' => 1, 'input_hash' => 'h',
            'status' => AiTask::PENDING, 'instructions' => 'x', 'input' => 'DATA_JSON {}', 'output_schema' => [], 'resume' => serialize(new RunSiteOperationJob($this->site->id, SiteOperations::CLUSTER_PAGES, []))]);
        Livewire::test(AiOperationsPage::class)->assertSee('Claude (MCP) iş kuyruğu')->assertSee('Claude bekleniyor');
    }

    public function test_mcp_endpoint_requires_the_bearer_token(): void
    {
        $message = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []];

        $this->postJson('/mcp/moxdop', $message)->assertUnauthorized();
        $this->postJson('/mcp/moxdop', $message, ['Authorization' => 'Bearer wrong'])->assertUnauthorized();
        $this->postJson('/mcp/moxdop', $message, ['Authorization' => 'Bearer test-mcp-token'])->assertOk()->assertSee('submit_result');

        config(['moxdop-mcp.token' => '']);
        $this->postJson('/mcp/moxdop', $message, ['Authorization' => 'Bearer test-mcp-token'])->assertNotFound();
    }

    public function test_a_delegated_operation_called_outside_a_job_never_uses_the_provider(): void
    {
        $this->delegate('site.cluster_match');
        ClusterMatchAgent::fake()->preventStrayPrompts();

        $result = app(SiteAi::class)->run(new ClusterMatchAgent, ['clusters' => []]);

        $this->assertSame('error', $result['status']);
        $this->assertSame(0, AiTask::query()->count());
        ClusterMatchAgent::assertNeverPrompted();
    }

    public function test_work_delegated_to_claude_may_run_without_a_click(): void
    {
        config(['moxdop-ai-pricing.automatic_areas' => ['queries']]);
        $this->assertFalse(AiBudget::automaticAllowed('site.cluster_match'));
        $this->assertFalse(AiBudget::automaticAllowed('site.weekly_refresh'));

        $this->delegate('site.cluster_match');
        $this->assertTrue(AiBudget::automaticAllowed('site.cluster_match'), 'delegated: no API cost, runs by itself');
        $this->assertTrue(AiBudget::automaticAllowed('site.weekly_refresh'), 'the weekly gate opens with a delegated site step');
        $this->assertFalse(AiBudget::automaticAllowed('site.page_summary'), 'an API operation still waits for a click');
        $this->assertFalse(AiBudget::automaticAllowed('brand.care'));
        $site = collect(app(AiSchedule::class)->upcoming())->firstWhere('name', 'site-weekly');
        $this->assertStringNotContainsString('Kapalı', $site['what']);

        config(['moxdop-mcp.token' => '']);
        $this->assertFalse(AiBudget::automaticAllowed('site.cluster_match'), 'MCP off: the API route, so a click again');
    }

    private function delegate(string $operation): void
    {
        $registry = app(PromptRegistry::class);
        $registry->publish($operation, ['template' => (string) $registry->current($operation)->template, 'model' => AiTaskQueue::MODEL], $this->admin);
    }

    private function contentSuggestion(): Suggestion
    {
        return Suggestion::query()->create([
            'brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => 'site.content', 'fingerprint' => hash('sha256', 'mcp-content'),
            'material_hash' => hash('sha256', 'x'), 'title' => 'İmplant sonrası ilk hafta', 'reason' => 'Kümenin uygun sayfası yok.', 'priority' => 2,
            'action_type' => 'content', 'status' => Suggestion::OPEN, 'evidence' => [],
            'action' => ['site_id' => $this->site->id, 'kind' => 'new', 'page_type' => 'blog', 'outline' => ['İlk 24 saat'], 'questions' => [],
                'target_url' => 'https://panorama.com.tr/blog/implant-sonrasi-ilk-hafta/'],
        ]);
    }

    public function test_when_claude_takes_all_every_operation_that_can_wait_goes_to_the_queue_whatever_its_model(): void
    {
        $queue = app(AiTaskQueue::class);
        config(['moxdop-mcp.token' => 'test-mcp-token', 'moxdop-mcp.takes_all' => true]);

        $this->assertTrue($queue->delegated('site.cluster_match'), 'its model is not Claude (MCP)');
        $this->assertTrue($queue->delegated(AiRouteKeys::SITE_WRITE_ARTICLE), 'content work too, money or not');
        $this->assertTrue($queue->delegated(AiRouteKeys::QUERIES_TRIAGE));
        $this->assertFalse($queue->delegated('whatsapp.reply'), 'a caller that cannot wait keeps its route');

        config(['moxdop-mcp.takes_all' => false]);
        $this->assertFalse($queue->delegated('site.cluster_match'));
    }
}
