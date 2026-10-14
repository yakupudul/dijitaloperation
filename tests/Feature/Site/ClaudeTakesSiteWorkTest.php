<?php

namespace Tests\Feature\Site;

use App\Ai\Agents\Site\ApplyChangeAgent;
use App\Ai\Agents\Site\ContentDiscoveryAgent;
use App\Ai\Agents\Site\ContentIdeasAgent;
use App\Ai\Agents\Site\ContentRecipeAgent;
use App\Ai\Agents\Site\PageSummaryAgent;
use App\Ai\Agents\Site\StandardFromDecisionAgent;
use App\Ai\Agents\Site\UrlAnalysisAgent;
use App\Jobs\Site\GenerateContentIdeasJob;
use App\Jobs\Site\RunSiteOperationJob;
use App\Models\AiTask;
use App\Models\BrandClusterPage;
use App\Models\BrandMemory;
use App\Models\ContentIdea;
use App\Models\OfferingPage;
use App\Models\Page;
use App\Models\Query;
use App\Models\Suggestion;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Queries\QueryNormalizer;
use App\Services\Site\ContentIdeaPool;
use App\Services\Site\SiteOperations;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * yakup, 2026-10-14: "sistemdeki tüm işleri sen devral". While Claude takes all, the website work whose callers used to
 * need an immediate answer (idea pool, discovery, SEO analizi, AI ile yap, page summaries, standard proposals, URL
 * analysis) waits in the AI iş kuyruğu instead of calling a provider; Claude's answer lands where the provider's did.
 */
final class ClaudeTakesSiteWorkTest extends SiteTestCase
{
    private Page $implantPage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableAi();
        config(['moxdop-mcp.token' => 'test-mcp-token', 'moxdop-mcp.takes_all' => true]);
        $this->implantPage = $this->page('/implant/', 'Ankara İmplant Tedavisi', ['category' => 'hizmet',
            'content_text' => 'İmplant süreci adım adım anlatılır. Kliniğimizde uzman hekimler çalışır.']);
        OfferingPage::query()->create(['brand_offering_id' => $this->implantOffering->id, 'page_id' => $this->implantPage->id, 'source' => 'rule']);
        foreach ([ContentIdeasAgent::class, ContentDiscoveryAgent::class, ContentRecipeAgent::class, ApplyChangeAgent::class,
            PageSummaryAgent::class, StandardFromDecisionAgent::class, UrlAnalysisAgent::class] as $agent) {
            $agent::fake()->preventStrayPrompts();
        }
    }

    public function test_every_converted_operation_waits_for_claude(): void
    {
        $queue = app(AiTaskQueue::class);
        foreach ([AiRouteKeys::CONTENT_IDEAS, AiRouteKeys::SITE_CONTENT_DISCOVERY, AiRouteKeys::SITE_CONTENT_RECIPE, AiRouteKeys::SITE_APPLY_CHANGE,
            AiRouteKeys::SITE_PAGE_SUMMARY, AiRouteKeys::SITE_STANDARD_FROM_DECISION, AiRouteKeys::SITE_URL_ANALYSIS] as $operation) {
            $this->assertTrue($queue->delegated($operation), $operation);
        }
    }

    public function test_new_content_ideas_wait_for_claude_and_land_in_the_pool(): void
    {
        $cluster = $this->cluster($this->implant, 'İmplant tedavisi süreci', ['implant tedavisi', 'implant sonrası ne yenir', 'implant mı köprü mü']);

        (new GenerateContentIdeasJob($cluster->id, $this->brand->id, 2, $this->admin->id))->handle(app(ContentIdeaPool::class));

        $task = AiTask::query()->sole();
        $this->assertSame([AiRouteKeys::CONTENT_IDEAS, AiTask::PENDING], [$task->operation, $task->status]);
        $this->assertSame(['running', true], [Cache::get(ContentIdeaPool::cacheKey($cluster->id))['status'], Cache::get(ContentIdeaPool::cacheKey($cluster->id))['waiting_for_claude']],
            'the screen keeps "üretiliyor" and no second request starts');
        $this->assertSame(0, ContentIdea::query()->count());

        $this->assertSame([], app(AiTaskQueue::class)->submit($task, ['ideas' => [
            ['title' => 'İmplant sonrası beslenme rehberi', 'type' => 'guide', 'angle' => 'Tedaviden sonraki ilk haftalar.',
                'target_queries' => ['implant sonrası ne yenir'], 'outline' => ['İlk gün', 'İlk hafta', 'Kaçınılacaklar']],
        ]]));

        $this->assertSame('İmplant sonrası beslenme rehberi', ContentIdea::query()->sole()->title);
        $this->assertSame($this->brand->id, ContentIdea::query()->sole()->origin_brand_id);
        $this->assertSame(['done', 1], [Cache::get(ContentIdeaPool::cacheKey($cluster->id))['status'], Cache::get(ContentIdeaPool::cacheKey($cluster->id))['added']]);
        $this->assertSame(AiTask::CONSUMED, $task->fresh()->status);
        ContentIdeasAgent::assertNeverPrompted();
    }

    public function test_content_discovery_waits_for_claude(): void
    {
        $free = Query::query()->create(['text' => 'diş taşı temizliği', 'text_hash' => QueryNormalizer::hash('diş taşı temizliği'), 'sector_id' => $this->dental->id, 'assignment' => 'none']);
        DB::table('brand_queries')->insert(['brand_id' => $this->brand->id, 'query_id' => $free->id, 'clicks_28d' => 4, 'impressions_28d' => 90, 'created_at' => now(), 'updated_at' => now()]);

        $task = $this->runUntilWaiting(SiteOperations::DISCOVERY, [], AiRouteKeys::SITE_CONTENT_DISCOVERY);
        // The brand's queries move while Claude works: the answer is found by its slot all the same.
        DB::table('brand_queries')->where('query_id', $free->id)->update(['impressions_28d' => 140]);

        $this->assertSame([], app(AiTaskQueue::class)->submit($task, ['items' => [
            ['title' => 'Diş taşı temizliği ne sıklıkla yapılır', 'service_id' => $this->implant->id, 'query_ids' => [$free->id], 'new_queries' => [],
                'page_type' => 'blog', 'outline' => ['Diş taşı nedir'], 'questions' => ['Diş taşı temizliği acıtır mı?'], 'reason' => 'Kümesiz sorgu.'],
        ]]));

        $this->assertSame(['ready', 1], [$this->siteStatus(SiteOperations::DISCOVERY)['status'], $this->siteStatus(SiteOperations::DISCOVERY)['added']]);
        $this->assertTrue(Suggestion::query()->where('title', 'Diş taşı temizliği ne sıklıkla yapılır')->sole()->action['out_of_cluster']);
        ContentDiscoveryAgent::assertNeverPrompted();
    }

    public function test_seo_analysis_recipe_waits_for_claude(): void
    {
        $cluster = $this->cluster($this->implant, 'İmplant tedavisi', ['implant tedavisi', 'implant nasıl yapılır']);
        $row = BrandClusterPage::query()->create(['brand_id' => $this->brand->id, 'cluster_id' => $cluster->id, 'website_asset_id' => $this->site->id,
            'page_id' => $this->implantPage->id, 'state' => 'thin_coverage', 'language' => 'tr']);
        $params = ['kind' => 'main', 'id' => $row->id];

        $task = $this->runUntilWaiting(SiteOperations::RECIPE, $params, AiRouteKeys::SITE_CONTENT_RECIPE);
        $this->assertNull($row->fresh()->recipe);

        $this->assertSame([], app(AiTaskQueue::class)->submit($task, ['summary' => 'Sayfa süreci anlatıyor; nasıl yapılır sorusu yanıtlanmıyor.', 'steps' => [
            ['order' => 1, 'area' => 'bolum', 'action' => 'İmplant nasıl yapılır bölümü ekle', 'where' => 'Sayfa ortası', 'why' => 'Sorgu sayfada yanıtlanmıyor.', 'evidence' => []],
        ], 'seo_title' => null, 'meta_description' => null, 'expected_effect' => 'Soru sorgularında görünürlük.', 'measure_after_days' => 56]));

        $this->assertSame('ready', $this->siteStatus(SiteOperations::RECIPE, $params)['status']);
        $this->assertSame(['İmplant nasıl yapılır bölümü ekle'], array_column($row->fresh()->recipe['steps'], 'action'));
        ContentRecipeAgent::assertNeverPrompted();
    }

    public function test_ai_ile_yap_waits_for_claude_and_the_proposal_is_shown(): void
    {
        $suggestion = $this->suggestion(['page_id' => $this->implantPage->id, 'action_type' => 'title_description', 'title' => 'Başlığı güçlendir']);
        $params = ['suggestion_id' => $suggestion->id];

        $task = $this->runUntilWaiting(SiteOperations::APPLY_CHANGE, $params, AiRouteKeys::SITE_APPLY_CHANGE);
        $this->assertArrayNotHasKey('proposal', (array) $suggestion->fresh()->action);

        $this->assertSame([], app(AiTaskQueue::class)->submit($task, ['seo_title' => 'Ankara İmplant Tedavisi | Panorama', 'meta_description' => null, 'html' => null,
            'internal_links' => [], 'schema_json' => null, 'note' => 'Başlık yenilendi.']));

        $this->assertSame('ready', $this->siteStatus(SiteOperations::APPLY_CHANGE, $params)['status']);
        $this->assertSame('Ankara İmplant Tedavisi | Panorama', $suggestion->fresh()->action['proposal']['new']['seo_title']);
        ApplyChangeAgent::assertNeverPrompted();
    }

    public function test_standard_proposal_waits_for_claude(): void
    {
        $suggestion = $this->suggestion(['page_id' => $this->implantPage->id, 'title' => 'Randevu adımı ekle', 'status' => Suggestion::APPROVED]);
        $params = ['suggestion_id' => $suggestion->id];

        $task = $this->runUntilWaiting(SiteOperations::STANDARD, $params, AiRouteKeys::SITE_STANDARD_FROM_DECISION);

        $this->assertSame([], app(AiTaskQueue::class)->submit($task, ['title' => 'Hizmet sayfasında randevu adımı', 'rule' => 'Her hizmet sayfası randevu bağlantısıyla bitmeli.',
            'condition' => 'hizmet sayfaları', 'exceptions' => '', 'scope' => 'brand']));

        $this->assertSame('ready', $this->siteStatus(SiteOperations::STANDARD, $params)['status']);
        $this->assertSame('Hizmet sayfasında randevu adımı', $suggestion->fresh()->action['standard_draft']['title']);
        StandardFromDecisionAgent::assertNeverPrompted();
    }

    public function test_page_summaries_wait_for_claude(): void
    {
        $task = $this->runUntilWaiting(SiteOperations::SUMMARIES, [], AiRouteKeys::SITE_PAGE_SUMMARY);

        $this->assertSame([], app(AiTaskQueue::class)->submit($task, ['pages' => [
            ['page_id' => $this->implantPage->id, 'summary' => 'Sayfa implant sürecini adım adım anlatır ve uzman hekimleri tanıtır.', 'facts' => []],
        ]]));

        $this->assertSame(['ready', 1], [$this->siteStatus(SiteOperations::SUMMARIES)['status'], $this->siteStatus(SiteOperations::SUMMARIES)['written']]);
        $this->assertStringStartsWith('Sayfa implant sürecini', (string) $this->implantPage->fresh()->content_summary);
        PageSummaryAgent::assertNeverPrompted();
    }

    public function test_url_analysis_waits_for_its_page_summary_then_for_the_analysis(): void
    {
        $params = ['page_ids' => [$this->implantPage->id]];

        // The pack needs the page's summary first: only the summary is asked.
        $summary = $this->runUntilWaiting(SiteOperations::URL_ANALYSIS, $params, AiRouteKeys::SITE_PAGE_SUMMARY);
        $this->assertSame([], app(AiTaskQueue::class)->submit($summary, ['pages' => [
            ['page_id' => $this->implantPage->id, 'summary' => 'Sayfa implant sürecini adım adım anlatır ve uzman hekimleri tanıtır.', 'facts' => []],
        ]]));

        // The job ran again: the summary is stored and the analysis waits for Claude with it in the pack.
        $this->assertSame('queued', $this->siteStatus(SiteOperations::URL_ANALYSIS, $params)['status']);
        $analysis = AiTask::query()->where('operation', AiRouteKeys::SITE_URL_ANALYSIS)->sole();
        $this->assertStringContainsString('Sayfa implant sürecini adım adım', (string) $analysis->input);
        $this->assertSame([], app(AiTaskQueue::class)->submit($analysis, ['suggestions' => [
            ['type' => 'title_description', 'title' => 'Başlığa hedef sorguyu ekle', 'reason' => 'Başlık hedef sorguyu içermiyor.', 'priority' => 1, 'cluster_id' => null, 'evidence' => []],
        ]]));

        $this->assertSame(['ready', 1, 1], [$this->siteStatus(SiteOperations::URL_ANALYSIS, $params)['status'], $this->siteStatus(SiteOperations::URL_ANALYSIS, $params)['pages'],
            $this->siteStatus(SiteOperations::URL_ANALYSIS, $params)['suggestions']]);
        $this->assertSame('Başlığa hedef sorguyu ekle', Suggestion::query()->where('page_id', $this->implantPage->id)->sole()->title);
        $this->assertNotNull($this->implantPage->fresh()->analyzed_at);
        $this->assertSame(1, BrandMemory::query()->where('kind', 'page')->count());
        $this->assertSame(2, AiTask::query()->where('status', AiTask::CONSUMED)->count());
        UrlAnalysisAgent::assertNeverPrompted();
        PageSummaryAgent::assertNeverPrompted();
    }

    /** @param  array<string, mixed>  $params */
    private function runUntilWaiting(string $operation, array $params, string $aiOperation): AiTask
    {
        (new RunSiteOperationJob($this->site->id, $operation, $params))->handle(app(SiteOperations::class));
        $this->assertSame('queued', $this->siteStatus($operation, $params)['status']);
        $task = AiTask::query()->where('status', AiTask::PENDING)->sole();
        $this->assertSame($aiOperation, $task->operation);

        return $task;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function siteStatus(string $operation, array $params = []): array
    {
        return (array) SiteOperations::status($this->site->id, $operation, $params);
    }

    /** @param  array<string, mixed>  $overrides */
    private function suggestion(array $overrides): Suggestion
    {
        $title = (string) ($overrides['title'] ?? 'Öneri');

        return Suggestion::query()->create(array_merge([
            'brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => 'site.test', 'fingerprint' => hash('sha256', $title),
            'material_hash' => hash('sha256', 'x'), 'title' => $title, 'reason' => 'Gerekçe.', 'priority' => 2, 'action_type' => 'title_description',
            'status' => Suggestion::OPEN, 'action' => ['site_id' => $this->site->id], 'evidence' => [],
        ], $overrides));
    }
}
