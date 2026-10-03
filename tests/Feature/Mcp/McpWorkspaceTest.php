<?php

namespace Tests\Feature\Mcp;

use App\Ai\Agents\Site\ClusterAiQueriesAgent;
use App\Ai\Agents\Site\ClusterGapsAgent;
use App\Ai\Agents\Site\ClusterMatchAgent;
use App\Ai\Agents\Site\PageCategoriesAgent;
use App\Ai\Agents\Site\PageSummaryAgent;
use App\Ai\Agents\Site\ServicePagesAgent;
use App\Ai\Agents\Site\WriteArticleAgent;
use App\Jobs\Site\RunSiteOperationJob;
use App\Mcp\Servers\MoxdopServer;
use App\Mcp\Tools\ContentQueue;
use App\Mcp\Tools\GetBrand;
use App\Mcp\Tools\ListBrands;
use App\Mcp\Tools\ListNotes;
use App\Mcp\Tools\RequestArticle;
use App\Mcp\Tools\SaveNote;
use App\Mcp\Tools\SubmitResult;
use App\Mcp\Tools\SystemHealth;
use App\Models\AiTask;
use App\Models\BrandClusterPage;
use App\Models\ClaudeNote;
use App\Models\OfferingPage;
use App\Models\Suggestion;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Brand\BrandDossier;
use App\Services\Prompts\PromptRegistry;
use App\Services\Site\ContentPlanner;
use App\Services\Site\SiteOperations;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Site\SiteTestCase;

/**
 * MCP Faz 2: Claude reads brands (rule-built facts, only what changed), keeps its own notes apart from facts, starts
 * drafts for approved titles, reads system health, and does Eşleştir (AI questions → match → gaps) in rounds.
 */
final class McpWorkspaceTest extends SiteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enableAi();
        config(['moxdop-mcp.token' => 'test-mcp-token']);
    }

    public function test_delegated_eslestir_waits_for_claude_step_by_step_and_finishes_with_the_answers(): void
    {
        foreach ([AiRouteKeys::QUERIES_AI_QUERIES, AiRouteKeys::SITE_CLUSTER_MATCH, AiRouteKeys::SITE_CLUSTER_GAPS] as $operation) {
            $this->delegate($operation);
        }
        ClusterAiQueriesAgent::fake()->preventStrayPrompts();
        ClusterMatchAgent::fake()->preventStrayPrompts();
        ClusterGapsAgent::fake()->preventStrayPrompts();
        $implantPage = $this->page('/implant/', 'İmplant Tedavisi', ['category' => 'hizmet', 'h1' => 'İmplant tedavisi', 'content_text' => 'İmplant tedavisi adım adım anlatılır.']);
        $cluster = $this->cluster($this->implant, 'İmplant tedavisi', ['implant tedavisi', 'implant nasıl yapılır']);
        $cluster->forceFill(['approved' => true])->save();
        $job = new RunSiteOperationJob($this->site->id, SiteOperations::CLUSTER_AUDIT);
        Queue::fake();

        // Round 1: the AI questions (the match reads them, so nothing else is asked yet).
        $job->handle(app(SiteOperations::class));
        $this->assertSame('queued', SiteOperations::status($this->site->id, SiteOperations::CLUSTER_AUDIT)['status']);
        $questions = AiTask::query()->where('status', AiTask::PENDING)->sole();
        $this->assertSame(AiRouteKeys::QUERIES_AI_QUERIES, $questions->operation);
        MoxdopServer::tool(SubmitResult::class, ['id' => $questions->id, 'output' => ['clusters' => [
            ['cluster_id' => $cluster->id, 'questions' => ['İmplant tedavisi kaç seans sürer?']]]]])->assertOk();
        Queue::assertPushed(RunSiteOperationJob::class);

        // Round 2: the match, one task per service.
        $job->handle(app(SiteOperations::class));
        $this->assertSame(['İmplant tedavisi kaç seans sürer?'], $cluster->fresh()->ai_queries);
        $match = AiTask::query()->where('status', AiTask::PENDING)->sole();
        $this->assertSame(AiRouteKeys::SITE_CLUSTER_MATCH, $match->operation);
        MoxdopServer::tool(SubmitResult::class, ['id' => $match->id, 'output' => ['clusters' => [
            ['cluster_id' => $cluster->id, 'page_id' => $implantPage->id, 'also_page_ids' => [], 'coverage' => 'partial', 'reason' => 'Süre anlatılmıyor.']]]])->assertOk();

        // Round 3: the gaps of the matched page; the stored match answer is reused, not asked again.
        $job->handle(app(SiteOperations::class));
        $gaps = AiTask::query()->where('status', AiTask::PENDING)->sole();
        $this->assertSame(AiRouteKeys::SITE_CLUSTER_GAPS, $gaps->operation);
        $this->assertSame($implantPage->id, BrandClusterPage::query()->where('cluster_id', $cluster->id)->value('page_id'));
        MoxdopServer::tool(SubmitResult::class, ['id' => $gaps->id, 'output' => ['clusters' => [
            ['cluster_id' => $cluster->id, 'coverage' => 'partial', 'gaps' => [['text' => 'Tedavinin kaç seans sürdüğü yanıtlanmamış', 'kind' => 'soru']]]]]])->assertOk();

        $job->handle(app(SiteOperations::class));
        $this->assertSame('ready', SiteOperations::status($this->site->id, SiteOperations::CLUSTER_AUDIT)['status']);
        $row = BrandClusterPage::query()->where('cluster_id', $cluster->id)->sole();
        $this->assertSame([$implantPage->id, 'partial', 'thin_coverage'], [$row->page_id, $row->coverage, $row->state]);
        $this->assertSame(['Tedavinin kaç seans sürdüğü yanıtlanmamış'], array_column($row->gaps, 'text'));
        $this->assertSame(3, AiTask::query()->where('status', AiTask::CONSUMED)->count());
        ClusterMatchAgent::assertNeverPrompted();
        ClusterGapsAgent::assertNeverPrompted();
    }

    public function test_weekly_refresh_waits_for_claude_on_categories_then_service_pages(): void
    {
        $this->delegate(AiRouteKeys::SITE_PAGE_CATEGORIES);
        $this->delegate(AiRouteKeys::SITE_SERVICE_PAGES);
        PageCategoriesAgent::fake()->preventStrayPrompts();
        ServicePagesAgent::fake()->preventStrayPrompts();
        PageSummaryAgent::fake([['pages' => []]]);
        $page = $this->page('/vida-kok/', 'Vida Kök Uygulaması', ['wp_post_type' => 'page', 'category' => null]);
        $job = new RunSiteOperationJob($this->site->id, SiteOperations::WEEKLY_REFRESH);
        Queue::fake();

        // Round 1: categories only; the service step reads them, so it is not asked yet.
        $job->handle(app(SiteOperations::class));
        $this->assertSame('queued', SiteOperations::status($this->site->id, SiteOperations::WEEKLY_REFRESH)['status']);
        $categories = AiTask::query()->where('status', AiTask::PENDING)->sole();
        $this->assertSame(AiRouteKeys::SITE_PAGE_CATEGORIES, $categories->operation);
        MoxdopServer::tool(SubmitResult::class, ['id' => $categories->id, 'output' => ['pages' => [['page_id' => $page->id, 'category' => 'hizmet']]]])->assertOk();

        // Round 2: the category is stored, the service ↔ page call waits.
        $job->handle(app(SiteOperations::class));
        $this->assertSame(['hizmet', 'ai'], [$page->fresh()->category, $page->fresh()->category_source]);
        $services = AiTask::query()->where('status', AiTask::PENDING)->sole();
        $this->assertSame(AiRouteKeys::SITE_SERVICE_PAGES, $services->operation);
        MoxdopServer::tool(SubmitResult::class, ['id' => $services->id, 'output' => ['pages' => [['page_id' => $page->id, 'service_id' => $this->implantOffering->id]]]])->assertOk();

        $job->handle(app(SiteOperations::class));
        $this->assertSame('ready', SiteOperations::status($this->site->id, SiteOperations::WEEKLY_REFRESH)['status']);
        $this->assertSame($this->implantOffering->id, OfferingPage::query()->where('page_id', $page->id)->value('brand_offering_id'));
        PageCategoriesAgent::assertNeverPrompted();
        ServicePagesAgent::assertNeverPrompted();
    }

    public function test_only_operations_whose_callers_wait_can_be_delegated(): void
    {
        $queue = app(AiTaskQueue::class);
        $this->assertTrue($queue->supports(AiRouteKeys::SITE_CLUSTER_MATCH));
        $this->assertTrue($queue->supports(AiRouteKeys::SITE_CLUSTER_GAPS));
        $this->assertFalse($queue->supports(AiRouteKeys::SITE_URL_ANALYSIS), 'its caller does not wait for Claude yet');
    }

    public function test_brand_facts_are_read_once_and_claude_notes_stay_apart(): void
    {
        MoxdopServer::tool(ListBrands::class)->assertOk()->assertSee($this->brand->name)->assertSee('"never_read":true');

        MoxdopServer::tool(GetBrand::class, ['brand_id' => $this->brand->id])->assertOk()
            ->assertSee('Marka dosyası')->assertSee('"changed_since_last_read":true')->assertSee('"claude_notes":[]');
        MoxdopServer::tool(ListBrands::class, ['only_changed' => true])->assertOk()->assertDontSee($this->brand->name);
        MoxdopServer::tool(GetBrand::class, ['brand_id' => $this->brand->id, 'only_changed' => true])->assertOk()->assertSee('"sections":[]');

        // A changed section is the only thing to read again.
        app(BrandDossier::class)->build($this->brand);
        BrandDossier::saveNotes($this->brand, 'Implant hastası artsın', '');
        app(BrandDossier::class)->build($this->brand);
        MoxdopServer::tool(ListBrands::class, ['only_changed' => true])->assertOk()->assertSee('"changed_sections":["notes"]');

        MoxdopServer::tool(SaveNote::class, ['brand_id' => $this->brand->id, 'kind' => 'hypothesis', 'text' => 'İmplant kümelerinde rehber içerik eksik olabilir.'])
            ->assertOk()->assertSee('kaydedildi');
        $first = ClaudeNote::query()->sole();
        MoxdopServer::tool(SaveNote::class, ['brand_id' => $this->brand->id, 'kind' => 'observation', 'text' => 'İmplant kümelerinin ikisinde sayfa yok.',
            'refs' => ['Eşleştir'], 'supersedes_id' => $first->id])->assertOk();
        $this->assertSame(ClaudeNote::SUPERSEDED, $first->fresh()->status);
        MoxdopServer::tool(SaveNote::class, ['brand_id' => $this->brand->id + 1000, 'kind' => 'observation', 'text' => 'Olmayan marka.'])->assertHasErrors();
        MoxdopServer::tool(ListNotes::class, ['brand_id' => $this->brand->id])->assertOk()->assertSee('ikisinde sayfa yok')->assertDontSee('eksik olabilir');
        MoxdopServer::tool(GetBrand::class, ['brand_id' => $this->brand->id, 'sections' => ['identity']])->assertOk()
            ->assertSee('ikisinde sayfa yok')->assertDontSee('"demand"');
    }

    public function test_request_article_writes_only_approved_titles_and_never_sends(): void
    {
        $open = $this->contentSuggestion(Suggestion::OPEN, 'a');
        $approved = $this->contentSuggestion(Suggestion::APPROVED, 'b');
        Queue::fake();

        MoxdopServer::tool(ContentQueue::class)->assertOk()->assertSee('"id":'.$approved->id)->assertDontSee('"id":'.$open->id.',');
        MoxdopServer::tool(RequestArticle::class, ['suggestion_id' => $open->id])->assertHasErrors(['onaylanmamış']);
        Queue::assertNothingPushed();

        MoxdopServer::tool(RequestArticle::class, ['suggestion_id' => $approved->id])->assertOk()->assertSee('API modeliyle');
        Queue::assertPushed(RunSiteOperationJob::class, fn (RunSiteOperationJob $job): bool => $job->operation === SiteOperations::WRITE_ARTICLE
            && $job->params === ['suggestion_id' => $approved->id]);
    }

    public function test_a_new_article_is_a_post_even_for_a_service_page_type(): void
    {
        WriteArticleAgent::fake([['title' => 'Pedodonti nedir', 'slug' => 'pedodonti-nedir', 'meta_title' => 'Pedodonti nedir', 'meta_description' => 'Pedodonti.',
            'excerpt' => 'Pedodonti.', 'html' => '<h2>Pedodonti</h2><p>Çocuk diş hekimliği alanıdır.</p>']]);
        $suggestion = $this->contentSuggestion(Suggestion::APPROVED, 'c', 'hizmet');

        app(ContentPlanner::class)->writeArticle($suggestion);

        $this->assertSame('post', $suggestion->fresh()->action['article']['post_type']);
    }

    public function test_delegate_command_hands_supported_operations_to_claude_and_back(): void
    {
        $queue = app(AiTaskQueue::class);

        $this->artisan('moxdop:mcp:delegate')->assertSuccessful();
        foreach (AiTaskQueue::SUPPORTED as $operation) {
            $this->assertTrue($queue->delegated($operation), $operation);
        }
        $this->artisan('moxdop:mcp:delegate', ['operations' => [AiRouteKeys::QUERIES_TRIAGE]])->expectsOutputToContain('atlandı')->assertSuccessful();
        $this->assertFalse($queue->delegated(AiRouteKeys::QUERIES_TRIAGE));

        $this->artisan('moxdop:mcp:delegate', ['operations' => [AiRouteKeys::SITE_CLUSTER_MATCH], '--api' => true])->assertSuccessful();
        $this->assertFalse($queue->delegated(AiRouteKeys::SITE_CLUSTER_MATCH));
        $this->assertTrue($queue->delegated(AiRouteKeys::SITE_CLUSTER_GAPS));

        config(['moxdop-mcp.token' => '']);
        $this->artisan('moxdop:mcp:delegate')->assertFailed();
    }

    public function test_system_health_reads_stored_state(): void
    {
        MoxdopServer::tool(SystemHealth::class)->assertOk()->assertSee('failed_ai_tasks')->assertSee('alerts');
    }

    private function delegate(string $operation): void
    {
        $registry = app(PromptRegistry::class);
        $registry->publish($operation, ['template' => (string) $registry->current($operation)->template, 'model' => AiTaskQueue::MODEL], $this->admin);
    }

    private function contentSuggestion(string $status, string $key, string $pageType = 'blog'): Suggestion
    {
        return Suggestion::query()->create([
            'brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => 'site.content', 'fingerprint' => hash('sha256', 'mcp-content-'.$key),
            'material_hash' => hash('sha256', 'x'), 'title' => 'İmplant sonrası ilk hafta '.$key, 'reason' => 'Kümenin uygun sayfası yok.', 'priority' => 2,
            'action_type' => 'content', 'status' => $status, 'evidence' => [],
            'action' => ['site_id' => $this->site->id, 'kind' => 'new', 'page_type' => $pageType, 'outline' => ['İlk 24 saat'], 'questions' => [],
                'target_url' => 'https://panorama.com.tr/blog/implant-'.$key.'/'],
        ]);
    }
}
