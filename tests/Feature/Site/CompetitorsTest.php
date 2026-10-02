<?php

namespace Tests\Feature\Site;

use App\Ai\Agents\Site\CompetitorAnalyzeAgent;
use App\Ai\Agents\Site\CompetitorClassifyAgent;
use App\Enums\CustomerStatus;
use App\Jobs\Site\AnalyzeCompetitorClusterJob;
use App\Jobs\Site\RefreshCompetitorsJob;
use App\Livewire\Operator\Website\V2\CompetitorsTab;
use App\Models\BrandClusterPage;
use App\Models\BrandClusterSerp;
use App\Models\CompetitorPage;
use App\Models\CoreIntegration;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\Integrations\DataForSeo\DataForSeoProviderCredentialService;
use App\Services\Prompts\PromptRegistry;
use App\Services\Site\Competitors\CompetitorAnalyzer;
use App\Services\Site\Competitors\CompetitorRefresher;
use App\Services\Site\Competitors\CompetitorTargets;
use App\Services\Site\Competitors\SerpLocationResolver;
use App\Services\Website\PageFetcher;
use App\Support\Ai\AiRouteRegistry;
use App\Support\ServiceScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** Faz 4b Rakipler: representative queries, cached SERP, domain classes, competitor pages, validated analysis. */
final class CompetitorsTest extends TestCase
{
    use RefreshDatabase;
    use SiteFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSite();
        config(['moxdop.dataforseo.login' => null, 'moxdop.dataforseo.password' => null, 'moxdop.dataforseo.base_url' => 'https://api.dataforseo.com']);
        app(DataForSeoProviderCredentialService::class)->save(CoreIntegration::factory()->dataforseo()->create(), [
            'login' => 'agency@example.com', 'password' => 'not-a-real-password',
        ], $this->admin);
        Cache::put(SerpLocationResolver::CACHE_KEY, [
            ['location_code' => 21167, 'location_name' => 'Cankaya,Ankara,Turkey', 'location_type' => 'District'],
            ['location_code' => 1012782, 'location_name' => 'Ankara,Ankara,Turkey', 'location_type' => 'City'],
        ], now()->addDay());
    }

    public function test_representative_query_adds_the_main_area_only_for_commercial_and_local_intent(): void
    {
        $commercial = $this->cluster('İmplant merkezi', 'implant merkezi', 'commercial');
        $info = $this->cluster('İmplant sonrası ağrı', 'implant sonrası ağrı', 'informational');
        $local = $this->cluster('Ankara implant', 'ankara implant', 'local');
        $this->cluster('Onaysız', 'implant fiyatı', 'commercial', approved: false);

        $this->assertSame('ankara implant merkezi', CompetitorTargets::representativeQuery($commercial, 'ankara'));
        $this->assertSame('implant sonrası ağrı', CompetitorTargets::representativeQuery($info, 'ankara'));
        $this->assertSame('ankara implant', CompetitorTargets::representativeQuery($local, 'ankara'), 'area not repeated');

        $targets = app(CompetitorTargets::class)->forSite($this->site->fresh());
        $this->assertCount(3, $targets, 'unapproved cluster left out');
        $byName = collect($targets)->keyBy(fn (array $t): string => $t['cluster']->name);
        $this->assertSame('ankara implant merkezi', $byName['İmplant merkezi']['query']);
        $this->assertSame(1012782, $byName['İmplant merkezi']['location_code'], 'city of the main area from the location directory');
        $this->assertSame('tr', $byName['İmplant merkezi']['language_code']);
        $this->assertSame('mobile', $byName['İmplant merkezi']['device']);

        // A mapped English page switches the language of that cluster.
        BrandClusterPage::query()->create(['brand_id' => $this->brand->id, 'cluster_id' => $info->id, 'website_asset_id' => $this->site->id, 'state' => 'sufficient', 'language' => 'en']);
        $this->assertSame('en', collect(app(CompetitorTargets::class)->forSite($this->site->fresh()))->firstWhere('cluster.id', $info->id)['language_code']);
    }

    public function test_refresh_uses_the_cached_serp_classifies_domains_and_fetches_competitor_pages(): void
    {
        $this->enableAi();
        $cluster = $this->cluster('İmplant merkezi', 'implant merkezi', 'commercial');
        Http::fake(['api.dataforseo.com/v3/serp/google/organic/live/advanced' => Http::response($this->serpResponse())]);
        $classifyCalls = 0;
        CompetitorClassifyAgent::fake(function (string $prompt) use (&$classifyCalls): array {
            $classifyCalls++;
            $this->assertStringNotContainsString('panorama.example', $prompt, 'own domain never sent');
            $this->assertStringNotContainsString('doktortakvimi.com', $prompt, 'known directory never sent');

            return ['domains' => [
                ['domain' => 'rakip-a.example', 'class' => 'ticari', 'reason' => 'klinik'],
                ['domain' => 'rakip-b.example', 'class' => 'ticari', 'reason' => 'klinik'],
                ['domain' => 'saglikrehberi.example', 'class' => 'bilgi', 'reason' => 'portal'],
                ['domain' => 'uydurma.example', 'class' => 'ticari', 'reason' => 'girdide yok'],
            ], 'prompt_version' => CompetitorClassifyAgent::PROMPT_VERSION];
        });
        $this->fakePages([
            'https://rakip-a.example/implant/' => '<html><head><title>A İmplant</title></head><body><header>menü</header><main><h1>İmplant tedavisi</h1><h2>Fiyatı etkileyenler</h2><p>'.str_repeat('Uzman kadro ile implant. ', 20).'</p></main><footer>adres</footer></body></html>',
            'https://rakip-b.example/' => null,
            'https://saglikrehberi.example/implant' => '<html><body><article><h1>İmplant nedir</h1><p>Bilgi metni uzun uzun.</p></article></body></html>',
        ]);

        $result = app(CompetitorRefresher::class)->refresh($this->site->fresh());

        $this->assertSame(['status' => 'ready', 'clusters' => 1, 'serps' => 1, 'errors' => 0, 'pages' => 3], $result);
        Http::assertSent(fn (Request $r): bool => $r['0']['keyword'] === 'ankara implant merkezi' && $r['0']['location_code'] === 1012782 && $r['0']['device'] === 'mobile' && $r['0']['language_code'] === 'tr');
        $serp = BrandClusterSerp::query()->sole();
        $this->assertSame($cluster->id, $serp->cluster_id);
        $this->assertSame(3, $serp->own_rank);
        $classes = collect($serp->results)->pluck('class', 'domain')->all();
        $this->assertSame(['rakip-a.example' => 'ticari', 'rakip-b.example' => 'ticari', 'panorama.example' => 'kendi', 'doktortakvimi.com' => 'dizin',
            'hurriyet.com.tr' => 'haber', 'saglikrehberi.example' => 'bilgi'], $classes);
        $this->assertFalse(DB::table('competitor_domains')->where('domain', 'uydurma.example')->exists(), 'unknown domain from the model dropped');
        $this->assertSame(3, DB::table('competitor_domains')->count());

        $a = CompetitorPage::query()->where('domain', 'rakip-a.example')->sole();
        $this->assertSame(CompetitorPage::OK, $a->status);
        $this->assertStringContainsString('Uzman kadro', $a->content_text);
        $this->assertStringNotContainsString('menü', $a->content_text, 'header removed');
        $this->assertSame('İmplant tedavisi', $a->headings[0]['text']);
        $this->assertSame(CompetitorPage::MISSING, CompetitorPage::query()->where('domain', 'rakip-b.example')->value('status'), 'unreachable = eksik');

        // Second run: SERP from the 30-day cache, stored domain classes (no AI), fresh pages not fetched again.
        app(CompetitorRefresher::class)->refresh($this->site->fresh());
        Http::assertSentCount(1);
        $this->assertSame(1, $classifyCalls);
        $this->assertSame(3, count(app(PageFetcher::class)->fetched));
    }

    public function test_refresh_and_analysis_are_gated_to_operational_brands(): void
    {
        $this->cluster('İmplant merkezi', 'implant merkezi', 'commercial');
        $this->customer->forceFill(['status' => CustomerStatus::Inactive])->save();
        Http::fake();

        $this->assertSame('not_operational', app(CompetitorRefresher::class)->refresh($this->site->fresh())['status']);
        Http::assertNothingSent();
        Livewire::test(CompetitorsTab::class, ['assetId' => $this->site->id])
            ->assertSee('Rakipleri güncelle')
            ->call('refreshCompetitors')->assertSet('message', ServiceScope::NOT_SERVED);
        $this->assertNull(Cache::get(CompetitorRefresher::statusKey($this->site->id)));
    }

    public function test_analysis_keeps_only_suggestions_citing_two_competitors_or_a_clear_gap(): void
    {
        $this->enableAi();
        $cluster = $this->cluster('İmplant merkezi', 'implant merkezi', 'commercial');
        $serp = $this->serpWithPages($cluster->id);
        $sent = null;
        CompetitorAnalyzeAgent::fake(function (string $prompt) use (&$sent): array {
            $sent = $prompt;

            return [
                'need' => 'Güvenilir implant kliniği seçmek', 'dominant_page_type' => 'service', 'missing_info' => ['Tedavi süresi'], 'local_trust' => ['Hekim özgeçmişi'],
                'decision' => 'new_page', 'prompt_version' => CompetitorAnalyzeAgent::PROMPT_VERSION,
                'suggestions' => [
                    ['title' => 'Tedavi süresini anlatan bölüm ekle', 'reason' => 'İki rakip adım adım süreyi veriyor.', 'competitor_urls' => ['https://rakip-a.example/implant/', 'https://rakip-c.example/implant'], 'gap' => false],
                    ['title' => 'Tek rakibin başlığı', 'reason' => 'Bir rakipte var.', 'competitor_urls' => ['https://rakip-a.example/implant/'], 'gap' => false],
                    ['title' => 'Uydurma kaynaklı öneri', 'reason' => 'x', 'competitor_urls' => ['https://yok.example/1', 'https://yok.example/2'], 'gap' => false],
                    ['title' => 'İmplant merkezi sayfası aç', 'reason' => 'Bu ihtiyaç için sayfamız yok.', 'competitor_urls' => ['https://rakip-a.example/implant/'], 'gap' => true],
                ],
            ];
        });

        $result = app(CompetitorAnalyzer::class)->analyze($serp->fresh());

        $this->assertSame(['status' => 'ready', 'suggestions' => 2], $result);
        $this->assertStringContainsString('"our_page":null', $sent, 'no mapped page → "sayfa yok"');
        $this->assertStringNotContainsString('rakip-b.example', $sent, 'eksik page not sent');
        $titles = Suggestion::query()->orderBy('id')->pluck('title')->all();
        $this->assertSame(['Tedavi süresini anlatan bölüm ekle', 'İmplant merkezi sayfası aç'], $titles);
        $first = Suggestion::query()->where('title', 'Tedavi süresini anlatan bölüm ekle')->sole();
        $this->assertSame(['search', 'rakip', $cluster->id, Suggestion::OPEN], [$first->channel, $first->action_type, $first->cluster_id, $first->status]);
        $this->assertSame(['https://rakip-a.example/implant/', 'https://rakip-c.example/implant'], $first->evidence['competitor_urls']);
        $this->assertSame((int) app(PromptRegistry::class)->current('competitors.analyze')->id, (int) $first->prompt_version_id);
        $this->assertSame('competitors.analyze', DB::table('ai_usage_records')->where('prompt_version_id', $first->prompt_version_id)->value('route_key'));
        $definitions = app(PromptRegistry::class)->definitions();
        foreach (['competitors.classify', 'competitors.analyze', 'backlinks.sources'] as $operation) {
            $this->assertArrayHasKey($operation, $definitions);
            $this->assertTrue(app(AiRouteRegistry::class)->has($operation), $operation.' AI route registered');
        }
        $this->assertSame('new_page', $serp->fresh()->analysis['decision']);

        // With our page mapped a "gap" is no longer a valid reason; unacted stale suggestion is removed on re-analysis.
        $page = Page::query()->create(['website_asset_id' => $this->site->id, 'url' => 'https://panorama.example/implant/', 'url_hash' => hash('sha256', 'x'), 'path' => '/implant/', 'title' => 'İmplant', 'content_text' => 'Bizim metin']);
        BrandClusterPage::query()->create(['brand_id' => $this->brand->id, 'cluster_id' => $cluster->id, 'website_asset_id' => $this->site->id, 'page_id' => $page->id, 'state' => 'thin_coverage']);
        app(CompetitorAnalyzer::class)->analyze($serp->fresh());
        $this->assertStringContainsString('Bizim metin', $sent);
        $this->assertSame(['Tedavi süresini anlatan bölüm ekle'], Suggestion::query()->pluck('title')->all());
        $this->assertSame($page->id, Suggestion::query()->sole()->page_id);

        $this->assertSame(['title' => 'Abc', 'reason' => '', 'urls' => ['u1', 'u2'], 'gap' => false],
            CompetitorAnalyzer::validSuggestions([['title' => 'Abc', 'competitor_urls' => ['u1', 'u2', 'u1']]], ['u1', 'u2'], false)[0] ?? null, 'duplicate citations count once');
        $this->assertSame([], CompetitorAnalyzer::validSuggestions([['title' => 'Abc', 'competitor_urls' => ['u1', 'u1']]], ['u1', 'u2'], false));
    }

    public function test_analysis_needs_two_fetched_competitor_pages(): void
    {
        $this->enableAi();
        $cluster = $this->cluster('İmplant merkezi', 'implant merkezi', 'commercial');
        $serp = $this->serpWithPages($cluster->id);
        CompetitorPage::query()->where('domain', 'rakip-c.example')->update(['status' => CompetitorPage::MISSING]);
        CompetitorAnalyzeAgent::fake(fn (): array => $this->fail('no AI call'));

        $this->assertSame('few_competitors', app(CompetitorAnalyzer::class)->analyze($serp->fresh())['status']);
    }

    public function test_tab_renders_clusters_top_ten_and_suggestions_and_queues_analysis(): void
    {
        Queue::fake();
        $cluster = $this->cluster('İmplant merkezi', 'implant merkezi', 'commercial');
        $this->cluster('İmplant sonrası ağrı', 'implant sonrası ağrı', 'informational');
        $serp = $this->serpWithPages($cluster->id);
        Suggestion::query()->create(['brand_id' => $this->brand->id, 'channel' => 'search', 'cluster_id' => $cluster->id, 'decision_key' => 'rakip', 'fingerprint' => str_repeat('a', 64),
            'material_hash' => str_repeat('b', 64), 'title' => 'Hekim özgeçmişi ekle', 'reason' => 'İki rakip hekim bilgisi veriyor.', 'action_type' => 'rakip', 'status' => 'open',
            'evidence' => ['competitor_urls' => ['https://rakip-a.example/implant/', 'https://rakip-c.example/implant'], 'website_asset_id' => $this->site->id]]);

        Livewire::test(CompetitorsTab::class, ['assetId' => $this->site->id])
            ->assertSee('İmplant merkezi')->assertSee('ankara implant merkezi')
            ->assertSee('implant sonrası ağrı')->assertSee('Henüz SERP yok.')
            ->assertSee('rakip-a.example')->assertSee('ticari rakip')->assertSee('eksik')
            ->assertSee('Hekim özgeçmişi ekle')->assertSee('2 rakip')
            ->call('analyze', $serp->id)->assertSet('message', 'Analiz başladı.')
            ->call('refreshCompetitors')->assertSet('message', 'Rakipler güncelleniyor.');
        Queue::assertPushed(AnalyzeCompetitorClusterJob::class);
        Queue::assertPushed(RefreshCompetitorsJob::class);
    }

    private function serpWithPages(int $clusterId): BrandClusterSerp
    {
        $results = [
            ['rank' => 1, 'url' => 'https://rakip-a.example/implant/', 'domain' => 'rakip-a.example', 'title' => 'A', 'class' => 'ticari'],
            ['rank' => 2, 'url' => 'https://rakip-b.example/', 'domain' => 'rakip-b.example', 'title' => 'B', 'class' => 'ticari'],
            ['rank' => 3, 'url' => 'https://rakip-c.example/implant', 'domain' => 'rakip-c.example', 'title' => 'C', 'class' => 'bilgi'],
            ['rank' => 4, 'url' => 'https://doktortakvimi.com/x', 'domain' => 'doktortakvimi.com', 'title' => 'D', 'class' => 'dizin'],
        ];
        foreach ([['rakip-a.example', 'https://rakip-a.example/implant/', 'ok'], ['rakip-b.example', 'https://rakip-b.example/', 'eksik'], ['rakip-c.example', 'https://rakip-c.example/implant', 'ok']] as [$domain, $url, $status]) {
            CompetitorPage::query()->create(['url' => $url, 'url_hash' => hash('sha256', $url), 'domain' => $domain, 'class' => 'ticari', 'status' => $status,
                'title' => $domain, 'headings' => [['level' => 1, 'text' => 'İmplant']], 'content_text' => $status === 'ok' ? 'Rakip metni '.$domain : null, 'fetched_at' => now()]);
        }

        return BrandClusterSerp::query()->create(['brand_id' => $this->brand->id, 'cluster_id' => $clusterId, 'website_asset_id' => $this->site->id,
            'query' => 'ankara implant merkezi', 'location_code' => 1012782, 'language_code' => 'tr', 'device' => 'mobile', 'own_rank' => null,
            'results' => $results, 'status' => 'ready', 'fetched_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function serpResponse(): array
    {
        $organic = [
            ['rakip-a.example', 'https://rakip-a.example/implant/'], ['rakip-b.example', 'https://rakip-b.example/'], ['www.panorama.example', 'https://www.panorama.example/implant/'],
            ['doktortakvimi.com', 'https://www.doktortakvimi.com/implant/ankara'], ['hurriyet.com.tr', 'https://www.hurriyet.com.tr/saglik/implant'], ['saglikrehberi.example', 'https://saglikrehberi.example/implant'],
        ];
        $items = [['type' => 'local_pack', 'rank_group' => 1, 'rank_absolute' => 1, 'url' => 'https://maps.example/', 'domain' => 'maps.example', 'title' => 'Harita']];
        foreach ($organic as $i => [$domain, $url]) {
            $items[] = ['type' => 'organic', 'rank_group' => $i + 1, 'rank_absolute' => $i + 2, 'url' => $url, 'domain' => $domain, 'title' => 'Sonuç '.($i + 1)];
        }

        return ['status_code' => 20000, 'status_message' => 'Ok.', 'cost' => 0.002, 'tasks_count' => 1, 'tasks_error' => 0,
            'tasks' => [['id' => 'task-1', 'status_code' => 20000, 'status_message' => 'Ok.', 'cost' => 0.002, 'result' => [['items' => $items]]]]];
    }
}
