<?php

namespace Tests\Feature\Site;

use App\Jobs\Site\RunSiteOperationJob;
use App\Livewire\Operator\Library\WebsiteStandardsPage;
use App\Livewire\Operator\Website\V2\AnalysisTab;
use App\Livewire\Operator\Website\V2\CompetitorsTab;
use App\Livewire\Operator\Website\V2\ContentIdeasTab;
use App\Livewire\Operator\Website\V2\SuggestionsTab;
use App\Models\BrandClusterPage;
use App\Models\BrandClusterSerp;
use App\Models\BrandMemory;
use App\Models\BrandQuery;
use App\Models\CompetitorPage;
use App\Models\CoreConnection;
use App\Models\DigitalAsset;
use App\Models\OfferingPage;
use App\Models\Page;
use App\Models\Query;
use App\Models\Suggestion;
use App\Services\Outcomes\OutcomeTracker;
use App\Services\Queries\ClusterEditor;
use App\Services\Queries\QueryNormalizer;
use App\Services\Site\Analysis\SiteAnalysisReader;
use App\Services\Site\BrandMemoryService;
use App\Services\Site\ClusterPageMapper;
use App\Services\Site\Competitors\CompetitorPageStore;
use App\Services\Site\SiteMetrics;
use App\Services\Site\SiteOperations;
use App\Services\Site\UrlAnalyzer;
use App\Services\Website\Pages\MainContentExtractor;
use App\Services\Website\Pages\PageStore;
use App\Services\Website\Pages\SitemapPageSync;
use App\Services\Website\Pages\WordPressPageSync;
use App\Services\Website\SitemapChangeWatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use MoxDop\Website\Standards\WebsiteStandardCatalog;

/**
 * v2 düzeltmeleri — web sitesi ekranı: extra URLs + every site language per cluster, competitor examples and audience in
 * the URL pack, standard versions from the library, applications / outcomes / H2 sections in brand memory, re-check on
 * deletion / URL / template change, Rakipler actions, Hedef sorgular, screen caches, sitemap for connector sites.
 */
final class SiteScreenFixesTest extends SiteTestCase
{
    private Page $implantPage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->implantPage = $this->page('/implant/', 'Ankara İmplant Tedavisi', ['category' => 'hizmet', 'wp_post_id' => 42,
            'headings' => [['level' => 1, 'text' => 'İmplant'], ['level' => 2, 'text' => 'İmplant süreci'], ['level' => 2, 'text' => 'İyileşme süresi'], ['level' => 3, 'text' => 'Detay']]]);
        OfferingPage::query()->create(['brand_offering_id' => $this->implantOffering->id, 'page_id' => $this->implantPage->id, 'source' => 'rule']);
    }

    public function test_every_site_language_gets_its_own_row_and_the_operator_adds_extra_urls(): void
    {
        $english = $this->page('/en/implant/', 'Dental Implants Ankara', ['category' => 'hizmet', 'language' => 'en']);
        OfferingPage::query()->create(['brand_offering_id' => $this->implantOffering->id, 'page_id' => $english->id, 'source' => 'rule']);
        $guide = $this->page('/blog/implant-rehberi/', 'İmplant rehberi', ['category' => 'blog']);
        $cluster = $this->cluster($this->implant, 'İmplant tedavisi', ['implant tedavisi']);

        app(ClusterPageMapper::class)->refresh($this->site);

        $rows = BrandClusterPage::query()->where('cluster_id', $cluster->id)->orderBy('language')->get();
        $this->assertSame(['en', 'tr'], $rows->pluck('language')->all());
        $this->assertSame([$english->id, $this->implantPage->id], $rows->pluck('page_id')->all());

        $tr = $rows->firstWhere('language', 'tr');
        Livewire::test(ContentIdeasTab::class, ['assetId' => $this->site->id])
            ->set('edit.'.$tr->id.'.extra', [(string) $guide->id])->call('saveMain', $tr->id)->assertHasNoErrors();
        $tr->refresh();
        $this->assertSame([$guide->id], $tr->extra_page_ids);
        $this->assertSame([$this->implantPage->id, $guide->id], $tr->pageIds());
        $this->assertTrue($tr->locked);

        // The extra URL's analysis pack knows the cluster; a refresh keeps the operator's URLs.
        [, , $clusterIds] = app(UrlAnalyzer::class)->pack($this->brand, $this->site, $guide);
        $this->assertSame([$cluster->id], $clusterIds);
        app(ClusterPageMapper::class)->refresh($this->site);
        $this->assertSame([$guide->id], $tr->fresh()->extra_page_ids);
        $this->assertSame(2, BrandClusterPage::query()->where('cluster_id', $cluster->id)->count());

        $other = $this->page('/baska/', 'Başka', ['website_asset_id' => $this->site->id]);
        $foreign = $this->page('/yabanci/', 'Yabancı');
        $foreign->forceFill(['website_asset_id' => DigitalAsset::factory()->create(['type' => 'website'])->id])->save();
        $this->expectException(ValidationException::class);
        app(ClusterEditor::class)->brandRow($tr->fresh(), ['page_id' => $other->id, 'state' => 'sufficient', 'extra_page_ids' => [$foreign->id]]);
    }

    public function test_url_pack_has_competitor_examples_and_the_target_audience(): void
    {
        $this->brand->forceFill(['audience' => 'Ankara’da implant düşünen yetişkinler', 'target_markets' => ['Ankara']])->save();
        $cluster = $this->cluster($this->implant, 'İmplant tedavisi', ['implant tedavisi']);
        BrandClusterPage::query()->create(['brand_id' => $this->brand->id, 'cluster_id' => $cluster->id, 'website_asset_id' => $this->site->id, 'page_id' => $this->implantPage->id, 'language' => 'tr', 'state' => 'sufficient']);
        $results = [];
        foreach ([1 => 'ticari', 2 => 'dizin', 3 => 'ticari', 4 => 'bilgi', 5 => 'ticari', 6 => 'ticari'] as $rank => $class) {
            $url = 'https://rakip'.$rank.'.example/implant';
            $results[] = ['rank' => $rank, 'url' => $url, 'domain' => 'rakip'.$rank.'.example', 'title' => 'R'.$rank, 'class' => $class];
            CompetitorPage::query()->create(['url' => $url, 'url_hash' => CompetitorPageStore::hash($url), 'domain' => 'rakip'.$rank.'.example', 'class' => $class,
                'title' => 'Rakip '.$rank, 'status' => $rank === 3 ? CompetitorPage::MISSING : CompetitorPage::OK,
                'headings' => [['level' => 1, 'text' => 'Başlık'], ['level' => 2, 'text' => 'Fiyat '.$rank], ['level' => 2, 'text' => 'Süreç']]]);
        }
        BrandClusterSerp::query()->create(['brand_id' => $this->brand->id, 'cluster_id' => $cluster->id, 'website_asset_id' => $this->site->id,
            'query' => 'ankara implant tedavisi', 'results' => $results, 'analysis' => ['need' => 'Güvenilir implant kliniği seçmek']]);

        [$pack, $evidence] = app(UrlAnalyzer::class)->pack($this->brand, $this->site, $this->implantPage);

        $this->assertSame('Ankara’da implant düşünen yetişkinler', $pack['brand']['audience']);
        $this->assertSame(['Ankara'], $pack['brand']['target_markets']);
        $examples = $pack['competitor_examples'];
        $this->assertSame('Güvenilir implant kliniği seçmek', $examples[0]['need']);
        // Top 3 fetched commercial / informational pages; directory and unreachable ones are skipped.
        $this->assertSame([1, 4, 5], array_column($examples[0]['pages'], 'rank'));
        $this->assertSame(['Fiyat 1', 'Süreç'], $examples[0]['pages'][0]['headings']);
        $this->assertTrue($evidence->knowsUrl('https://rakip4.example/implant'));

        $page = $this->page('/zirkonyum/', 'Zirkonyum');
        $this->assertSame('veri yok', app(UrlAnalyzer::class)->pack($this->brand, $this->site, $page)[0]['competitor_examples']);
    }

    public function test_decision_standard_is_edited_in_the_library_as_a_new_version_with_history(): void
    {
        $id = WebsiteStandardCatalog::DECISION_PREFIX.'test';
        DB::table('website_standard_settings')->insert(['standard_id' => $id, 'enabled' => true, 'scope_type' => 'brand', 'scope_id' => $this->brand->id, 'version' => 1,
            'custom_definition' => json_encode(['id' => $id, 'version' => 1, 'enabled' => true, 'title' => 'Randevu adımı', 'group' => 'content', 'method' => 'decision_rule',
                'classification' => 'agency_practice', 'required_evidence' => ['decision'], 'asset_type' => 'website', 'platform' => 'general', 'criterion' => 'Kapsam: marka',
                'rule' => 'Her hizmet sayfası randevu bağlantısıyla bitmeli.', 'condition' => 'hizmet sayfaları', 'exceptions' => 'kampanya sayfaları',
                'action' => 'Her hizmet sayfası randevu bağlantısıyla bitmeli.', 'verification' => 'x', 'source_url' => null, 'severity' => 'medium']),
            'created_at' => now(), 'updated_at' => now()]);

        Livewire::test(WebsiteStandardsPage::class)->set('search', 'Randevu')
            ->assertSee('sürüm 1')->assertSee('hizmet sayfaları')->assertSee('kampanya sayfaları')
            ->call('editStandard', $id)->assertSet('draft.condition', 'hizmet sayfaları')
            ->set('draft.rule', 'Her hizmet sayfası randevu formu içermeli.')->set('draft.exceptions', '')
            ->call('saveVersion')->assertHasNoErrors()->assertSee('sürüm 2')->assertSee('Her hizmet sayfası randevu bağlantısıyla');

        $row = DB::table('website_standard_settings')->where('standard_id', $id)->sole();
        $this->assertSame(2, (int) $row->version);
        $this->assertSame('brand', $row->scope_type);
        $definition = json_decode($row->custom_definition, true);
        $this->assertSame('Her hizmet sayfası randevu formu içermeli.', $definition['rule']);
        $this->assertSame([1], array_column($definition['history'], 'version'));
        $this->assertSame('kampanya sayfaları', $definition['history'][0]['exceptions']);
    }

    public function test_memory_keeps_applications_outcomes_and_h2_sections(): void
    {
        $memory = app(BrandMemoryService::class);
        $suggestion = $this->suggestion(['page_id' => $this->implantPage->id, 'title' => 'Başlığı düzelt', 'status' => Suggestion::APPROVED]);
        $memory->recordDecision($suggestion, 'onaylandı');
        app(OutcomeTracker::class)->apply($suggestion, $this->admin);
        $suggestion->forceFill(['outcome' => ['d28' => ['verdict' => OutcomeTracker::WORKED, 'reason' => 'tık 20 → 30 (+%50)']]])->save();
        $memory->recordOutcome($suggestion);

        $decision = BrandMemory::query()->where('kind', 'decision')->sole()->data;
        $this->assertSame('onaylandı', $decision['decision']);
        $this->assertSame(now()->toDateString(), $decision['applied_at']);
        $this->assertSame(OutcomeTracker::WORKED, $decision['outcome']['d28']['verdict']);

        $context = $memory->contextFor($this->brand, [$this->implantPage->id], []);
        $this->assertSame(OutcomeTracker::WORKED, $context['decisions'][0]['outcome']['d28']['verdict']);
        $this->assertSame(['İmplant süreci', 'İyileşme süresi'], $context['pages'][0]['sections']);
    }

    public function test_deletion_url_change_and_template_change_flag_open_and_approved_suggestions(): void
    {
        $open = $this->suggestion(['page_id' => $this->implantPage->id, 'title' => 'Açık']);
        $approved = $this->suggestion(['page_id' => $this->implantPage->id, 'title' => 'Onaylı', 'status' => Suggestion::APPROVED]);
        $applied = $this->suggestion(['page_id' => $this->implantPage->id, 'title' => 'Uygulandı', 'status' => Suggestion::APPLIED, 'applied_at' => now()]);

        // URL changed (same content).
        app(PageStore::class)->upsert($this->site->id, ['url' => 'https://panorama.com.tr/implant-tedavisi/', 'title' => 'Ankara İmplant Tedavisi', 'h1' => 'Ankara İmplant Tedavisi',
            'language' => 'tr', 'content_text' => 'Ankara İmplant Tedavisi sayfası.', 'wp_post_id' => 42]);
        $this->assertSame([Suggestion::RECHECK, Suggestion::RECHECK, Suggestion::APPLIED], [$open->fresh()->status, $approved->fresh()->status, $applied->fresh()->status]);

        // Shared template change: every open / approved-not-applied suggestion of the site.
        $other = $this->page('/zirkonyum/', 'Zirkonyum', ['wp_post_id' => 43]);
        $onOther = $this->suggestion(['page_id' => $other->id, 'title' => 'Zirkonyum onaylı', 'status' => Suggestion::APPROVED]);
        $this->assertSame(0, app(WordPressPageSync::class)->applyEvents($this->site->id, [(object) ['type' => 'maintenance.theme_changed']])['deleted']);
        $this->assertSame(Suggestion::RECHECK, $onOther->fresh()->status);

        // Deleted page: flagged before its page id is nulled, and still listed in Öneriler.
        $onOther->forceFill(['status' => Suggestion::OPEN])->save();
        app(PageStore::class)->deleteWordPressObject($this->site->id, 43);
        $onOther->refresh();
        $this->assertSame([Suggestion::RECHECK, null], [$onOther->status, $onOther->page_id]);
        Livewire::test(SuggestionsTab::class, ['assetId' => $this->site->id])->set('status', 'recheck')->assertSee('Zirkonyum onaylı');
    }

    public function test_competitor_suggestions_are_decided_and_turned_into_content_or_a_new_version(): void
    {
        Queue::fake();
        $cluster = $this->cluster($this->implant, 'İmplant tedavisi', ['implant tedavisi']);
        $cluster->forceFill(['subtopics' => ['iyileşme süresi']])->save();
        $gap = $this->competitor($cluster->id, 'İmplant sonrası bakım rehberi', null);
        $onPage = $this->competitor($cluster->id, 'Tedavi süresi tablosu ekle', $this->implantPage->id);
        $dismiss = $this->competitor($cluster->id, 'Video ekle', null);
        $otherSite = $this->competitor($cluster->id, 'Başka site önerisi', null, siteId: DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website'])->id);

        $tab = Livewire::test(CompetitorsTab::class, ['assetId' => $this->site->id])
            ->assertSee('İmplant sonrası bakım rehberi')->assertDontSee('Başka site önerisi')
            ->assertSee('Yeni içerik olarak ekle')->assertSee('AI ile yap')
            ->set('reasons.'.$dismiss->id, 'Kapasite yok')->call('dismiss', $dismiss->id)
            ->call('aiDo', $onPage->id)
            ->call('addContent', $gap->id)->assertHasNoErrors()->assertSee('Taslak hazırla');

        $this->assertSame(Suggestion::DISMISSED, $dismiss->fresh()->status);
        Queue::assertPushed(RunSiteOperationJob::class, fn (RunSiteOperationJob $job): bool => $job->operation === SiteOperations::APPLY_CHANGE && $job->params['suggestion_id'] === $onPage->id);
        $gap->refresh();
        $this->assertSame(Suggestion::APPROVED, $gap->status);
        $content = Suggestion::query()->findOrFail($gap->action['content_suggestion_id']);
        $this->assertSame(['content', 'new', 'İmplant sonrası bakım rehberi', $cluster->id, $this->site->id], [$content->action_type, $content->action['kind'], $content->title, $content->cluster_id, $content->action['site_id']]);
        $this->assertSame(['iyileşme süresi'], $content->action['outline']);

        $tab->call('prepareDraft', $gap->id);
        Queue::assertPushed(RunSiteOperationJob::class, fn (RunSiteOperationJob $job): bool => $job->operation === SiteOperations::WRITE_ARTICLE && $job->params['suggestion_id'] === $content->id);
        try {
            $tab->call('approve', $otherSite->id);
        } catch (\Throwable) {
            // another site's suggestion is not found here
        }
        $this->assertSame(Suggestion::OPEN, $otherSite->fresh()->status);
    }

    public function test_hedef_sorgular_read_brand_queries_else_cluster_target_queries_with_search_console(): void
    {
        $cluster = $this->cluster($this->implant, 'İmplant tedavisi', ['implant tedavisi']);
        BrandClusterPage::query()->create(['brand_id' => $this->brand->id, 'cluster_id' => $cluster->id, 'website_asset_id' => $this->site->id, 'page_id' => $this->implantPage->id,
            'language' => 'tr', 'state' => 'sufficient', 'target_query' => 'ankara implant tedavisi']);
        $this->fact('ankara implant tedavisi', '/implant/', 120, 9, 4.0);
        $this->fact('ankara implant tedavisi', '/implant/', 80, 3, 6.0, '2026-09-21');

        $rows = app(SiteAnalysisReader::class)->targetQueries($this->site->fresh(), 28);
        $this->assertSame([['query' => 'ankara implant tedavisi', 'area' => 'Çankaya şubesi', 'clicks' => 12, 'impressions' => 200, 'position' => 4.8, 'url' => $this->implantPage->url]], $rows);
        Livewire::test(AnalysisTab::class, ['assetId' => $this->site->id])->call('setSub', 'targets')->assertSee('Hedef sorgular')->assertSee('ankara implant tedavisi')->assertSee('/implant');

        Cache::flush();
        $query = Query::query()->create(['text' => 'implant fiyatları', 'text_hash' => QueryNormalizer::hash('implant fiyatları'), 'sector_id' => $this->dental->id]);
        BrandQuery::query()->create(['brand_id' => $this->brand->id, 'query_id' => $query->id, 'url' => $this->implantPage->url, 'clicks_28d' => 5, 'impressions_28d' => 90, 'position_28d' => 7.25]);
        $this->assertSame([['query' => 'implant fiyatları', 'area' => '—', 'clicks' => 5, 'impressions' => 90, 'position' => 7.3, 'url' => $this->implantPage->url]],
            app(SiteAnalysisReader::class)->targetQueries($this->site->fresh(), 28));
    }

    public function test_screen_numbers_are_cached_and_cleared_by_the_mapper_and_lists_are_bounded(): void
    {
        $this->fact('implant', '/implant/', 50, 5, 3.0);
        $metrics = app(SiteMetrics::class);
        $this->assertSame(5, array_values($metrics->cachedPageTotals($this->brand, $this->site))[0]['clicks']);
        $this->assertSame(5, $metrics->cachedSiteClicks($this->brand, $this->site)['clicks']);
        $this->fact('implant', '/implant/', 50, 7, 3.0, '2026-09-21');
        $this->assertSame(5, array_values($metrics->cachedPageTotals($this->brand, $this->site))[0]['clicks'], 'cached for 10 minutes');
        $this->assertTrue(Cache::has('site:clicks:'.$this->site->id));

        app(ClusterPageMapper::class)->refresh($this->site);
        $this->assertSame(12, array_values(app(SiteMetrics::class)->cachedPageTotals($this->brand, $this->site))[0]['clicks'], 'the mapper clears the cache');
        $this->assertFalse(Cache::has('site:clicks:'.$this->site->id));

        foreach (range(1, 60) as $i) {
            $this->page('/blog/yazi-'.$i.'/', 'Yazı '.$i, ['category' => 'blog']);
        }
        Livewire::test(ContentIdeasTab::class, ['assetId' => $this->site->id])
            ->assertViewHas('pageOptions', fn (array $options): bool => count($options) === 50)
            ->set('pageSearch', 'yazi-7')
            ->assertViewHas('pageOptions', fn (array $options): bool => array_values($options) === ['/blog/yazi-7/'])
            ->assertViewHas('groups', fn ($groups): bool => $groups->perPage() === 100);
    }

    public function test_sitemap_adds_only_non_wordpress_urls_for_connector_sites_and_prune_keeps_them(): void
    {
        $this->assertSame(200, SitemapPageSync::MAX_FETCH_PER_PASS);
        CoreConnection::factory()->create(['digital_asset_id' => $this->site->id, 'type' => 'wordpress_connector', 'enabled' => true, 'config' => ['pairing_state' => 'paired']]);
        $watcher = new SitemapChangeWatcher(fn (string $url): array => ['ok' => false, 'body' => null]);
        $this->assertNotContains($this->site->id, $watcher->eligibleSiteIds());
        $this->site->forceFill(['sitemap_url' => 'https://panorama.com.tr/extra-sitemap.xml'])->save();
        $this->assertContains($this->site->id, $watcher->eligibleSiteIds());

        $fetched = [];
        $sync = new SitemapPageSync(app(PageStore::class), app(MainContentExtractor::class), function (string $url) use (&$fetched): array {
            $fetched[] = $url;

            return ['html' => '<html><head><title>Kampanya</title></head><body><main><h1>Kampanya</h1><p>Kampanya sayfası metni.</p></main></body></html>', 'final_url' => $url, 'error' => null];
        });
        $stats = $sync->sync($this->site->id, ['https://panorama.com.tr/implant' => ['m' => null], 'https://panorama.com.tr/kampanya/' => ['m' => null]], [], extraOnly: true);

        $this->assertSame(['https://panorama.com.tr/kampanya/'], $fetched, 'the WordPress page path is not fetched');
        $this->assertSame(1, $stats['created']);
        $this->assertSame(0, $stats['removed']);
        $this->assertTrue(Page::query()->whereKey($this->implantPage->id)->exists());

        DB::table('website_cms_object_snapshot')->insert(['digital_asset_id' => $this->site->id, 'cms' => 'wordpress', 'object_id' => '42', 'object_type' => 'page', 'status' => 'publish',
            'observed_at' => now(), 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
        app(WordPressPageSync::class)->pruneAfterFullInventory($this->site->id);
        $this->assertSame(['/implant/', '/kampanya/'], Page::query()->where('website_asset_id', $this->site->id)->orderBy('path')->pluck('path')->all());
    }

    /** @param  array<string, mixed>  $overrides */
    private function suggestion(array $overrides): Suggestion
    {
        $title = (string) ($overrides['title'] ?? 'Öneri');

        return Suggestion::query()->create(array_merge([
            'brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => 'site.test', 'fingerprint' => hash('sha256', $title.random_int(1, PHP_INT_MAX)),
            'material_hash' => hash('sha256', 'x'), 'title' => $title, 'reason' => 'Gerekçe.', 'priority' => 2, 'action_type' => 'title_description',
            'status' => Suggestion::OPEN, 'action' => ['site_id' => $this->site->id], 'evidence' => [],
        ], $overrides));
    }

    private function competitor(int $clusterId, string $title, ?int $pageId, ?int $siteId = null): Suggestion
    {
        return $this->suggestion(['title' => $title, 'action_type' => 'rakip', 'decision_key' => 'rakip', 'cluster_id' => $clusterId, 'page_id' => $pageId, 'action' => ['decision' => 'new_page'],
            'evidence' => ['competitor_urls' => ['https://rakip1.example/a', 'https://rakip2.example/b'], 'query' => 'ankara implant', 'gap' => $pageId === null, 'website_asset_id' => $siteId ?? $this->site->id]]);
    }
}
