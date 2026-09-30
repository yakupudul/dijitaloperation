<?php

namespace Tests\Feature\Site;

use App\Ai\Agents\Site\ApplyChangeAgent;
use App\Ai\Agents\Site\ContentDiscoveryAgent;
use App\Ai\Agents\Site\PageCategoriesAgent;
use App\Ai\Agents\Site\PageSummaryAgent;
use App\Ai\Agents\Site\StandardFromDecisionAgent;
use App\Ai\Agents\Site\UrlAnalysisAgent;
use App\Ai\Agents\Site\WeeklyContentAgent;
use App\Ai\Agents\Site\WriteArticleAgent;
use App\Enums\CustomerStatus;
use App\Jobs\ExecuteExternalWriteJob;
use App\Jobs\Site\RunSiteOperationJob;
use App\Livewire\Operator\Website\V2\ClustersPagesTab;
use App\Livewire\Operator\Website\V2\ContentTab;
use App\Livewire\Operator\Website\V2\SettingsTab;
use App\Livewire\Operator\Website\V2\SuggestionsTab;
use App\Models\Brand;
use App\Models\BrandClusterPage;
use App\Models\BrandMemory;
use App\Models\Cluster;
use App\Models\CoreConnection;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\OfferingPage;
use App\Models\Page;
use App\Models\Query;
use App\Models\ServiceCategory;
use App\Models\Suggestion;
use App\Services\Queries\QueryNormalizer;
use App\Services\Site\BrandMemoryService;
use App\Services\Site\ChangeApplier;
use App\Services\Site\ClusterPageMapper;
use App\Services\Site\ContentPlanner;
use App\Services\Site\PageCategorizer;
use App\Services\Site\ScopedStandards;
use App\Services\Site\SiteOperations;
use App\Services\Site\UrlAnalyzer;
use App\Services\Website\Pages\PageStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use MoxDop\Website\Standards\WebsiteStandardCatalog;

/**
 * Faz 4a: brand memory (relevant parts only, page change → recheck), URL analysis validation, "AI ile yap" → approved
 * WordPress write + baseline, "Bu karardan standart öner" (scoped, versioned), İçerik (weekly plan, discovery, library,
 * article), screens and the operational-brand AI gate.
 */
final class SiteSuggestionsTest extends SiteTestCase
{
    private Page $implantPage;

    private Page $zirkonyumPage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->implantPage = $this->page('/implant/', 'Ankara İmplant Tedavisi', ['category' => 'hizmet', 'wp_post_id' => 42, 'wp_post_type' => 'page',
            'meta_description' => 'Panorama Ankara implant tedavisi.', 'content_text' => 'İmplant süreci adım adım anlatılır. Kliniğimizde 12 uzman hekim çalışır.']);
        $this->zirkonyumPage = $this->page('/zirkonyum-kaplama/', 'Zirkonyum Kaplama', ['category' => 'hizmet', 'wp_post_id' => 43]);
        OfferingPage::query()->create(['brand_offering_id' => $this->implantOffering->id, 'page_id' => $this->implantPage->id, 'source' => 'rule']);
        OfferingPage::query()->create(['brand_offering_id' => $this->zirkonyumOffering->id, 'page_id' => $this->zirkonyumPage->id, 'source' => 'rule']);
    }

    public function test_memory_context_returns_only_the_relevant_parts(): void
    {
        $implantPrices = $this->page('/implant-fiyat/', 'İmplant Bilgi', ['category' => 'hizmet']);
        $blog = $this->page('/blog/dis-bakimi/', 'Diş bakımı', ['category' => 'blog']);
        OfferingPage::query()->create(['brand_offering_id' => $this->implantOffering->id, 'page_id' => $implantPrices->id, 'source' => 'rule']);
        foreach ([$this->implantPage, $implantPrices, $blog, $this->zirkonyumPage] as $page) {
            $page->update(['content_summary' => 'Özet: '.$page->title]);
        }
        $onImplant = $this->suggestion(['page_id' => $this->implantPage->id, 'title' => 'İmplant kararı']);
        $onZirkonyum = $this->suggestion(['page_id' => $this->zirkonyumPage->id, 'title' => 'Zirkonyum kararı']);
        $memory = app(BrandMemoryService::class);
        $memory->recordDecision($onImplant, 'onaylandı');
        $memory->recordDecision($onZirkonyum, 'reddedildi', 'marka istemiyor');
        $standards = app(ScopedStandards::class);
        $fields = ['rule' => 'Hizmet sayfasında randevu bağlantısı olmalı.', 'condition' => '', 'exceptions' => ''];
        $brandWide = $standards->save($onImplant, $fields + ['title' => 'Marka standardı', 'scope' => 'brand'], $this->admin);
        $urlOnly = $standards->save($onImplant, $fields + ['title' => 'URL standardı', 'scope' => 'url'], $this->admin);
        $otherUrl = $standards->save($onZirkonyum, $fields + ['title' => 'Başka URL standardı', 'scope' => 'url'], $this->admin);

        $context = BrandMemory::contextFor($this->brand, [$this->implantPage->id], []);

        $this->assertSame('Panorama Ankara', $context['profile']['name']);
        $this->assertSame(['Diş İmplantı', 'Zirkonyum Kaplama'], array_column($context['profile']['services'], 'name'));
        $this->assertSame([$this->implantPage->id], array_column($context['pages'], 'id'));
        $this->assertSame([$implantPrices->id], array_column($context['related_pages'], 'id'), 'same service only, not the blog or another service');
        $this->assertSame(['İmplant kararı'], array_column($context['decisions'], 'title'));
        $ids = array_column($context['standards'], 'id');
        $this->assertContains($brandWide, $ids);
        $this->assertContains($urlOnly, $ids);
        $this->assertNotContains($otherUrl, $ids, 'a URL standard of another page does not apply');
    }

    public function test_page_change_flags_open_suggestions_for_recheck_and_drops_the_summary(): void
    {
        $open = $this->suggestion(['page_id' => $this->implantPage->id, 'title' => 'Açık öneri']);
        $dismissed = $this->suggestion(['page_id' => $this->implantPage->id, 'title' => 'Reddedilen', 'status' => Suggestion::DISMISSED]);
        $this->implantPage->update(['content_summary' => 'Eski özet']);
        BrandMemory::query()->create(['brand_id' => $this->brand->id, 'kind' => 'page', 'ref_type' => 'page', 'ref_id' => $this->implantPage->id, 'summary' => 'Eski özet']);

        app(PageStore::class)->upsert($this->site->id, ['url' => $this->implantPage->url, 'title' => 'Ankara İmplant Tedavisi', 'content_text' => 'Yeni içerik.', 'wp_post_id' => 42]);

        $this->assertSame(Suggestion::RECHECK, $open->fresh()->status);
        $this->assertSame(Suggestion::DISMISSED, $dismissed->fresh()->status);
        $this->assertNull($this->implantPage->fresh()->content_summary);
        $this->assertSame(0, BrandMemory::query()->where('kind', 'page')->count());
    }

    public function test_url_analysis_keeps_grounded_suggestions_and_drops_invented_urls_and_numbers(): void
    {
        $this->enableAi();
        $this->fact('implant tedavisi', '/implant/', 200, 20, 3.0);
        PageSummaryAgent::fake([['pages' => [
            ['page_id' => $this->implantPage->id, 'summary' => 'Sayfa implant sürecini anlatır ve uzman hekim bilgisini verir.', 'facts' => ['12 uzman hekim', '99 şube']],
            ['page_id' => $this->zirkonyumPage->id, 'summary' => 'Zirkonyum kaplama sayfası kısa bilgi verir ve randevuya yönlendirir.', 'facts' => []],
        ]]]);
        $prompts = [];
        UrlAnalysisAgent::fake(function (string $prompt) use (&$prompts): array {
            $prompts[] = $prompt;

            return ['suggestions' => [
                ['type' => 'title_description', 'title' => 'Başlığa hedef sorguyu ekle', 'reason' => 'Sayfa 200 gösterimde 20 tık aldı.', 'priority' => 1, 'cluster_id' => 777,
                    'evidence' => [['kind' => 'number', 'value' => '200', 'source' => 'Search Console'], ['kind' => 'quote', 'value' => 'İmplant süreci adım adım', 'source' => 'sayfa'],
                        ['kind' => 'url', 'value' => 'https://panorama.com.tr/zirkonyum-kaplama/', 'source' => 'site']]],
                ['type' => 'internal_links', 'title' => 'Olmayan sayfaya bağlantı ver', 'reason' => 'https://panorama.com.tr/olmayan/ sayfası güçlü.', 'priority' => 2, 'cluster_id' => null, 'evidence' => []],
                ['type' => 'missing_topic', 'title' => 'Fiyat bölümü ekle', 'reason' => 'Sayfa 5400 gösterim aldı.', 'priority' => 2, 'cluster_id' => null, 'evidence' => []],
                ['type' => 'conversion', 'title' => 'Randevu butonu ekle', 'reason' => 'Sayfada bir sonraki adım yok.', 'priority' => 3, 'cluster_id' => null,
                    'evidence' => [['kind' => 'quote', 'value' => 'sayfada olmayan cümle', 'source' => 'sayfa'], ['kind' => 'number', 'value' => '777', 'source' => 'GA4']]],
                ['type' => 'uydurma_tur', 'title' => 'Geçersiz tür', 'reason' => 'x', 'priority' => 1, 'cluster_id' => null, 'evidence' => []],
                ['type' => 'missing_topic', 'title' => 'Garantili sonuç bölümü ekle', 'reason' => 'Sayfada sonuç anlatılmıyor.', 'priority' => 1, 'cluster_id' => null, 'evidence' => []],
            ]];
        });

        $result = app(UrlAnalyzer::class)->analyze($this->site, [$this->implantPage->id]);

        $this->assertSame(['status' => 'ready', 'pages' => 1, 'suggestions' => 2], $result);
        $this->assertStringContainsString('"ga4_28d":"veri yok"', $prompts[0], 'missing data is said, not guessed');
        $this->assertStringContainsString('Sayfa implant sürecini anlatır', $prompts[0], 'lazy summary in the pack');
        $rows = Suggestion::query()->orderBy('priority')->get();
        $this->assertSame(['title_description', 'conversion'], $rows->pluck('action_type')->all());
        $this->assertCount(3, $rows[0]->evidence);
        $this->assertNull($rows[0]->cluster_id, 'unknown cluster id is not trusted');
        $this->assertSame([['kind' => 'none', 'value' => 'veri yok', 'source' => '']], $rows[1]->evidence);
        $this->assertSame($this->implantPage->id, $rows[0]->page_id);
        $this->assertNotNull($rows[0]->prompt_version_id);
        $memory = BrandMemory::query()->where('kind', 'page')->where('ref_id', $this->implantPage->id)->sole();
        $this->assertSame(['12 uzman hekim'], $memory->data['facts'], 'a fact with an invented number is dropped');

        // Re-analysis: open suggestions it no longer repeats go; decided ones stay.
        $rows[1]->update(['status' => Suggestion::DISMISSED]);
        UrlAnalysisAgent::fake([['suggestions' => []]]);
        app(UrlAnalyzer::class)->analyze($this->site, [$this->implantPage->id]);
        $this->assertSame([Suggestion::DISMISSED], Suggestion::query()->pluck('status')->all());
    }

    public function test_ai_do_shows_new_version_and_approval_queues_the_wordpress_write_with_baseline(): void
    {
        $this->enableAi();
        Queue::fake();
        $this->connector();
        $this->fact('implant tedavisi', '/implant/', 200, 20, 3.0);
        $suggestion = $this->suggestion(['page_id' => $this->implantPage->id, 'action_type' => 'title_description', 'title' => 'Başlığı güçlendir']);
        ApplyChangeAgent::fake([[
            'seo_title' => 'Ankara İmplant Tedavisi | Panorama', 'meta_description' => 'Ankara Çankaya’da implant süreci, 12 uzman hekimle planlanır.', 'html' => null,
            'internal_links' => [['anchor' => 'zirkonyum kaplama', 'url' => 'https://panorama.com.tr/zirkonyum-kaplama/'], ['anchor' => 'dış', 'url' => 'https://evil.example/']],
            'schema_json' => null, 'note' => 'Başlık ve açıklama yenilendi.',
        ]]);

        $this->assertSame(['status' => 'ready'], app(ChangeApplier::class)->prepare($suggestion));

        $proposal = $suggestion->fresh()->action['proposal'];
        $this->assertSame('fields', $proposal['kind']);
        $this->assertSame([['anchor' => 'zirkonyum kaplama', 'url' => 'https://panorama.com.tr/zirkonyum-kaplama/']], $proposal['new']['internal_links'], 'links only to site pages');
        Livewire::test(SuggestionsTab::class, ['assetId' => $this->site->id])
            ->call('open', $suggestion->id)
            ->assertSee('Ankara İmplant Tedavisi | Panorama')->assertSee('Panorama Ankara implant tedavisi.')
            ->call('applyChange', $suggestion->id)->assertHasNoErrors();

        $write = ExternalWriteAction::query()->where('suggestion_id', $suggestion->id)->sole();
        $this->assertSame(ExternalWriteAction::ACTION_SITE_FIX, $write->action);
        $this->assertSame(['seo_title', 'seo_description', 'internal_link'], array_column($write->request_payload['changes'], 'type'));
        $this->assertSame(42, $write->request_payload['changes'][0]['object_id']);
        Queue::assertPushed(ExecuteExternalWriteJob::class);
        $fresh = $suggestion->fresh();
        $this->assertSame(Suggestion::APPLIED, $fresh->status);
        $this->assertNotNull($fresh->applied_at);
        $this->assertSame(20, $fresh->baseline['clicks']);
        $this->assertSame(200, $fresh->baseline['impressions']);
        $this->assertSame($this->implantPage->url, $fresh->baseline['scope']['url']);
        $this->assertSame('onaylandı', BrandMemory::query()->where('kind', 'decision')->sole()->data['decision']);
    }

    public function test_ai_do_output_failing_the_sector_compliance_gate_is_not_shown(): void
    {
        $this->enableAi();
        $suggestion = $this->suggestion(['page_id' => $this->implantPage->id, 'action_type' => 'title_description', 'title' => 'Başlığı güçlendir']);
        ApplyChangeAgent::fake([['seo_title' => 'Garantili implant tedavisi', 'meta_description' => null, 'html' => null, 'internal_links' => [], 'schema_json' => null, 'note' => '']]);

        $result = app(ChangeApplier::class)->prepare($suggestion);

        $this->assertSame('blocked', $result['status']);
        $this->assertArrayNotHasKey('proposal', (array) $suggestion->fresh()->action);
        $this->assertStringContainsString('garanti', mb_strtolower((string) $suggestion->fresh()->action['proposal_blocked']));
    }

    public function test_standard_from_decision_is_saved_scoped_and_versioned_and_applies_only_in_scope(): void
    {
        $this->enableAi();
        $suggestion = $this->suggestion(['page_id' => $this->implantPage->id, 'title' => 'Randevu adımı ekle', 'status' => Suggestion::APPROVED]);
        StandardFromDecisionAgent::fake([['title' => 'Hizmet sayfasında randevu adımı', 'rule' => 'Her hizmet sayfası randevu bağlantısıyla bitmeli.', 'condition' => 'hizmet sayfaları', 'exceptions' => '', 'scope' => 'brand']]);

        $this->assertSame(['status' => 'ready'], app(ScopedStandards::class)->propose($suggestion));

        Livewire::test(SuggestionsTab::class, ['assetId' => $this->site->id])->set('status', '')
            ->call('open', $suggestion->id)->assertSet('standard.scope', 'brand')
            ->set('standard.scope', 'sector')->call('saveStandard', $suggestion->id)->assertHasNoErrors();

        $row = DB::table('website_standard_settings')->where('created_from_suggestion_id', $suggestion->id)->sole();
        $this->assertSame('sector', $row->scope_type);
        $this->assertSame($this->dental->id, (int) $row->scope_id);
        $this->assertSame(1, (int) $row->version);
        $definition = app(WebsiteStandardCatalog::class)->all()[$row->standard_id];
        $this->assertSame('sector', $definition['scope_type']);

        $legal = ServiceCategory::query()->firstOrCreate(['code' => 'legal'], ['name' => 'Hukuk', 'normalized_key' => 'hukuk']);
        $other = Brand::factory()->create(['customer_id' => $this->brand->customer_id, 'sector_id' => $legal->id]);
        $this->assertContains($row->standard_id, array_column(app(ScopedStandards::class)->forContext($this->brand, []), 'id'));
        $this->assertNotContains($row->standard_id, array_column(app(ScopedStandards::class)->forContext($other, []), 'id'), 'another sector does not get it');

        $this->assertSame(2, app(ScopedStandards::class)->update($row->standard_id, ['title' => 'Randevu adımı', 'rule' => 'Her hizmet sayfası randevu bağlantısı içermeli.'], $this->admin));
        $this->assertSame('sector', DB::table('website_standard_settings')->where('standard_id', $row->standard_id)->value('scope_type'), 'scope stays');
    }

    public function test_weekly_content_plan_and_out_of_cluster_discovery_with_library_and_article_draft(): void
    {
        $this->enableAi();
        Queue::fake();
        $this->connector();
        $this->brand->forceFill(['weekly_content_capacity' => 5])->save();
        $this->page('/blog/implant-sonrasi-agri/', 'İmplant sonrası ağrı', ['category' => 'blog', 'wp_post_type' => 'post']);
        $this->page('/en/dental-implant/', 'Dental Implant', ['category' => 'hizmet', 'language' => 'en']);
        $gap = $this->cluster($this->implant, 'İmplant sonrası bakım', ['implant sonrası bakım'], [], 'informational');
        BrandClusterPage::query()->create(['brand_id' => $this->brand->id, 'cluster_id' => $gap->id, 'website_asset_id' => $this->site->id, 'language' => 'tr', 'state' => 'no_page']);
        $this->suggestion(['action_type' => 'content', 'title' => 'İmplant sonrası beslenme', 'page_id' => null, 'created_at' => now()->subWeeks(2)]);
        $prompts = [];
        WeeklyContentAgent::fake(function (string $prompt) use (&$prompts, $gap): array {
            $prompts[] = $prompt;
            $item = fn (string $title, string $kind, ?string $url, ?int $cluster = null): array => ['title' => $title, 'kind' => $kind, 'cluster_id' => $cluster, 'page_type' => 'blog',
                'target_url' => $url, 'outline' => ['Giriş', 'İlk 24 saat'], 'questions' => ['İmplant sonrası ne yenir?'], 'reason' => 'Kümenin uygun sayfası yok.'];

            return ['items' => [
                $item('İmplant sonrası ilk hafta', 'new', null, $gap->id),
                $item('Zirkonyum kaplama sayfasını genişlet', 'update', 'https://panorama.com.tr/zirkonyum-kaplama/'),
                $item('Olmayan sayfayı güncelle', 'update', 'https://panorama.com.tr/olmayan/'),
                $item('İmplant fiyatları rehberi', 'new', null),
                $item('İmplant sonrası beslenme', 'new', null),
            ]];
        });

        $this->assertSame(['status' => 'ready', 'added' => 2], app(ContentPlanner::class)->weekly($this->site));

        $this->assertStringContainsString('İmplant sonrası beslenme', $prompts[0], 'previous plans are in the pack');
        $this->assertStringContainsString('"capacity":5', $prompts[0]);
        $new = Suggestion::query()->where('title', 'İmplant sonrası ilk hafta')->sole();
        $this->assertSame('https://panorama.com.tr/blog/implant-sonrasi-ilk-hafta/', $new->action['target_url'], 'site URL pattern, not invented');
        $this->assertSame($gap->id, $new->cluster_id);
        $this->assertSame(['İmplant sonrası ne yenir?'], $new->action['questions']);
        $this->assertSame($this->zirkonyumPage->id, Suggestion::query()->where('title', 'Zirkonyum kaplama sayfasını genişlet')->value('page_id'));

        // Discovery: queries outside every cluster → "küme dışı" → library.
        $free = Query::query()->create(['text' => 'diş taşı temizliği', 'text_hash' => QueryNormalizer::hash('diş taşı temizliği'), 'sector_id' => $this->dental->id, 'assignment' => 'none']);
        DB::table('brand_queries')->insert(['brand_id' => $this->brand->id, 'query_id' => $free->id, 'clicks_28d' => 4, 'impressions_28d' => 90, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('brand_queries')->insert(['brand_id' => $this->brand->id, 'query_id' => $gap->mainQuery->id, 'clicks_28d' => 1, 'impressions_28d' => 50, 'created_at' => now(), 'updated_at' => now()]);
        $discoveryPrompts = [];
        ContentDiscoveryAgent::fake(function (string $prompt) use (&$discoveryPrompts, $free): array {
            $discoveryPrompts[] = $prompt;

            return ['items' => [
                ['title' => 'Diş taşı temizliği ne sıklıkla yapılır', 'service_id' => $this->implant->id, 'query_ids' => [$free->id, 99999], 'new_queries' => ['diş taşı temizleme sıklığı'],
                    'page_type' => 'blog', 'outline' => ['Diş taşı nedir'], 'questions' => ['Diş taşı temizliği acıtır mı?'], 'reason' => 'Kümesiz sorgu 90 gösterim aldı.'],
                ['title' => 'Kanıtsız fırsat', 'service_id' => null, 'query_ids' => [], 'new_queries' => [], 'page_type' => 'blog', 'outline' => [], 'questions' => [], 'reason' => ''],
            ]];
        });
        $this->assertSame(['status' => 'ready', 'added' => 1], app(ContentPlanner::class)->discover($this->site));
        $this->assertStringNotContainsString('implant sonrası bakım', $discoveryPrompts[0], 'clustered queries are not sent');
        $discovery = Suggestion::query()->where('title', 'Diş taşı temizliği ne sıklıkla yapılır')->sole();
        $this->assertTrue($discovery->action['out_of_cluster']);
        $this->assertSame([$free->id], $discovery->action['query_ids']);

        Livewire::test(ContentTab::class, ['assetId' => $this->site->id])->assertSee('küme dışı')
            ->call('addToLibrary', $discovery->id)->assertHasNoErrors();
        $cluster = Cluster::query()->where('name', 'Diş taşı temizliği ne sıklıkla yapılır')->sole();
        $this->assertFalse($cluster->approved);
        $this->assertSame($free->id, $cluster->main_query_id);
        $this->assertTrue(Query::query()->where('text', 'diş taşı temizleme sıklığı')->sole()->is_suggested);

        // Taslak hazırla → validated article → WordPress draft (Admin).
        WriteArticleAgent::fake([[
            'title' => 'İmplant sonrası ilk hafta', 'slug' => 'implant-sonrasi-ilk-hafta', 'meta_title' => 'İmplant sonrası ilk hafta', 'meta_description' => 'İlk hafta nelere dikkat edilir?',
            'excerpt' => 'İlk hafta rehberi.', 'html' => '<h2>İlk 24 saat</h2><p>Soğuk uygulama yapılır. <a href="https://evil.example/x">tıkla</a></p><p>Hastaların %87 si memnun kalır.</p><p>Bkz. <a href="https://panorama.com.tr/implant/">implant</a></p>',
        ]]);
        $this->assertSame(['status' => 'ready'], app(ContentPlanner::class)->writeArticle($new));
        $article = $new->fresh()->action['article'];
        $this->assertStringNotContainsString('evil.example', $article['html'], 'external links are unlinked');
        $this->assertStringNotContainsString('%87', $article['html'], 'a block with an invented number is dropped');
        $this->assertStringContainsString('https://panorama.com.tr/implant/', $article['html']);
        $this->assertStringContainsString('en', (string) $new->fresh()->action['article_note'], 'other languages are skipped with a note');
        Livewire::test(ContentTab::class, ['assetId' => $this->site->id])->call('sendDraft', $new->id)->assertHasNoErrors();
        $this->assertSame(ExternalWriteAction::ACTION_ARTICLE_DRAFTS, ExternalWriteAction::query()->sole()->action);
    }

    public function test_screen_renders_tabs_and_main_actions_queue_jobs(): void
    {
        Queue::fake();
        $this->fact('implant tedavisi', '/implant/', 200, 20, 3.0);
        $suggestion = $this->suggestion(['page_id' => $this->implantPage->id, 'title' => 'Başlığı güçlendir', 'reason' => 'Sayfa 200 gösterim aldı.']);
        $other = $this->suggestion(['page_id' => $this->implantPage->id, 'title' => 'Bağlantı ekle', 'action_type' => 'internal_links']);

        $this->get(route('operator.website', ['assetId' => $this->site->id]))->assertOk()
            ->assertSee('Özet')->assertSee('Sayfalar')->assertSee('Sorgular')->assertSee('Sağlık')->assertSee('Ayarlar')
            ->assertSee('Ana hizmet sayfaları')->assertSee('Başlığı güçlendir')->assertSee('Organik tıklama');
        $this->get(route('operator.website', ['assetId' => $this->site->id, 'tab' => 'health']))->assertOk()->assertSee('Site Sağlığı')->assertDontSee('Hazırlanıyor');
        // Old "SEO Yapılacaklar › Kümeler & Sayfalar" link lands on Sorgular › Kümeler & Sayfalar.
        $this->get(route('operator.website', ['assetId' => $this->site->id, 'tab' => 'seo', 'sub' => 'kumeler']))->assertOk()->assertSee('Kümeler &amp; Sayfalar', false)->assertSee('/implant/');

        Livewire::test(ClustersPagesTab::class, ['assetId' => $this->site->id])
            ->call('run', SiteOperations::CATEGORIZE)
            ->call('setCategory', $this->implantPage->id, 'lokasyon')
            ->call('setOffering', $this->zirkonyumPage->id, (string) $this->implantOffering->id)
            ->set('selected', [$this->implantPage->id, $this->zirkonyumPage->id])->call('analyzeSelected')->assertHasNoErrors();
        Queue::assertPushed(RunSiteOperationJob::class, fn (RunSiteOperationJob $job): bool => $job->operation === SiteOperations::CATEGORIZE);
        Queue::assertPushed(RunSiteOperationJob::class, fn (RunSiteOperationJob $job): bool => $job->operation === SiteOperations::URL_ANALYSIS && $job->params['page_ids'] === [$this->implantPage->id, $this->zirkonyumPage->id]);
        $this->assertTrue($this->implantPage->fresh()->category_locked);
        $this->assertTrue(OfferingPage::query()->where('page_id', $this->zirkonyumPage->id)->sole()->locked);

        Livewire::test(SuggestionsTab::class, ['assetId' => $this->site->id])
            ->assertSee('Başlığı güçlendir')
            ->call('approve', $suggestion->id)
            ->call('dismiss', $other->id)->assertHasErrors('reason')
            ->set('reasons.'.$other->id, 'Marka istemiyor')->call('dismiss', $other->id)->assertHasNoErrors()
            ->call('aiDo', $suggestion->id);
        $this->assertSame(Suggestion::APPROVED, $suggestion->fresh()->status);
        $this->assertSame('Marka istemiyor', $other->fresh()->operator_note);
        $this->assertSame(2, BrandMemory::query()->where('kind', 'decision')->count());
        Queue::assertPushed(RunSiteOperationJob::class, fn (RunSiteOperationJob $job): bool => $job->operation === SiteOperations::APPLY_CHANGE && $job->params === ['suggestion_id' => $suggestion->id]);

        Livewire::test(SettingsTab::class, ['assetId' => $this->site->id])
            ->set('sitemapUrl', 'https://panorama.com.tr/sitemap_index.xml')->call('saveSitemap')
            ->set('capacity', 6)->call('saveCapacity')->assertSee('/implant/')
            ->call('unlockCategory', $this->implantPage->id)->assertHasNoErrors();
        $this->assertSame('https://panorama.com.tr/sitemap_index.xml', $this->site->fresh()->sitemap_url);
        $this->assertSame(6, (int) $this->brand->fresh()->weekly_content_capacity);
        $this->assertFalse($this->implantPage->fresh()->category_locked);

        // The job stores a one-line status the screen reads.
        (new RunSiteOperationJob($this->site->id, SiteOperations::CATEGORIZE))->handle(app(SiteOperations::class));
        $this->assertSame('ready', SiteOperations::status($this->site->id, SiteOperations::CATEGORIZE)['status']);
    }

    public function test_non_operational_brand_gets_no_ai(): void
    {
        $this->enableAi();
        Queue::fake();
        $this->brand->customer->update(['status' => CustomerStatus::Inactive]);
        $this->page('/kampanyalar/', 'Kampanyalar');
        PageCategoriesAgent::fake([['pages' => []]]);
        UrlAnalysisAgent::fake([['suggestions' => []]]);
        WeeklyContentAgent::fake([['items' => []]]);
        $suggestion = $this->suggestion(['page_id' => $this->implantPage->id, 'action_type' => 'title_description', 'title' => 'Başlığı güçlendir']);

        $this->assertSame('not_operational', app(PageCategorizer::class)->categorize($this->site)['status']);
        $this->assertSame('not_operational', app(UrlAnalyzer::class)->analyze($this->site, [$this->implantPage->id])['status']);
        $this->assertSame('not_operational', app(ContentPlanner::class)->weekly($this->site)['status']);
        $this->assertSame('not_operational', app(ChangeApplier::class)->prepare($suggestion)['status']);
        $this->assertSame('not_operational', app(SiteOperations::class)->run($this->site, SiteOperations::WEEKLY_REFRESH)['status']);
        $this->assertSame('no_clusters', app(ClusterPageMapper::class)->refresh($this->site)['status']);
        PageCategoriesAgent::assertNeverPrompted();
        UrlAnalysisAgent::assertNeverPrompted();
        WeeklyContentAgent::assertNeverPrompted();
        ApplyChangeAgent::assertNeverPrompted();

        $active = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id]);
        $activeSite = DigitalAsset::factory()->create(['brand_id' => $active->id, 'type' => 'website', 'domain' => 'aktif.example', 'primary_url' => 'https://aktif.example/']);
        $this->artisan('moxdop:site:weekly')->assertSuccessful();
        Queue::assertPushed(RunSiteOperationJob::class, 1);
        Queue::assertPushed(RunSiteOperationJob::class, fn (RunSiteOperationJob $job): bool => $job->siteId === $activeSite->id);
    }

    /** @param  array<string, mixed>  $overrides */
    private function suggestion(array $overrides): Suggestion
    {
        $title = (string) ($overrides['title'] ?? 'Öneri');
        $suggestion = Suggestion::query()->create(array_merge([
            'brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => 'site.test', 'fingerprint' => hash('sha256', $title.random_int(1, PHP_INT_MAX)),
            'material_hash' => hash('sha256', 'x'), 'title' => $title, 'reason' => 'Gerekçe.', 'priority' => 2, 'action_type' => 'title_description',
            'status' => Suggestion::OPEN, 'action' => ['site_id' => $this->site->id], 'evidence' => [],
        ], array_diff_key($overrides, ['created_at' => true])));
        if (isset($overrides['created_at'])) {
            $suggestion->forceFill(['created_at' => $overrides['created_at']])->save();
        }

        return $suggestion;
    }

    private function connector(): void
    {
        CoreConnection::factory()->create([
            'digital_asset_id' => $this->site->id, 'type' => 'wordpress_connector', 'enabled' => true,
            'config' => ['pairing_state' => 'paired', 'snapshot_url' => 'https://panorama.com.tr/wp-json/moxdop/v1/snapshot', 'plugin_version' => '1.5.0'],
        ]);
    }
}
