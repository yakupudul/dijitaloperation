<?php

namespace Tests\Feature\ContentStudio;

use App\Ai\Agents\Content\ArticleWriterAgent;
use App\Ai\Agents\Content\ContentIdeaAgent;
use App\Ai\Agents\Content\ContentLocalizerAgent;
use App\Enums\CustomerStatus;
use App\Jobs\BuildBrandDemandJob;
use App\Livewire\Operator\Seo\SeoTasksPanel;
use App\Livewire\Operator\Website\ContentStudioPanel;
use App\Models\AiProduction;
use App\Models\Brand;
use App\Models\BrandDemandQuery;
use App\Models\BrandOffering;
use App\Models\ContentArticle;
use App\Models\ContentIdea;
use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\IntelligenceCore\IntelligencePageIdentity;
use App\Models\IntelligenceProjection\WebsiteIntelligenceProjectionRun;
use App\Models\IntelligenceProjection\WebsitePageProfile;
use App\Models\SeoPlan;
use App\Models\SeoTask;
use App\Models\ServiceCategory;
use App\Models\SiteFixItem;
use App\Models\TopicCluster;
use App\Models\TopicClusterQuery;
use App\Models\TopicMapBuild;
use App\Models\User;
use App\Services\BrandIntelligence\BrandOfferingService;
use App\Services\ContentStudio\ContentIdeaAi;
use App\Services\ContentStudio\ContentIdeaPlanner;
use App\Services\ContentStudio\ContentStudio;
use App\Services\ContentStudio\TopicMapBuilder;
use App\Services\ContentStudio\TopicMapEditor;
use App\Services\Integrations\WordPress\WordPressConnectorClient;
use App\Services\SeoTasks\SeoPlanInputCollector;
use App\Services\SeoTasks\SeoTaskRuleEngine;
use App\Services\SeoTasks\SeoText;
use App\Support\Integrations\WordPress\WordPressConnectorCanonicalJson;
use App\Support\Roles;
use App\Support\ServiceScope;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use MoxDop\Website\Discovery\PublicUrlSafety;
use RuntimeException;
use Tests\Feature\Brain\InsertsFacts;
use Tests\TestCase;

/** SEO content pipeline Faz 3–4: brand topic map and İçerik Stüdyosu (ideas → AI articles → languages → dates → drafts / WXR). */
final class ContentStudioTest extends TestCase
{
    use InsertsFacts;
    use RefreshDatabase;

    private const string SECRET = 'ssssssssssssssssssssssssssssssssssssssssssss';

    private User $admin;

    private User $member;

    private Customer $customer;

    private Brand $brand;

    private DigitalAsset $site;

    private BrandOffering $implant;

    private BrandOffering $aligner;

    private BrandOffering $smile;

    /** @var list<array{0: string, 1: string, 2: mixed}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->member = User::factory()->create(['is_active' => true]);
        $this->member->assignRole(Roles::TEAM_MEMBER);
        $this->customer = Customer::factory()->create(['status' => CustomerStatus::Active]);
        $this->brand = Brand::factory()->create(['customer_id' => $this->customer->id, 'name' => 'Atlas Diş']);
        $this->brand->sectors()->attach(ServiceCategory::query()->where('code', 'dental')->value('id'));
        $this->site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'status' => 'active', 'module_id' => 'website',
            'name' => 'atlas.example', 'domain' => 'example.test', 'primary_url' => 'https://example.test/']);
        $offerings = app(BrandOfferingService::class);
        $this->implant = $offerings->create($this->brand, 'İmplant Tedavisi');
        $this->implant->forceFill(['is_priority' => true, 'priority_rank' => 1])->save();
        $this->aligner = $offerings->create($this->brand, 'Şeffaf Plak');
        $this->smile = $offerings->create($this->brand, 'Gülüş Tasarımı');

        $this->page('/', 'Atlas Diş | Ana sayfa', 'Atlas Diş');
        $this->page('/implant-tedavisi/', 'İmplant Tedavisi | Atlas Diş', 'İmplant Tedavisi', 900);
        $this->page('/implant-nasil-yapilir/', 'İmplant Nasıl Yapılır? | Atlas Diş', 'İmplant Nasıl Yapılır?', 1000);
        $this->page('/seffaf-plak-tedavisi/', 'Şeffaf Plak Tedavisi | Atlas Diş', 'Şeffaf Plak Tedavisi', 800);
        $this->page('/gulus-tasarimi/', 'Gülüş Tasarımı | Atlas Diş', 'Gülüş Tasarımı', 700);
        $this->page('/gulus-tasarimi-nedir/', 'Gülüş Tasarımı Nedir? | Atlas Diş', 'Gülüş Tasarımı Nedir?', 900);
        $this->page('/dis-eti-bakimi/', 'Diş Eti Bakımı Nasıl Yapılır? | Atlas Diş', 'Diş Eti Bakımı Nasıl Yapılır?', 800);

        // Hub queries (single-type, no place): service, relevance, intent, Search Console metrics.
        $this->hub('implant tedavisi', $this->implant, 1000, 14.0);
        $this->hub('diş implantı tedavisi', $this->implant, 200, 16.0);
        $this->hub('implant nasıl yapılır', $this->implant, 400, 4.0);
        $this->hub('implant nasıl takılır', $this->implant, 150, 5.0);
        $this->hub('implant sonrası beslenme', $this->implant, 300, null);
        $this->hub('implant sonrası ne yenir', $this->implant, 120, null);
        $this->hub('şeffaf plak tedavisi', $this->aligner, 500, null);
        $this->hub('şeffaf plak mı tel mi', $this->aligner, 90, null);
        $this->hub('gülüş tasarımı', $this->smile, 600, 12.0);
        $this->hub('atlas diş', null, 800, 1.0, branded: true);
        $this->hub('diş hekimi maaşları', null, 300, null, relevance: 'irrelevant');
        $this->hub('ağız kokusu', null, 100, null, relevance: 'unclear');

        foreach ([
            ['implant tedavisi', '/implant-tedavisi/', 1000, 14.0], ['diş implantı tedavisi', '/implant-tedavisi/', 200, 16.0],
            ['implant nasıl yapılır', '/implant-nasil-yapilir/', 400, 4.0], ['implant nasıl takılır', '/implant-nasil-yapilir/', 150, 5.0],
            ['gülüş tasarımı', '/gulus-tasarimi/', 300, 12.0], ['gülüş tasarımı', '/gulus-tasarimi-nedir/', 300, 15.0],
        ] as $i => [$query, $path, $impressions, $position]) {
            $this->insertFacts('gsc_query_page_daily', [
                'digital_asset_id' => $this->site->id, 'external_resource_id' => null, 'site_url' => 'https://example.test/',
                'reporting_date' => now()->subDays(10)->toDateString(), 'query' => $query, 'page' => 'https://example.test'.$path,
                'clicks' => 5, 'impressions' => $impressions, 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
                'record_fingerprint' => hash('sha256', 'gsc'.$i), 'metadata' => json_encode(['provider_average_position' => $position]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function test_weekly_command_and_hub_rebuild_refresh_the_topic_map(): void
    {
        $this->artisan('moxdop:topics:build')->assertSuccessful();
        $this->assertSame('done', TopicMapBuild::query()->latest('id')->value('status'));
        $this->assertSame('weekly', TopicMapBuild::query()->latest('id')->value('trigger'));

        BuildBrandDemandJob::dispatchSync($this->brand->id);
        $last = TopicMapBuild::query()->latest('id')->first();
        $this->assertSame('hub', $last->trigger);
        $this->assertSame('done', $last->status, (string) $last->error);
        $this->assertSame(2, TopicMapBuild::query()->count());
    }

    public function test_seo_task_card_links_to_the_studio(): void
    {
        app(TopicMapBuilder::class)->build($this->site);
        $cluster = $this->clusterOf('implant sonrası beslenme');
        $plan = SeoPlan::query()->create(['brand_id' => $this->brand->id, 'customer_id' => $this->brand->customer_id, 'digital_asset_id' => $this->site->id, 'status' => 'completed', 'completed_at' => now()]);
        SeoTask::query()->create([
            'customer_id' => $this->brand->customer_id, 'brand_id' => $this->brand->id, 'digital_asset_id' => $this->site->id, 'task_key' => 'k1', 'type' => 'create',
            'rule_id' => 'create-guide', 'severity' => 'high', 'priority_score' => 900, 'title' => 'Rehber yaz: implant sonrası beslenme', 'reason' => 'r',
            'evidence' => ['source' => 'topic_map', 'studio' => ['cluster' => $cluster->id], 'queries' => []], 'checklist' => [], 'status' => 'open',
            'first_seen_plan_id' => $plan->id, 'last_seen_plan_id' => $plan->id,
        ]);
        $this->actingAs($this->admin);

        Livewire::test(SeoTasksPanel::class, ['websiteId' => $this->site->id])->assertSee('Stüdyoda hazırla')
            ->assertSeeHtml('studio_cluster='.$cluster->id);
    }

    public function test_topic_map_groups_hub_queries_by_service_and_judges_owner_coverage_and_verdict(): void
    {
        $stats = app(TopicMapBuilder::class)->build($this->site);

        $this->assertSame(9, $stats['queries'], 'relevant + service-assigned queries; branded, irrelevant and unclear-without-service are left out');
        $how = $this->clusterOf('implant nasıl yapılır');
        $this->assertSame($how->id, $this->clusterOf('implant nasıl takılır')->id, 'same topic → one cluster');
        $this->assertSame($this->implant->id, (int) $how->brand_offering_id);
        $this->assertSame('none', $how->verdict, 'covered by the existing post');
        $this->assertSame('covered', $how->coverage);
        $this->assertSame('https://example.test/implant-nasil-yapilir/', $how->owner_url);
        $this->assertSame('search_console', $how->owner_source);
        $this->assertSame('informational', $how->intent);
        $this->assertStringContainsString('İmplant Nasıl Yapılır?', (string) data_get($how->verdict_detail, 'reason'));

        $treatment = $this->clusterOf('implant tedavisi');
        $this->assertSame($treatment->id, $this->clusterOf('diş implantı tedavisi')->id);
        $this->assertSame('strengthen', $treatment->verdict, 'owner page exists but ranks 14');
        $this->assertSame('weak', $treatment->coverage);
        $this->assertSame('service', $treatment->page_type);
        $this->assertSame('commercial', $treatment->intent);
        $this->assertContains('implant tedavisi', (array) data_get($treatment->verdict_detail, 'missing_queries'));

        $after = $this->clusterOf('implant sonrası beslenme');
        $this->assertSame($after->id, $this->clusterOf('implant sonrası ne yenir')->id);
        $this->assertSame('new', $after->verdict);
        $this->assertSame('uncovered', $after->coverage);
        $this->assertNull($after->owner_url);
        $this->assertSame('İmplant sonrası beslenme', $after->label);
        $this->assertSame(420.0, (float) $after->demand_score);

        $aligner = $this->clusterOf('şeffaf plak tedavisi');
        $this->assertSame('none', $aligner->verdict, 'inventory title covers it');
        $this->assertSame('inventory', $aligner->owner_source);
        $comparison = $this->clusterOf('şeffaf plak mı tel mi');
        $this->assertSame('comparison', $comparison->page_type);

        $smile = $this->clusterOf('gülüş tasarımı');
        $this->assertSame('merge', $smile->verdict, 'two pages share the same queries');
        $this->assertCount(2, (array) $smile->cannibal_urls);

        $this->assertFalse(TopicClusterQuery::query()->where('query', 'atlas diş')->exists());
        $this->assertFalse(TopicClusterQuery::query()->where('query', 'diş hekimi maaşları')->exists());
        $this->assertSame(1, $stats['version']);
    }

    public function test_operator_edits_survive_a_rebuild(): void
    {
        $builder = app(TopicMapBuilder::class);
        $editor = app(TopicMapEditor::class);
        $builder->build($this->site);
        $after = $this->clusterOf('implant sonrası beslenme');
        $treatment = $this->clusterOf('implant tedavisi');
        $how = $this->clusterOf('implant nasıl yapılır');
        $comparison = $this->clusterOf('şeffaf plak mı tel mi');
        $aligner = $this->clusterOf('şeffaf plak tedavisi');

        $editor->rename($after, 'İmplant sonrası beslenme rehberi', $this->admin);
        $editor->skip($treatment, true, $this->admin);
        $editor->moveQuery(TopicClusterQuery::query()->where('query', 'implant nasıl takılır')->sole(), $after, $this->admin);
        $editor->merge($comparison, $aligner, $this->admin);
        $split = $editor->split($after->refresh(), [TopicClusterQuery::query()->where('query', 'implant sonrası ne yenir')->value('id')], 'İmplant sonrası yemek', $this->admin);

        $stats = $builder->build($this->site->refresh());

        $this->assertSame(2, $stats['version']);
        $this->assertSame('İmplant sonrası beslenme rehberi', $after->refresh()->label, 'renamed label kept');
        $this->assertSame('active', $after->status);
        $this->assertSame('skipped', $treatment->refresh()->status, 'skip kept');
        $this->assertSame($treatment->id, $this->clusterOf('implant tedavisi')->id, 'same cluster identity');
        $this->assertSame($after->id, $this->clusterOf('implant nasıl takılır')->id, 'moved query stays');
        $this->assertTrue(TopicClusterQuery::query()->where('query', 'implant nasıl takılır')->value('pinned'));
        $this->assertSame($how->id, $this->clusterOf('implant nasıl yapılır')->id);
        $this->assertSame('merged', $comparison->refresh()->status);
        $this->assertSame($aligner->id, $this->clusterOf('şeffaf plak mı tel mi')->id, 'merged query stays in the target');
        $this->assertSame($split->id, $this->clusterOf('implant sonrası ne yenir')->id, 'split cluster kept');
        $this->assertSame('İmplant sonrası yemek', $split->refresh()->label);

        // Skipped clusters produce no ideas and no SEO tasks.
        $ideas = app(ContentIdeaPlanner::class)->fromClusters($this->site, null, 20, false)['ideas'];
        $this->assertNotContains($treatment->id, array_map(fn (ContentIdea $i): ?int => $i->topic_cluster_id, $ideas));
    }

    public function test_ideas_link_real_urls_and_never_repropose_existing_topics(): void
    {
        $this->enableAi();
        app(TopicMapBuilder::class)->build($this->site);
        $result = app(ContentIdeaPlanner::class)->fromClusters($this->site, [$this->implant->id], 10, false);

        $idea = collect($result['ideas'])->firstWhere('topic_cluster_id', $this->clusterOf('implant sonrası beslenme')->id);
        $this->assertNotNull($idea);
        $this->assertSame('İmplant sonrası beslenme: Bilmeniz Gerekenler', $idea->title);
        $this->assertSame('implant sonrası beslenme', $idea->focus_keyword);
        $this->assertSame('guide', $idea->page_type);
        $this->assertSame('https://example.test/implant-sonrasi-beslenme/', $idea->target_url, 'root slugs, like the site');
        $links = array_column((array) $idea->internal_links, 'url');
        $this->assertSame('https://example.test/implant-tedavisi/', $links[0], 'the service page first');
        $this->assertGreaterThanOrEqual(2, count($links));
        $inventory = DB::table('website_page_profiles')->where('website_asset_id', $this->site->id)->pluck('preferred_url')->all();
        foreach ($links as $url) {
            $this->assertContains($url, $inventory, 'internal links are real inventory URLs');
        }
        $this->assertNotEmpty($idea->outline);
        $this->assertCount(4, (array) $idea->faq);
        $this->assertFalse(collect($result['ideas'])->contains(fn (ContentIdea $i): bool => $i->topic_cluster_id === $this->clusterOf('implant nasıl yapılır')->id), 'covered topics give no idea');

        // "Konu üret": 3 ideas = 1 from the topic map + AI for the gap (one call); written topics and rule breakers are dropped.
        ContentIdeaAgent::fake([['ideas' => [
            ['service' => 'İmplant Tedavisi', 'title' => 'İmplant Nasıl Yapılır?', 'focus_keyword' => 'implant nasıl yapılır', 'page_type' => 'guide', 'target_queries' => [], 'outline' => [], 'faq' => []],
            ['service' => 'İmplant Tedavisi', 'title' => 'En iyi implant markaları', 'focus_keyword' => 'implant markaları', 'page_type' => 'comparison', 'target_queries' => [], 'outline' => [], 'faq' => []],
            ['service' => 'İmplant Tedavisi', 'title' => 'İmplant sonrası ağız bakımı', 'focus_keyword' => 'implant sonrası ağız bakımı', 'page_type' => 'guide',
                'target_queries' => ['implant bakımı'], 'outline' => [['h2' => 'İmplant bakımı neden önemli?', 'h3' => []], ['h2' => 'Günlük bakım adımları', 'h3' => ['Fırçalama', 'Diş ipi']]],
                'faq' => ['İmplant nasıl temizlenir']],
        ]]]);
        app(ContentIdeaAi::class)->queue($this->site, [$this->implant->id], 3, $this->admin);

        ContentIdeaAgent::assertPrompted(fn ($prompt): bool => str_contains((string) $prompt->prompt, '"existing_titles"') && str_contains((string) $prompt->prompt, 'İmplant Nasıl Yapılır?') && str_contains((string) $prompt->prompt, '"count":3'));
        $titles = ContentIdea::query()->where('digital_asset_id', $this->site->id)->pluck('title')->all();
        $this->assertContains('İmplant sonrası ağız bakımı', $titles);
        $this->assertNotContains('İmplant Nasıl Yapılır?', $titles, 'already written on the site');
        $this->assertNotContains('En iyi implant markaları', $titles, 'sector rules');
        $ai = ContentIdea::query()->where('title', 'İmplant sonrası ağız bakımı')->sole();
        $this->assertSame('ai', $ai->source);
        $this->assertSame([['h2' => 'İmplant bakımı neden önemli?', 'h3' => []], ['h2' => 'Günlük bakım adımları', 'h3' => ['Fırçalama', 'Diş ipi']]], $ai->outline);
        $this->assertSame(['İmplant nasıl temizlenir?'], $ai->faq);
        $this->assertStringContainsString('2 konu hazır', (string) app(ContentIdeaAi::class)->state($this->site->id));
        $this->assertSame(1, AiProduction::query()->where('kind', 'content.ideas')->count());

        // Manual idea close to an existing post shows it.
        $manual = app(ContentIdeaPlanner::class)->addManual($this->site, 'Diş eti bakımı ipuçları', null, $this->admin);
        $this->assertSame('https://example.test/dis-eti-bakimi/', data_get($manual->similar_existing, 'url'));
    }

    public function test_bulk_write_produces_ready_articles_and_reprompts_once_on_violations(): void
    {
        $this->enableAi();
        app(TopicMapBuilder::class)->build($this->site);
        $ideas = app(ContentIdeaPlanner::class)->fromClusters($this->site, null, 10, false)['ideas'];
        $after = collect($ideas)->firstWhere('topic_cluster_id', $this->clusterOf('implant sonrası beslenme')->id);
        $comparison = collect($ideas)->firstWhere('topic_cluster_id', $this->clusterOf('şeffaf plak mı tel mi')->id);
        $this->assertNotNull($comparison);

        $good = fn (string $title): array => ['title' => $title, 'meta_title' => $title.' — hekim önerileriyle kapsamlı bir rehber ve sık sorulan sorular', 'meta_description' => 'Kısa açıklama.',
            'focus_keyword' => 'implant sonrası beslenme', 'slug' => 'Implant Sonrasi Beslenme', 'excerpt' => 'Özet.', 'categories' => ['Implant'],
            'html' => '<p>implant sonrası beslenme hakkında bilgi.</p><h2>Bölüm</h2><ul><li>a</li></ul><p><a href="https://example.test/implant-tedavisi/">implant</a> ve <a href="https://example.test/gizli-sayfa/">uydurma</a> ve <a href="https://www.saglik.gov.tr/">bakanlık</a></p><h2>Sık sorulan sorular</h2><h3>Soru?</h3><p>Cevap.</p>'];
        ArticleWriterAgent::fake([
            ['title' => 'İmplant sonrası en iyi beslenme', 'meta_title' => 'x', 'meta_description' => 'y', 'focus_keyword' => 'z', 'slug' => 'a', 'excerpt' => '', 'categories' => [], 'html' => '<p>Garantili sonuç.</p>'],
            $good('İmplant sonrası beslenme'),
            $good('Şeffaf plak mı tel mi?'),
        ]);

        $result = app(ContentStudio::class)->queueWrite($this->site, [$after->id, $comparison->id], [], $this->admin);

        $this->assertSame(2, $result['queued']);
        ArticleWriterAgent::assertPrompted(fn ($prompt): bool => str_contains((string) $prompt->prompt, '"violations"') && str_contains((string) $prompt->prompt, '"phrase":"en iyi"'));
        ArticleWriterAgent::assertPrompted(fn ($prompt): bool => str_contains((string) $prompt->prompt, '"internal_links"') && str_contains((string) $prompt->prompt, 'https://example.test/implant-tedavisi/'));
        $article = ContentArticle::query()->where('content_idea_id', $after->id)->sole();
        $this->assertSame('ready', $article->status, (string) $article->error);
        $this->assertSame(2, $article->attempts, 'one re-prompt');
        $html = (string) data_get($article->payload, 'html');
        $this->assertStringContainsString('href="https://example.test/implant-tedavisi/"', $html);
        $this->assertStringNotContainsString('gizli-sayfa', $html, 'unknown internal URL unwrapped');
        $this->assertStringContainsString('uydurma', $html);
        $this->assertStringContainsString('href="https://www.saglik.gov.tr/"', $html, 'external links stay');
        $this->assertStringContainsString('hekiminize danışın', $html, 'health disclaimer appended');
        $this->assertLessThanOrEqual(60, mb_strlen((string) data_get($article->payload, 'meta_title')));
        $this->assertSame('implant-sonrasi-beslenme', data_get($article->payload, 'slug'));
        $this->assertSame([['name' => 'Implant']], data_get($article->payload, 'categories'));
        $this->assertNotNull($article->ai_production_id);
        $this->assertSame('written', $after->refresh()->status);
        $this->assertContains('Metin kısa: '.$article->word_count.' kelime (hedef 850–1100).', (array) data_get($article->quality, 'issues'));
        $this->assertSame('ready', ContentArticle::query()->where('content_idea_id', $comparison->id)->value('status'));

        // Still violating after the re-prompt → "uyum sorunu var"; delivery refused.
        $manual = app(ContentIdeaPlanner::class)->addManual($this->site, 'İmplant bakımı', $this->implant->id, $this->admin);
        ArticleWriterAgent::fake([
            ['title' => 'İmplant bakımı', 'meta_title' => 'x', 'meta_description' => 'y', 'focus_keyword' => 'z', 'slug' => 'a', 'excerpt' => '', 'categories' => [], 'html' => '<p>Ağrısız ve garantili.</p>'],
            ['title' => 'İmplant bakımı', 'meta_title' => 'x', 'meta_description' => 'y', 'focus_keyword' => 'z', 'slug' => 'a', 'excerpt' => '', 'categories' => [], 'html' => '<p>Yine garantili.</p>'],
        ]);
        app(ContentStudio::class)->queueWrite($this->site, [$manual->id], [], $this->admin);
        $bad = ContentArticle::query()->where('content_idea_id', $manual->id)->sole();
        $this->assertSame('needs_fix', $bad->status);
        $this->assertNotSame([], $bad->blockingViolations());
        $this->actingAs($this->admin)->get(route('operator.website.wxr-export', ['site' => $this->site->id, 'articles' => $bad->id]))->assertStatus(422)->assertSee('sektör uyum kurallarına takılıyor');
        $this->expectException(ValidationException::class);
        app(ContentStudio::class)->publish($this->admin, $bad);
    }

    public function test_polylang_site_gets_linked_language_versions_dates_and_wxr(): void
    {
        $this->enableAi();
        $this->siteLanguages(['tr' => true, 'en' => false]);
        app(TopicMapBuilder::class)->build($this->site);
        $ideas = app(ContentIdeaPlanner::class)->fromClusters($this->site, null, 10, false)['ideas'];
        $after = collect($ideas)->firstWhere('topic_cluster_id', $this->clusterOf('implant sonrası beslenme')->id);
        $comparison = collect($ideas)->firstWhere('topic_cluster_id', $this->clusterOf('şeffaf plak mı tel mi')->id);
        ArticleWriterAgent::fake([$this->articleResponse('İmplant sonrası beslenme'), $this->articleResponse('Şeffaf plak mı tel mi?')]);
        ContentLocalizerAgent::fake([
            ['title' => 'Eating after dental implants', 'slug' => 'eating-after-dental-implants', 'meta_title' => 'Eating after implants', 'meta_description' => 'Information.', 'focus_keyword' => 'eating after implant',
                'excerpt' => 'Short.', 'html' => '<p>Information about <a href="https://example.test/implant-tedavisi/">implants</a>.</p>', 'categories' => ['Implant']],
            ['title' => 'Clear aligners or braces?', 'slug' => 'clear-aligners-or-braces', 'meta_title' => 'Aligners or braces', 'meta_description' => 'Information.', 'focus_keyword' => 'clear aligners',
                'excerpt' => 'Short.', 'html' => '<p>Information.</p>', 'categories' => ['Implant']],
        ]);

        app(ContentStudio::class)->queueWrite($this->site, [$after->id, $comparison->id], ['en', 'fr'], $this->admin);

        $source = ContentArticle::query()->where('content_idea_id', $after->id)->whereNull('source_article_id')->sole();
        $this->assertSame('tr', $source->language);
        $this->assertSame('moxdop-article-'.$source->id, $source->translation_key);
        $en = $source->translations()->sole();
        $this->assertSame('en', $en->language, 'only languages the site has');
        $this->assertSame('ready', $en->status, (string) $en->error);
        $this->assertSame($source->translation_key, $en->translation_key);
        $this->assertSame('Eating after dental implants', $en->title);
        $this->assertStringContainsString('href="https://example.test/en/"', (string) data_get($en->payload, 'html'), 'internal link → language home');
        $this->assertSame(1, AiProduction::query()->where('kind', 'content.localized')->where('subject_id', $en->id)->count());

        $second = ContentArticle::query()->where('content_idea_id', $comparison->id)->whereNull('source_article_id')->sole();
        $count = app(ContentStudio::class)->schedule($this->site, [$second->id, $source->id], '2030-03-01', '10:00', 1);
        $this->assertSame(2, $count);
        $this->assertSame('2030-03-01 07:00:00', $second->refresh()->scheduled_at->utc()->format('Y-m-d H:i:s'), 'order of selection, Istanbul time');
        $this->assertSame('2030-03-02 07:00:00', $source->refresh()->scheduled_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2030-03-02 07:00:00', $en->refresh()->scheduled_at->utc()->format('Y-m-d H:i:s'), 'language version follows its source');
        app(ContentStudio::class)->schedule($this->site, [$second->id, $source->id], '2030-03-01', '10:00', 2);
        $this->assertSame('2030-03-01 11:00:00', $source->refresh()->scheduled_at->utc()->format('Y-m-d H:i:s'), 'two a day, 4 hours apart');

        $this->actingAs($this->member)->get(route('operator.website.wxr-export', ['site' => $this->site->id, 'articles' => $source->id]))->assertForbidden();
        $response = $this->actingAs($this->admin)->get(route('operator.website.wxr-export', ['site' => $this->site->id, 'articles' => $source->id.','.$second->id]))->assertOk();
        $xml = $response->getContent();
        $this->assertStringContainsString('Eating after dental implants', $xml);
        $this->assertStringContainsString('_moxdop_translation_key', $xml);
        $this->assertStringContainsString('<![CDATA[moxdop-article-'.$source->id.']]>', $xml);
        $this->assertSame(4, substr_count($xml, '<item>'));
        $this->assertSame('exported', $source->refresh()->status);
        $this->assertSame('exported', $en->refresh()->status);
    }

    public function test_send_to_wordpress_goes_through_the_content_draft_publisher(): void
    {
        $this->enableAi();
        $this->siteLanguages(['tr' => true, 'en' => false]);
        $this->connectWordPress();
        app(TopicMapBuilder::class)->build($this->site);
        $idea = app(ContentIdeaPlanner::class)->ideaForCluster($this->clusterOf('implant sonrası beslenme'))['idea'];
        ArticleWriterAgent::fake([$this->articleResponse('İmplant sonrası beslenme')]);
        ContentLocalizerAgent::fake([['title' => 'Eating after implants', 'slug' => 'eating-after-implants', 'meta_title' => 'Eating after implants', 'meta_description' => 'Info.',
            'focus_keyword' => 'eating after implant', 'excerpt' => 'Short.', 'html' => '<p>Info.</p>', 'categories' => ['Implant']]]);
        app(ContentStudio::class)->queueWrite($this->site, [$idea->id], ['en'], $this->admin);
        $source = ContentArticle::query()->whereNull('source_article_id')->sole();
        app(ContentStudio::class)->schedule($this->site, [$source->id], '2030-05-01', '10:00', 1);

        $this->actingAs($this->member);
        Livewire::test(ContentStudioPanel::class, ['websiteId' => $this->site->id])->call('publishArticle', $source->id)->assertSee('yalnız Admin');
        $this->assertSame(0, ExternalWriteAction::query()->count());

        $action = app(ContentStudio::class)->publish($this->admin, $source->refresh());

        $this->assertSame(ExternalWriteAction::ACTION_ARTICLE_DRAFTS, $action->action);
        $this->assertSame('succeeded', $action->refresh()->status, (string) $action->error);
        $posts = array_values(array_filter($this->sent, fn (array $s): bool => $s[0] === 'POST' && str_ends_with($s[1], '/drafts')));
        $this->assertSame(['tr', 'en'], array_column(array_column($posts, 2), 'language'));
        $this->assertSame('moxdop-article-'.$source->id, $posts[0][2]['reference']);
        $this->assertSame(101, $posts[1][2]['translation_of']);
        $this->assertSame('2030-05-01T07:00:00+00:00', $posts[0][2]['post_date']);
        $this->assertArrayNotHasKey('schedule', $posts[0][2], 'drafts stay drafts');
        $this->assertSame('sent', $source->refresh()->status);
        $this->assertSame($action->id, $source->translations()->value('external_write_action_id'));
    }

    public function test_seo_tasks_create_from_clusters_with_location_ideas_from_service_areas_only(): void
    {
        $this->brand->serviceAreas()->create(['country_code' => 'TR', 'country_name' => 'Türkiye', 'city_name' => 'İstanbul', 'district_name' => 'Kadıköy', 'normalized_key' => 'tr:istanbul:kadikoy', 'status' => 'active']);
        $this->brand->serviceAreas()->create(['country_code' => 'TR', 'country_name' => 'Türkiye', 'city_name' => 'İstanbul', 'district_name' => 'Maltepe', 'normalized_key' => 'tr:istanbul:maltepe', 'status' => 'active']);
        $this->page('/kadikoy-implant-tedavisi/', 'Kadıköy İmplant Tedavisi', 'Kadıköy İmplant Tedavisi', 700);
        // A place-bearing query from another city: no "out of area" card any more.
        $this->insertFacts('gsc_query_page_daily', ['digital_asset_id' => $this->site->id, 'external_resource_id' => null, 'site_url' => 'https://example.test/',
            'reporting_date' => now()->subDays(10)->toDateString(), 'query' => 'ankara implant', 'page' => 'https://example.test/implant-tedavisi/', 'clicks' => 0, 'impressions' => 900,
            'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => hash('sha256', 'ankara'), 'metadata' => json_encode(['provider_average_position' => 40]),
            'created_at' => now(), 'updated_at' => now()]);

        $collector = app(SeoPlanInputCollector::class);
        $this->assertNull($collector->collect($this->site)['topic_clusters'], 'no map yet');
        app(TopicMapBuilder::class)->build($this->site);
        $input = $collector->collect($this->site);
        $tasks = collect((new SeoTaskRuleEngine)->evaluate($input)['tasks']);

        $this->assertNull($tasks->firstWhere('rule_id', 'out-of-area-demand'));
        $create = $tasks->where('type', 'create');
        $fromMap = $create->first(fn (array $t): bool => ($t['evidence']['studio']['cluster'] ?? null) === $this->clusterOf('implant sonrası beslenme')->id);
        $this->assertNotNull($fromMap, 'uncovered cluster → create task with a studio link');
        $this->assertSame('topic_map', $fromMap['evidence']['source']);
        $this->assertContains('implant sonrası beslenme', $fromMap['content_brief']['queries']);
        $this->assertFalse($create->contains(fn (array $t): bool => ($t['evidence']['studio']['cluster'] ?? null) === $this->clusterOf('implant nasıl yapılır')->id), 'covered clusters give no task');
        $this->assertFalse($create->contains(fn (array $t): bool => in_array('ankara implant', array_column($t['evidence']['queries'] ?? [], 'query'), true)));
        $locations = $create->filter(fn (array $t): bool => ($t['evidence']['source'] ?? '') === 'service_area')->values();
        $this->assertCount(1, $locations, 'Kadıköy already has a page; only Maltepe × the priority service');
        $this->assertSame(['offering' => $this->implant->id, 'area' => 'Maltepe'], $locations[0]['evidence']['studio']);
        $this->assertSame('create-location', $locations[0]['rule_id']);
        $strengthen = $tasks->first(fn (array $t): bool => $t['type'] === 'strengthen' && $t['target_url'] === 'https://example.test/implant-tedavisi/');
        if ($strengthen !== null) {
            $this->assertSame($this->clusterOf('implant tedavisi')->id, $strengthen['evidence']['studio']['cluster']);
        }

        // Location ideas: brand areas × services, one per pair, never for a covered pair.
        $ideas = app(ContentIdeaPlanner::class)->locationIdeas($this->site, [$this->implant->id], 10);
        $this->assertSame(['Maltepe'], array_map(fn (ContentIdea $i): string => (string) $i->location, $ideas));
        $this->assertSame('Maltepe İmplant Tedavisi: Süreç ve Sık Sorulanlar', $ideas[0]->title);
        $this->assertSame('location', $ideas[0]->page_type);
        $this->assertCount(1, app(ContentIdeaPlanner::class)->locationIdeas($this->site, [$this->implant->id], 10), 'no duplicate on a second run');

        // Faz 0 inventory guard: no page list → no create task even with a topic map.
        $input['pages'] = [];
        $this->assertFalse(collect((new SeoTaskRuleEngine)->evaluate($input)['tasks'])->contains(fn (array $t): bool => $t['type'] === 'create'));
    }

    public function test_work_is_refused_outside_the_service_scope(): void
    {
        $this->enableAi();
        app(TopicMapBuilder::class)->build($this->site);
        $idea = app(ContentIdeaPlanner::class)->ideaForCluster($this->clusterOf('implant sonrası beslenme'))['idea'];
        $this->customer->forceFill(['status' => CustomerStatus::Inactive])->save();
        app(ServiceScope::class)->flush();

        try {
            app(TopicMapBuilder::class)->build($this->site->refresh());
            $this->fail('expected a refusal');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('hizmet', mb_strtolower($exception->getMessage()));
        }
        foreach ([fn () => app(TopicMapBuilder::class)->queue($this->site), fn () => app(ContentStudio::class)->queueWrite($this->site, [$idea->id], [], $this->admin),
            fn () => app(ContentIdeaAi::class)->queue($this->site, [$this->implant->id], 5, $this->admin)] as $call) {
            try {
                $call();
                $this->fail('expected a refusal');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
        $this->assertSame(0, ContentArticle::query()->count());
        $this->actingAs($this->admin);
        Livewire::test(ContentStudioPanel::class, ['websiteId' => $this->site->id])->assertSee('hizmet kapsamında değil')->call('writeIdea', $idea->id);
        $this->assertSame(0, ContentArticle::query()->count());
    }

    public function test_studio_renders_and_its_main_actions_work(): void
    {
        $this->enableAi();
        $this->actingAs($this->admin);
        $this->get(route('operator.website', ['assetId' => $this->site->id, 'tab' => 'studio']))->assertOk()->assertSee('İçerik Stüdyosu')->assertSee('Konu haritası henüz kurulmadı');

        $component = Livewire::test(ContentStudioPanel::class, ['websiteId' => $this->site->id])
            ->call('rebuildMap')->assertSee('Konu haritası yenileniyor')
            ->set('verdictFilter', 'all')
            ->assertSee('İmplant sonrası beslenme')->assertSee('Yeni içerik')->assertSee('Güçlendir')->assertSee('Birleştir')->assertSee('Gerek yok')
            ->assertSee('Güncelleme taslağı hazırla');
        $after = $this->clusterOf('implant sonrası beslenme');
        $component->call('startRename', $after->id)->set('clusterLabel', 'Beslenme rehberi')->call('saveRename')->assertSee('Beslenme rehberi');
        $component->call('ideaFromCluster', $after->id)->assertSet('view', 'ideas')->assertSee('İmplant sonrası beslenme: Bilmeniz Gerekenler')->assertSee('Taslak plan, SSS ve iç bağlantılar');
        $idea = ContentIdea::query()->where('topic_cluster_id', $after->id)->sole();
        $component->call('confirmBulk')->assertSee('Tahmini AI maliyeti');

        ArticleWriterAgent::fake([$this->articleResponse('İmplant sonrası beslenme')]);
        $component->call('writeSelected')->assertSet('view', 'articles')->assertSee('Hazır')->assertSee('İmplant sonrası beslenme');
        $article = ContentArticle::query()->where('content_idea_id', $idea->id)->sole();
        $component->call('openArticle', $article->id)->set('articleEdit.html', '<p>Garantili sonuç.</p>')->call('saveArticle', $article->id)->assertSee('hâlâ uyum kuralına takılıyor');
        $this->assertSame('needs_fix', $article->refresh()->status);
        $component->set('articleEdit.html', '<p>implant sonrası beslenme için bilgi.</p>')->call('saveArticle', $article->id)->assertSee('uyum kontrolünden geçti');
        $this->assertSame('ready', $article->refresh()->status);
        $component->set('selectedArticles', [$article->id => true])->set('scheduleStart', '2030-01-10')->call('scheduleSelected')->assertSee('1 yazıya tarih atandı')
            ->assertSee('XML indir');

        // "Güçlendir": an ADR-070 page-text proposal for the owner page with the missing queries as brief.
        DB::table('website_cms_object_snapshot')->insert(['digital_asset_id' => $this->site->id, 'cms' => 'wordpress', 'object_type' => 'page', 'object_id' => '44', 'status' => 'publish',
            'permalink' => 'https://example.test/implant-tedavisi/', 'title' => 'İmplant Tedavisi', 'observed_at' => now(), 'contract_version' => 1, 'first_collected_at' => now(),
            'last_collected_at' => now(), 'record_fingerprint' => hash('sha256', 'o44'), 'metadata' => json_encode([]), 'created_at' => now(), 'updated_at' => now()]);
        $component->call('prepareUpdate', $this->clusterOf('implant tedavisi')->id)->assertSee('Güncelleme taslağı hazırlanıyor');
        $fix = SiteFixItem::query()->where('type', 'content_update')->sole();
        $this->assertSame('44', $fix->object_id);
        $this->assertSame('studio', data_get($fix->current, 'source'));
        $this->assertContains('implant tedavisi', (array) data_get($fix->current, 'brief.add_queries'));

        // Deep link from an SEO task: the idea of the cluster is opened and selected.
        Livewire::withQueryParams(['studio_cluster' => $this->clusterOf('şeffaf plak mı tel mi')->id])->test(ContentStudioPanel::class, ['websiteId' => $this->site->id])
            ->assertSet('view', 'ideas')->assertSee('Şeffaf plak mı tel mi: Farklar ve Hangi Durumda Hangisi?');

        $this->get(route('operator.brand', ['brand' => $this->brand->id, 'tab' => 'business']))->assertOk()->assertSee('İçerik Stüdyosu');
    }

    /** @return array<string, mixed> */
    private function articleResponse(string $title): array
    {
        return ['title' => $title, 'meta_title' => $title, 'meta_description' => 'Hekim bilgilendirmesi.', 'focus_keyword' => mb_strtolower($title), 'slug' => Str::slug($title), 'excerpt' => 'Özet.',
            'categories' => ['İmplant'], 'html' => '<p>'.mb_strtolower($title).' hakkında bilgi.</p><h2>Süreç</h2><ul><li>Adım</li></ul><h2>Sık sorulan sorular</h2><h3>Soru?</h3><p>Cevap.</p>'];
    }

    private function clusterOf(string $query): TopicCluster
    {
        return TopicCluster::query()->findOrFail(TopicClusterQuery::query()->where('digital_asset_id', $this->site->id)->where('query', $query)->value('topic_cluster_id'));
    }

    private function hub(string $query, ?BrandOffering $offering, int $impressions, ?float $position, bool $branded = false, string $relevance = 'relevant'): void
    {
        BrandDemandQuery::query()->create([
            'brand_id' => $this->brand->id, 'query' => $query, 'query_key' => hash('sha256', SeoText::fold($query)), 'brand_offering_id' => $offering?->id,
            'is_branded' => $branded, 'relevance' => $relevance, 'intent' => null, 'sources' => ['search_console'], 'source_mask' => 1,
            'gsc_impressions' => $impressions, 'gsc_clicks' => 5, 'gsc_position' => $position, 'value_score' => $impressions / 10,
        ]);
    }

    private function page(string $path, string $title, string $h1, int $words = 600): void
    {
        $url = 'https://example.test'.$path;
        $identity = IntelligencePageIdentity::query()->create([
            'uuid' => (string) Str::uuid(), 'website_asset_id' => $this->site->id, 'identity_hash' => hash('sha256', $this->site->id.':'.$url), 'preferred_url' => $url,
            'preferred_url_hash' => hash('sha256', $url), 'scheme' => 'https', 'host' => 'example.test', 'path' => $path,
            'resolution_status' => 'resolved', 'normalization_version' => 'v1', 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        $projection = WebsiteIntelligenceProjectionRun::query()->create([
            'uuid' => (string) Str::uuid(), 'website_asset_id' => $this->site->id, 'trigger' => 'test', 'status' => 'completed',
            'schema_version' => 1, 'intelligence_registry_version' => 1, 'period_start' => now()->subDays(90), 'period_end' => now()->subDay(),
        ]);
        WebsitePageProfile::query()->create([
            'website_asset_id' => $this->site->id, 'page_identity_id' => $identity->id, 'projection_run_id' => $projection->id,
            'preferred_url' => $url, 'profile_version' => 1, 'projected_at' => now(), 'last_observed_at' => now(),
            'source_states' => ['website' => ['url' => $url, 'http' => ['status_code' => 200], 'content' => ['word_count' => $words, 'language' => 'tr'],
                'document_head' => ['title' => $title, 'title_present' => true, 'robots' => 'index'], 'headings' => ['h1' => $h1, 'h1_present' => true]]],
        ]);
    }

    private function enableAi(): void
    {
        config(['moxdop.anthropic.api_key' => 'sk-ant-test']);
        CoreIntegration::factory()->anthropic()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
    }

    /** @param array<string, bool> $languages slug => default */
    private function siteLanguages(array $languages): void
    {
        DB::table('website_cms_site_snapshot')->insert([
            'digital_asset_id' => $this->site->id, 'cms' => 'wordpress', 'site_key' => 'k', 'site_url' => 'https://example.test/', 'home_url' => 'https://example.test/',
            'observed_at' => now(), 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => hash('sha256', 'site'),
            'metadata' => json_encode(['languages' => collect($languages)->map(fn (bool $default, string $slug): array => ['slug' => $slug, 'name' => strtoupper($slug), 'locale' => $slug, 'default' => $default,
                'home_url' => $default ? 'https://example.test/' : 'https://example.test/'.$slug.'/'])->values()->all()]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function connectWordPress(): void
    {
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $this->site->id, 'type' => 'wordpress_connector', 'enabled' => true,
            'config' => ['pairing_state' => 'paired', 'snapshot_url' => 'https://example.test/wp-json/moxdop/v1/snapshot', 'plugin_version' => '1.5.0'],
        ]);
        CoreConnectionCredential::factory()->create(['connection_id' => $connection->id, 'encrypted_payload' => ['client_id' => 'client-1', 'shared_secret' => self::SECRET]]);
        $this->app->instance(WordPressConnectorClient::class, new WordPressConnectorClient(new WordPressConnectorCanonicalJson, new PublicUrlSafety(fn (string $host): array => ['93.184.216.34'])));
        $next = 100;
        Http::fake(function (Request $request) use (&$next) {
            $body = json_decode($request->body(), true);
            $this->sent[] = [$request->method(), $request->url(), $body];
            $id = ++$next;
            $data = ['schema_version' => 1, 'post_id' => $id, 'status' => 'draft', 'slug' => (string) ($body['slug'] ?? ''), 'edit_url' => 'https://example.test/wp-admin/post.php?post='.$id.'&action=edit', 'preview_url' => '',
                'language' => $body['language'] ?? null, 'translations' => [], 'categories' => [], 'tags' => [], 'seo_provider' => 'yoast'];
            $nonce = $request->header(WordPressConnectorClient::HEADER_NONCE)[0] ?? '';
            $time = now()->timestamp;
            $signature = hash_hmac('sha256', implode("\n", [(string) $time, $nonce, hash('sha256', (new WordPressConnectorCanonicalJson)->encode($data))]), self::SECRET);

            return Http::response(['data' => $data, 'meta' => ['server_time' => $time, 'request_nonce' => $nonce, 'signature' => $signature]]);
        });
    }
}
