<?php

namespace Tests\Feature\Site;

use App\Livewire\Demo\GlobalSearch;
use App\Livewire\Operator\Website\V2\AnalyticsTab;
use App\Livewire\Operator\Website\V2\CompetitorsTab;
use App\Livewire\Operator\Website\V2\ContentIdeasTab;
use App\Livewire\Operator\Website\V2\LinkedAssetsTab;
use App\Livewire\Operator\Website\V2\OverviewTab;
use App\Livewire\Operator\Website\V2\PagesTab;
use App\Livewire\Operator\Website\V2\SettingsTab;
use App\Livewire\Operator\Website\V2\TechnicalSeoTab;
use App\Livewire\Operator\Website\V2\WebsiteScreen;
use App\Models\BrandOffering;
use App\Models\Cluster;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\Gsc\UrlInspectionTargets;
use App\Services\Site\Analysis\SiteAnalysisReader;
use App\Services\Site\Analysis\SitePagesReader;
use App\Services\Site\Analysis\SiteRange;
use App\Services\Site\Analysis\TechnicalSeoReader;
use App\Services\Site\PageCategorizer;
use App\Services\Site\SiteOperations;
use App\Services\Site\SiteScope;
use App\Services\Website\SitemapChangeWatcher;
use App\Support\Demo\DemoMenu;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Integrations\Google\GoogleScopes;
use App\Support\Integrations\ProviderRegistry;
use App\Support\OperatorMenu;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\InsertsFacts;
use Tests\TestCase;

/** Web sitesi ekranı: Sayfalar (inventory × Search Console × GA4 × Google Ads × health), page detail, tabs, access. */
final class SitePagesTest extends TestCase
{
    use InsertsFacts;
    use RefreshDatabase;
    use SiteFixtures;

    private CoreExternalResource $gsc;

    private CoreExternalResource $ga4;

    private Page $implantPage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSite();
        $this->gsc = CoreExternalResource::factory()->searchConsole()->create();
        $this->ga4 = CoreExternalResource::factory()->create();
        CoreAssetBinding::factory()->create(['digital_asset_id' => $this->site->id, 'external_resource_id' => $this->gsc->id, 'capability' => 'search_console']);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $this->site->id, 'external_resource_id' => $this->ga4->id, 'capability' => 'ga4']);

        // Inventory: a service page mapped to the brand service, a blog post, a page that now 404s, a noindex page, a quiet page.
        $this->implantPage = $this->page('/implant/', 'Diş implantı', 'hizmet', 11);
        $this->page('/blog/agri/', 'İmplant sonrası ağrı', 'blog', 12);
        $this->page('/eski-kampanya/', 'Eski kampanya', 'hizmet');
        $this->page('/tesekkurler/', 'Teşekkürler', 'kurumsal');
        $this->page('/hakkimizda/', 'Hakkımızda', 'kurumsal');
        $offering = BrandOffering::query()->where('brand_id', $this->brand->id)->firstOrFail();
        DB::table('offering_pages')->insert(['brand_offering_id' => $offering->id, 'page_id' => $this->implantPage->id, 'source' => 'manual', 'locked' => true, 'created_at' => now(), 'updated_at' => now()]);

        // Search Console: current 28 days end 2026-09-26 (30 Aug – 26 Sep), previous 2 – 29 Aug.
        foreach ([
            ['ankara implant', '/implant/', '2026-09-20', 30, 300, 4.0],
            ['implant fiyatları', '/implant', '2026-09-24', 10, 200, 6.0],
            ['ankara implant', '/implant/', '2026-08-20', 20, 250, 5.0],
            ['implant ağrısı', '/blog/agri/', '2026-09-10', 2, 40, 9.0],
            ['implant ağrısı', '/blog/agri/', '2026-08-10', 10, 60, 7.0],
            ['kampanya', '/eski-kampanya/', '2026-09-26', 1, 10, 12.0],
            ['diş beyazlatma', '/beyazlatma/', '2026-09-15', 4, 50, 8.0],
        ] as [$query, $path, $date, $clicks, $impressions, $position]) {
            $this->insertFacts('gsc_query_page_daily', [
                'digital_asset_id' => null, 'external_resource_id' => $this->gsc->id, 'site_url' => 'sc-domain:panorama.example', 'search_type' => 'web',
                'reporting_date' => $date, 'query' => $query, 'page' => 'https://www.panorama.example'.$path, 'clicks' => $clicks, 'impressions' => $impressions,
                'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => Str::random(20),
                'metadata' => json_encode(['provider_average_position' => $position]),
            ]);
        }
        foreach ([
            ['/implant/', 'google', 'organic', '2026-09-20', 40, 3],
            ['/implant/?gclid=x', 'google', 'cpc', '2026-09-22', 10, 2],
            ['/tesekkurler/', '(direct)', '(none)', '2026-09-21', 6, 0],
        ] as [$landing, $source, $medium, $date, $sessions, $keyEvents]) {
            $this->insertFacts('ga4_landing_source_daily', [
                'digital_asset_id' => null, 'external_resource_id' => $this->ga4->id, 'property_id' => 'properties/1', 'reporting_date' => $date,
                'landingPage' => $landing, 'sessionSource' => $source, 'sessionMedium' => $medium, 'sessions' => $sessions, 'keyEvents' => $keyEvents,
                'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => Str::random(20),
            ]);
        }

        // Google Ads: the brand's account sends paid clicks to /implant/ (other hosts are ignored).
        $ads = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_ads', 'status' => 'active', 'name' => 'Panorama Ads']);
        $adsResource = CoreExternalResource::factory()->create(['resource_type' => 'google_ads', 'external_id' => '1234567890']);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $ads->id, 'external_resource_id' => $adsResource->id, 'capability' => 'google_ads']);
        foreach ([['https://www.panorama.example/implant/?utm_source=ads', 25, 312.5], ['https://baska.example/implant/', 99, 999.0]] as $i => [$landing, $clicks, $cost]) {
            $this->insertFacts('google_ads_landing_page_daily', [
                'digital_asset_id' => null, 'external_resource_id' => $adsResource->id, 'customer_id' => '1234567890', 'reporting_date' => '2026-09-18',
                'landing_page' => $landing, 'impressions' => 500, 'clicks' => $clicks, 'cost_micros' => (int) ($cost * 1000000), 'conversions' => 1,
                'cost_amount' => $cost, 'currency' => 'TRY', 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
                'record_fingerprint' => hash('sha256', 'ads'.$i),
            ]);
        }

        // Health: latest fetch of each URL (older fetch of /implant/ had a 500 and an issue — superseded).
        $this->http('https://www.panorama.example/implant/', '2026-09-01 10:00:00', 500, [['server_error', 'critical']]);
        $this->http('https://www.panorama.example/implant/', '2026-09-25 10:00:00', 200, [['meta_description_missing', 'medium']]);
        $this->http('https://www.panorama.example/eski-kampanya/', '2026-09-25 10:00:00', 404, [['http_404', 'high']]);
        $this->http('https://www.panorama.example/tesekkurler/', '2026-09-25 10:00:00', 200, []);
        $this->meta('https://www.panorama.example/tesekkurler/', '2026-09-25 10:00:00', 'noindex, follow');
        $this->http('https://www.panorama.example/blog/agri/', '2026-09-25 10:00:00', 200, []);
        $this->edge('https://www.panorama.example/blog/agri/', 'https://www.panorama.example/implant/', '2026-09-25 10:00:00');
        $this->edge('https://www.panorama.example/implant/', 'https://www.panorama.example/blog/agri/', '2026-09-25 10:00:00');
        $this->edge('https://www.panorama.example/implant/', 'https://www.panorama.example/hakkimizda/', '2026-09-25 10:00:00');
    }

    public function test_rows_merge_inventory_search_console_ga4_ads_and_health(): void
    {
        $rows = app(SitePagesReader::class)->rows($this->site->fresh(), 28);

        $implant = $rows['/implant'];
        $this->assertSame($this->implantPage->id, $implant['page_id']);
        $this->assertSame(['Diş İmplantı'], $implant['services'], 'service from offering_pages');
        $this->assertSame([40, 500, 4.8, 20, 100], [$implant['clicks'], $implant['impressions'], $implant['position'], $implant['prev_clicks'], $implant['delta']], '/implant and /implant/ are one row');
        $this->assertSame([50, 5.0], [$implant['sessions'], $implant['key_events']], 'GA4 landing with query string counted');
        $this->assertSame([25, 312.5], [$implant['ads_clicks'], $implant['ads_cost']], 'Google Ads landing of this host only');
        $this->assertSame([200, 1, 0, false, true], [$implant['status_code'], $implant['issues'], $implant['serious'], $implant['problem'], $implant['is_main']], 'latest fetch only');

        $this->assertTrue($rows['/blog/agri']['declining'], '10 → 2 clicks');
        $this->assertFalse($rows['/blog/agri']['is_main']);
        $this->assertSame([404, 1, true, true], [$rows['/eski-kampanya']['status_code'], $rows['/eski-kampanya']['serious'], $rows['/eski-kampanya']['problem'], $rows['/eski-kampanya']['is_main']], 'category hizmet counts as a main page');
        $this->assertSame([false, true], [$rows['/tesekkurler']['indexable'], $rows['/tesekkurler']['problem']], 'noindex from the metadata snapshot');
        $this->assertSame([false, 6], [$rows['/tesekkurler']['no_traffic'], $rows['/tesekkurler']['sessions']]);
        $this->assertTrue($rows['/hakkimizda']['no_traffic']);
        $this->assertSame([[], 4], [$rows['/beyazlatma']['sources'], $rows['/beyazlatma']['clicks']], 'Search Console-only URL is listed, outside the inventory');
        $this->assertNull($rows['/blog/agri']['ads_clicks']);
    }

    public function test_filters_search_sort_pagination_and_service_state(): void
    {
        $reader = app(SitePagesReader::class);
        $site = $this->site->fresh();

        $this->assertSame(['ana' => 2, 'tum' => 6, 'trafiksiz' => 1, 'sorunlu' => 2, 'dususte' => 1], $reader->counts($site, 28));
        $paths = fn ($paginator): array => array_column($paginator->items(), 'path');
        $this->assertSame(['/implant', '/eski-kampanya'], $paths($reader->list($site, 28, 'ana', '', 'clicks', true, 1)));
        $this->assertSame(['/eski-kampanya', '/tesekkurler'], $paths($reader->list($site, 28, 'sorunlu', '', 'path', false, 1)));
        $this->assertSame(['/blog/agri'], $paths($reader->list($site, 28, 'tum', 'ağrı', 'clicks', true, 1)), 'search matches the title');
        $this->assertSame(['/implant'], $paths($reader->list($site, 28, 'tum', 'dİş İmplanti', 'clicks', true, 1)), 'search matches the service, Turkish İ folded');
        $this->assertSame(['/implant', '/beyazlatma', '/blog/agri', '/eski-kampanya', '/hakkimizda', '/tesekkurler'], $paths($reader->list($site, 28, 'tum', '', 'clicks', true, 1)));
        $this->assertSame('/implant', $paths($reader->list($site, 28, 'tum', '', 'position', false, 1))[0], 'best position first, empty last');
        $second = $reader->list($site, 28, 'tum', '', 'path', false, 2, 4);
        $this->assertSame([6, 2, ['/implant', '/tesekkurler']], [$second->total(), $second->lastPage(), $paths($second)]);

        $state = $reader->serviceState($site, 28);
        $this->assertSame([1, 0, 1], [$state['iyi'], $state['dususte'], $state['sorunlu']]);
        $this->assertSame('/eski-kampanya', $state['rows'][0]['row']['path'], 'problems first');
    }

    public function test_page_detail_queries_trend_channels_issues_links_and_suggestions(): void
    {
        $this->suggestion('Meta açıklaması ekle');

        $detail = app(SitePagesReader::class)->detail($this->site->fresh(), '/implant', 28);

        $this->assertSame([['ankara implant', 30, 20], ['implant fiyatları', 10, 0]], array_map(fn (array $q): array => [$q['query'], $q['clicks'], $q['prev_clicks']], $detail['queries']));
        $this->assertCount(90, $detail['trend']);
        $this->assertSame(['2026-06-29', '2026-09-26'], [$detail['trend'][0]['date'], $detail['trend'][89]['date']]);
        $this->assertSame([30, 40], [collect($detail['trend'])->firstWhere('date', '2026-09-20')['clicks'], collect($detail['trend'])->firstWhere('date', '2026-09-20')['sessions']]);
        $this->assertSame(20, collect($detail['trend'])->firstWhere('date', '2026-08-20')['clicks'], 'trend covers 90 days');
        $this->assertSame([['google', 'organic', 40, 3.0], ['google', 'cpc', 10, 2.0]], array_map(fn (array $c): array => [$c['source'], $c['medium'], $c['sessions'], $c['key_events']], $detail['channels']));
        $this->assertSame(['meta_description_missing'], array_column($detail['issues'], 'code'), 'issues of the latest fetch');
        $this->assertSame(['out' => 2, 'in' => 1], $detail['links']);
        $this->assertSame(['Meta açıklaması ekle'], $detail['suggestions']->pluck('title')->all());
        $this->assertNull(app(SitePagesReader::class)->detail($this->site->fresh(), '/yok', 28));
    }

    public function test_pages_tab_filters_and_opens_the_detail_drawer(): void
    {
        Livewire::test(PagesTab::class, ['assetId' => $this->site->id])
            ->call('setType', 'hizmet')->call('setFilter', 'ana')
            ->assertSee('Ana hizmet sayfaları')->assertSee('/implant')->assertDontSee('/blog/agri')->assertSee('312,50')
            ->call('setType', 'icerik')->call('setFilter', 'dususte')->assertSee('/blog/agri')->assertDontSee('data-page-row="/implant"', false)
            ->call('setType', '')->set('search', '/')->call('setFilter', 'tum')->call('sortBy', 'path')->assertViewHas('rows', fn ($rows): bool => array_slice(array_column($rows->items(), 'path'), 0, 3) === ['/beyazlatma', '/blog/agri', '/eski-kampanya'])
            ->call('open', '/implant')->assertSee('data-page-detail="/implant"', false)->assertSee('ankara implant')->assertSee('google / cpc')
            ->assertSee('1 gelen, 2 giden')->assertSee('Son 90 gün')
            ->call('close')->assertDontSee('data-page-detail', false);
    }

    public function test_overview_shows_traffic_trend_service_pages_and_open_work(): void
    {
        foreach (range(0, 27) as $i) {
            $day = now()->setDate(2026, 9, 26)->subDays($i)->toDateString();
            $this->insertFacts('gsc_property_daily', ['digital_asset_id' => null, 'external_resource_id' => $this->gsc->id, 'site_url' => 'sc-domain:panorama.example',
                'reporting_date' => $day, 'clicks' => 100, 'impressions' => 1000, 'search_type' => 'web', 'metadata' => '{}', 'contract_version' => 1,
                'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => hash('sha256', 'p'.$day)]);
        }
        $this->suggestion('Başlığı güçlendir');

        $trend = app(SitePagesReader::class)->trend($this->site->fresh(), 28);
        $this->assertSame([2800, 28000, 56, 5.0, 0], [$trend['current']['clicks'], $trend['current']['impressions'], $trend['current']['sessions'], $trend['current']['key_events'], $trend['previous']['clicks']], 'property totals first');
        $this->assertCount(28, $trend['series']);

        Livewire::test(OverviewTab::class, ['assetId' => $this->site->id])
            ->assertSee('Organik tıklama')->assertSee('2.800')->assertSee('Oturum')->assertSee('data-sparkline="Organik tıklama"', false)
            ->assertSee('Ana hizmet sayfaları')->assertSee('İyi 1')->assertSee('Sorunlu 1')->assertSee('Diş İmplantı')
            ->assertSee('Başlığı güçlendir')
            ->assertSee('Tıklama oranı')->assertSee('10,0%')->assertSee('Ortalama sıra')
            ->assertSee('En çok tıklanan sorgular')->assertSeeHtml('data-top-queries')->assertSee('ankara implant')
            ->assertSee('En çok tıklanan sayfalar')->assertSee('Diş implantı')
            ->assertSee('Kaybedenler')->assertSee('10 → 2')
            ->set('period', 90)->assertSee('29.06.2026 – 26.09.2026');
    }

    public function test_tabs_and_legacy_tab_ids_route_to_the_new_views(): void
    {
        $this->assertSame('yapilacaklar', WebsiteScreen::resolve('seo', 'oneriler'));
        $this->assertSame('kumeler', WebsiteScreen::resolve('seo', 'kumeler'));
        $this->assertSame('kumeler', WebsiteScreen::resolve('sorgular', 'fikirler'));
        $this->assertSame('hedef', WebsiteScreen::resolve('sorgular', 'hedef'));
        $this->assertSame('analiz', WebsiteScreen::resolve('analiz', ''));
        $this->assertSame('donusumler', WebsiteScreen::resolve('conversions', ''));
        $this->assertSame('analiz', WebsiteScreen::resolve('ozet', 'ozet'));
        $this->assertSame('analiz', WebsiteScreen::resolve('genel', ''));
        $this->assertSame('teknik', WebsiteScreen::resolve('saglik', ''));
        $this->assertSame('ayarlar', WebsiteScreen::resolve('varliklar', ''));
        $this->assertSame('sayfalar', WebsiteScreen::resolve('pages', ''));
        $this->assertSame('rakipler', WebsiteScreen::resolve('seo', 'rakipler'));
        $this->assertSame('kumeler', WebsiteScreen::resolve('bilinmeyen', 'x'));

        $url = fn (array $query): string => route('operator.website', ['assetId' => $this->site->id, ...$query]);
        $this->get($url([]))->assertOk()->assertSee('data-clusters-board', false)->assertSee('data-tab="kumeler"', false)->assertSee('data-tab-more', false)
            ->assertSee('data-date-picker', false)->assertSee('Son 28 gün');
        $this->get($url(['tab' => 'sayfalar']))->assertOk()->assertSee('data-pages-tab', false)->assertSee('data-page-types', false)->assertSee('/implant')
            ->assertDontSee('aria-label="Dönem"', false);
        $this->get($url(['tab' => 'sayfalar', 'sayfa' => '/implant']))->assertOk()->assertSee('data-page-detail="/implant"', false);
        $this->get($url(['tab' => 'analiz']))->assertOk()->assertSee('data-analytics-tab', false)->assertSee('data-scorecards', false)->assertSee('data-page-scorecard', false);
        $this->get($url(['tab' => 'sorgular']))->assertOk()->assertSee('data-rows="queries"', false)->assertSee('ankara implant')->assertDontSee('aria-label="Analiz sekmeleri"', false);
        $this->get($url(['tab' => 'fikirler']))->assertOk()->assertSee('data-content-ideas-tab', false)->assertDontSee('data-date-picker', false);
        $this->get($url(['tab' => 'hedef']))->assertOk()->assertSee('data-rows="targets"', false);
        $this->get($url(['tab' => 'saglik']))->assertOk()->assertSee('data-technical-seo-tab', false)->assertSee('Site Sağlığı');
        $this->get($url(['tab' => 'varliklar']))->assertOk()->assertSee('data-settings', false)->assertSee('data-linked-assets-tab', false)
            ->assertSee('Şimdi güncelle')->assertSee(route('operator.integrations.website', ['assetId' => $this->site->id]), false);
        $this->get($url(['tab' => 'seo', 'sub' => 'rakipler']))->assertOk()->assertSee('data-tab="rakipler"', false);

        Livewire::test(WebsiteScreen::class, ['assetId' => (string) $this->site->id])
            ->call('setSub', 'oneriler')->assertSet('tab', 'yapilacaklar')
            ->call('setTab', 'sorgular')->assertSet('tab', 'sorgular')
            ->call('setTab', 'ozet')->assertSet('tab', 'analiz')
            ->call('setRange', 90, '', '', 'year')->assertSet('days', 90)->assertSet('compare', 'year')->assertSee('Son 3 ay')
            ->call('setRange', 28, '2026-09-01', '2026-09-10', 'prev')->assertSet('start', '2026-09-01')->assertSet('days', 10)->assertSee('Özel aralık')
            ->call('setRange', 28, '2026-09-10', '2026-09-01', 'prev')->assertSet('start', '')->assertSet('days', 28);
        Livewire::test(SettingsTab::class, ['assetId' => $this->site->id])->assertSee('henüz toplanmadı');
        Livewire::test(LinkedAssetsTab::class, ['assetId' => $this->site->id])->assertOk();
    }

    public function test_websites_menu_list_search_brand_button_and_integration_link(): void
    {
        $this->assertContains('operator.websites', collect(DemoMenu::groups())->flatMap(fn (array $group): array => $group['items'])->pluck('route')->all());
        $item = collect(OperatorMenu::groups())->flatMap(fn (array $group): array => $group['items'])->firstWhere('path', route('operator.websites', absolute: false));
        $this->assertContains('operator.website', $item['routes'], 'menu entry stays active on the site screen');
        DigitalAsset::factory()->create(['type' => 'website', 'brand_id' => null, 'name' => 'Sahipsiz Site', 'domain' => 'sahipsiz.example']);

        $this->get(route('operator.websites'))->assertOk()->assertSee('Web siteleri')->assertSee('Panorama Site')->assertSee('Panorama Ankara')
            ->assertSee(route('operator.website', ['assetId' => $this->site->id]), false)->assertDontSee('Sahipsiz Site')->assertSee('1 site markaya atanmamış');
        $this->get(route('operator.websites', ['ara' => 'yok-boyle']))->assertOk()->assertSee('Markaya atanmış web sitesi yok.');

        Livewire::test(GlobalSearch::class)->set('q', 'panorama.example')
            ->assertSee('Panorama Site')->assertSee('Web sitesi · panorama.example')->assertSee(route('operator.website', ['assetId' => $this->site->id]), false);

        $this->get(route('operator.brand', ['brand' => $this->brand->id]))->assertOk()
            ->assertSee('data-open-website="'.$this->site->id.'"', false)->assertSee('Siteyi aç');
        $this->get(route('operator.brand', ['brand' => $this->brand->id, 'tab' => 'overview']))->assertOk()->assertSee('Siteyi aç');

        $this->get(route('operator.integrations.website'))->assertOk()
            ->assertSee('data-open-site-screen="'.$this->site->id.'"', false);
    }

    private function suggestion(string $title): Suggestion
    {
        return Suggestion::query()->create([
            'brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => 'site.test', 'fingerprint' => hash('sha256', $title),
            'material_hash' => hash('sha256', 'x'), 'title' => $title, 'reason' => 'Gerekçe.', 'priority' => 1, 'action_type' => 'title_description',
            'status' => Suggestion::OPEN, 'action' => ['site_id' => $this->site->id], 'evidence' => [], 'page_id' => $this->implantPage->id,
        ]);
    }

    public function test_service_section_pages_are_main_page_totals_match_the_site_and_empty_views_say_the_next_step(): void
    {
        // A sitemap page under the service section, not categorized yet, with the city in its slug and no title.
        $url = 'https://www.panorama.example/tedavilerimiz/implant-tedavisi/ankara-all-on-6-implant';
        $allOn6 = Page::query()->create(['website_asset_id' => $this->site->id, 'url' => $url, 'url_hash' => hash('sha256', $url),
            'path' => '/tedavilerimiz/implant-tedavisi/ankara-all-on-6-implant', 'title' => null, 'category' => null]);
        $this->assertSame('hizmet', PageCategorizer::rule($allOn6, [], ['ankara']), 'service section wins over the city word');

        // Page totals (anonymized queries included) replace the query × page sum; the position still comes from the facts.
        foreach ([['/implant/', '2026-09-20', 70, 900], ['/implant/', '2026-08-20', 30, 400]] as $i => [$path, $date, $clicks, $impressions]) {
            $this->insertFacts('gsc_page_daily', [
                'digital_asset_id' => null, 'external_resource_id' => $this->gsc->id, 'site_url' => 'sc-domain:panorama.example', 'search_type' => 'web',
                'reporting_date' => $date, 'page' => 'https://www.panorama.example'.$path, 'clicks' => $clicks, 'impressions' => $impressions,
                'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => hash('sha256', 'p'.$i),
            ]);
        }
        $rows = app(SitePagesReader::class)->rows($this->site->fresh(), 28);
        $this->assertSame([70, 900, 4.8, 30], [$rows['/implant']['clicks'], $rows['/implant']['impressions'], $rows['/implant']['position'], $rows['/implant']['prev_clicks']]);
        $row = $rows['/tedavilerimiz/implant-tedavisi/ankara-all-on-6-implant'];
        $this->assertSame(['hizmet', true, 'Ankara all on 6 implant'], [$row['category'], $row['is_main'], $row['title']]);

        // No approved cluster for the brand's services: every cluster view says so, with the link to approve.
        Cluster::query()->create(['sector_id' => $this->brand->sector_id, 'service_id' => BrandOffering::query()->where('brand_id', $this->brand->id)->value('service_catalog_item_id'),
            'name' => 'İmplant fiyatları', 'intent' => 'commercial', 'page_type' => 'service', 'approved' => false]);
        $this->assertSame('approve', SiteScope::clusterReadiness($this->brand, $this->site)['step']);
        Livewire::test(ContentIdeasTab::class, ['assetId' => $this->site->id])->assertSeeHtml('data-cluster-readiness="approve"')->assertSee('Kümeleri onayla');
        Livewire::test(CompetitorsTab::class, ['assetId' => $this->site->id])->assertSeeHtml('data-cluster-readiness="approve"');
    }

    public function test_after_otomatik_kur_the_site_is_prepared_without_waiting_for_the_weekly_refresh(): void
    {
        $url = 'https://www.panorama.example/tedavilerimiz/ankara-zirkonyum';
        $page = Page::query()->create(['website_asset_id' => $this->site->id, 'url' => $url, 'url_hash' => hash('sha256', $url), 'path' => '/tedavilerimiz/ankara-zirkonyum', 'title' => 'Ankara Zirkonyum']);

        $result = app(SiteOperations::class)->run($this->site->fresh(), SiteOperations::SETUP);

        $this->assertSame('ready', $result['status']);
        $this->assertArrayHasKey('cluster_pages', $result);
        $this->assertIsInt($result['targets']);
        $this->assertSame('hizmet', $page->fresh()->category, 'rule category written right away');
    }

    private function page(string $path, string $title, string $category, ?int $wpId = null): Page
    {
        $url = 'https://www.panorama.example'.$path;

        return Page::query()->create(['website_asset_id' => $this->site->id, 'url' => $url, 'url_hash' => hash('sha256', $url), 'path' => $path,
            'title' => $title, 'category' => $category, 'wp_post_id' => $wpId, 'wp_post_type' => $wpId !== null ? 'page' : null]);
    }

    /** @param  list<array{0: string, 1: string}>  $issues */
    private function http(string $url, string $observedAt, int $status, array $issues): void
    {
        DB::table('website_http_snapshot')->insert($this->snapshot($url, $observedAt, ['status_code' => $status, 'ok' => $status < 400]));
        foreach ($issues as [$code, $severity]) {
            DB::table('website_crawl_issue_snapshot')->insert($this->snapshot($url, $observedAt, ['evidence' => []]) + ['issue_code' => $code, 'severity' => $severity, 'message' => $code.' mesajı']);
        }
    }

    private function meta(string $url, string $observedAt, string $robots): void
    {
        DB::table('website_metadata_snapshot')->insert($this->snapshot($url, $observedAt, ['meta_robots' => $robots]));
    }

    private function edge(string $from, string $to, string $observedAt): void
    {
        DB::table('website_link_edge')->insert(array_diff_key($this->snapshot($from, $observedAt, []), ['url' => true]) + [
            'edge_key' => hash('sha256', $from.$to), 'source_url' => $from, 'target_url' => $to, 'normalized_target_url' => $to, 'is_internal' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function snapshot(string $url, string $observedAt, array $metadata): array
    {
        return ['digital_asset_id' => $this->site->id, 'url' => $url, 'observed_at' => $observedAt, 'contract_version' => 1, 'first_collected_at' => now(),
            'last_collected_at' => now(), 'record_fingerprint' => Str::random(40), 'metadata' => json_encode($metadata), 'created_at' => now(), 'updated_at' => now()];
    }

    public function test_site_range_presets_custom_range_and_comparison_windows(): void
    {
        $last = CarbonImmutable::parse('2026-09-26');
        $this->assertSame(['start' => '2026-08-30', 'end' => '2026-09-26', 'prev_start' => '2026-08-02', 'prev_end' => '2026-08-29'], SiteRange::from(28)->window($last));
        $this->assertSame(['start' => '2025-08-30', 'end' => '2025-09-26'], array_slice(array_combine(['start', 'end'], array_slice(array_values(SiteRange::from(28, null, null, 'year')->window($last)), 2)), 0, 2));
        $custom = SiteRange::from(7, '2026-09-01', '2026-09-10');
        $this->assertSame([10, true, '2026-08-22', '2026-08-31'], [$custom->days, $custom->custom(), $custom->window($last)['prev_start'], $custom->window($last)['prev_end']]);
        $this->assertSame(28, SiteRange::from(13)->days, 'unknown preset falls back to 28 days');
        $this->assertFalse(SiteRange::from(28, '2026-09-10', '2026-09-01')->custom(), 'start after end is ignored');
        $this->assertSame('1 Eyl – 28 Eyl 2026', SiteRange::format('2026-09-01', '2026-09-28'));

        // The bound range drives the readers: the last 7 days hold only the 20 + 24 Sep rows.
        SiteRange::from(7)->bind();
        $totals = app(SiteAnalysisReader::class)->totals($this->site->fresh(), 28);
        $this->assertSame([41, 510], [$totals['current']['clicks'], $totals['current']['impressions']]);
        app()->forgetInstance(SiteRange::class);
    }

    public function test_analytics_tab_scorecards_charts_funnel_findings_and_page_scorecard(): void
    {
        Livewire::test(AnalyticsTab::class, ['assetId' => $this->site->id, 'range' => ['days' => 28]])
            ->assertSeeHtml('data-scorecard="clicks"')->assertSeeHtml('data-scorecard="key_events"')->assertSee('Tıklama')->assertSee('Dönüşüm')
            ->assertSeeHtml('data-chart="gsc"')->assertSeeHtml('data-chart="ga4"')
            ->assertSee('Aramadan dönüşüme')->assertSee('Organik oturum')
            ->assertSeeHtml('data-insight="service_no_click"')->assertSeeHtml('data-insight="top10_no_click"')->assertSeeHtml('data-insight="home_share"')->assertSeeHtml('data-insight="conversion_rate"')
            ->assertSee('Sayfa karnesi')->assertSeeHtml('data-karne-row="/implant"')
            ->call('pick', 'impressions')->assertSet('gscMetric', 'impressions')
            ->call('pick', 'key_events')->assertSet('ga4Metric', 'key_events')
            ->call('pick', 'yok')->assertSet('gscMetric', 'impressions')
            ->call('csv')->assertFileDownloaded('sayfa-karnesi-'.$this->site->id.'.csv');

        // Organic funnel reads only google / organic landing rows (40 sessions, 3 key events), not cpc or direct.
        $organic = app(SiteAnalysisReader::class)->organic($this->site->fresh(), 28);
        $this->assertSame(['sessions' => 40, 'key_events' => 3.0], $organic['current']);
    }

    public function test_pages_tab_groups_pages_by_type(): void
    {
        $types = app(SitePagesReader::class)->types($this->site->fresh(), 28);
        $this->assertSame(['hizmet', 'kategori', 'icerik', 'kurumsal', 'lokasyon', 'anahtar', 'diger'], array_keys($types));
        $this->assertContains('/implant', array_column($types['hizmet']['rows'], 'path'));
        $this->assertContains('/blog/agri', array_column($types['icerik']['rows'], 'path'));

        Livewire::test(PagesTab::class, ['assetId' => $this->site->id, 'range' => ['days' => 28]])
            ->assertSeeHtml('data-type-section="hizmet"')->assertSeeHtml('data-type-section="icerik"')->assertDontSeeHtml('data-page-rows')
            ->assertDontSeeHtml('aria-label="Dönem"')
            ->call('setType', 'icerik')->assertSet('type', 'icerik')->assertSeeHtml('data-page-rows')->assertSee('/blog/agri')->assertDontSeeHtml('data-page-row="/implant"')
            ->call('setType', 'yok')->assertSet('type', '')->assertSeeHtml('data-type-section="hizmet"');
    }

    public function test_technical_seo_tab_lists_google_and_html_findings_with_drawer(): void
    {
        foreach ([['https://www.panorama.example/implant/', 'Submitted and indexed'], ['https://www.panorama.example/blog/agri/', 'Crawled - currently not indexed'],
            ['https://www.panorama.example/eski-kampanya/', 'Not found (404)']] as $i => [$url, $state]) {
            $this->insertFacts('gsc_url_inspection_snapshot', ['digital_asset_id' => $this->site->id, 'external_resource_id' => null, 'site_url' => 'sc-domain:panorama.example',
                'page' => $url, 'inspected_at' => '2026-09-25 10:00:00', 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
                'record_fingerprint' => hash('sha256', 'insp'.$i), 'metadata' => json_encode(['coverage_state' => $state, 'verdict' => $i === 0 ? 'PASS' : 'NEUTRAL'])]);
        }
        Page::query()->where('path', '/hakkimizda/')->update(['title' => 'Teşekkürler']);

        $data = app(TechnicalSeoReader::class)->read($this->site->fresh());
        $this->assertSame(['inspected' => 3, 'indexed' => 1, 'not_indexed' => 2], $data['index']);
        $this->assertSame(['g_404', 'g_crawled_not_indexed'], array_column($data['google'], 'key'), 'critical first');
        $this->assertSame(['/eski-kampanya'], $data['google'][0]['pages']);
        $this->assertContains('h_duplicate_title', array_column($data['html'], 'key'));
        $this->assertSame([], array_values(array_filter($data['html'], fn (array $f): bool => in_array('/implant', $f['pages'], true) && $f['key'] === 'h_server_error')), 'superseded crawl issues are not listed');

        Livewire::test(TechnicalSeoTab::class, ['assetId' => $this->site->id])
            ->assertSeeHtml('data-tile="critical"')->assertSeeHtml('data-index-bar')->assertSee('Google’ın bildirdikleri')->assertSee('Sitenin HTML’inde bulduklarımız')
            ->assertSee('Google 404 buluyor')->assertSee('WordPress’ten düzeltilir')
            ->call('show', 'g_404')->assertSeeHtml('data-finding-drawer="g_404"')->assertSee('Nasıl düzeltilir?')->assertSee('/eski-kampanya')
            ->call('close')->assertDontSeeHtml('data-finding-drawer')
            ->set('source', 'html')->assertDontSee('Google’ın bildirdikleri')
            ->set('source', '')->set('severity', 'info')->assertDontSee('Google 404 buluyor');
    }

    public function test_daily_url_inspection_batch_and_search_console_sitemaps(): void
    {
        config(['moxdop.google.client_id' => 'test-client-id', 'moxdop.google.client_secret' => 'test-client-secret']);
        $integration = CoreIntegration::query()->where('provider', ProviderRegistry::GOOGLE)->first()
            ?? CoreIntegration::factory()->google()->create();
        $integration->forceFill(['status' => CoreIntegration::STATUS_ACTIVE, 'config' => ['granted_scopes' => [GoogleScopes::SEARCH_CONSOLE_READONLY]]])->save();
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $integration->id]);
        CoreIntegrationCredential::factory()->authorization()->create(['integration_id' => $integration->id, 'expires_at' => now()->addHour(),
            'encrypted_payload' => ['access_token' => 't', 'refresh_token' => 'r', 'scope' => GoogleScopes::SEARCH_CONSOLE_READONLY]]);
        $this->gsc->forceFill(['integration_id' => $integration->id, 'provider' => ProviderRegistry::GOOGLE,
            'resource_type' => GoogleResourceType::GSC_PROPERTY, 'external_id' => 'sc-domain:panorama.example',
            'status' => CoreExternalResource::STATUS_AVAILABLE])->save();
        $targets = app(UrlInspectionTargets::class)->for($this->site->fresh(), 3);
        $this->assertNotNull($targets['site_url']);
        $this->assertCount(3, $targets['targets']);
        $this->assertStringContainsString('/implant', $targets['targets'][0], 'service pages first');

        // A URL inspected in the last 14 days waits.
        $this->insertFacts('gsc_url_inspection_snapshot', ['digital_asset_id' => $this->site->id, 'external_resource_id' => $this->gsc->id, 'site_url' => $targets['site_url'],
            'page' => $targets['targets'][0], 'inspected_at' => now(), 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
            'record_fingerprint' => hash('sha256', 'insp-recent'), 'metadata' => json_encode(['coverage_state' => 'Submitted and indexed'])]);
        $this->assertNotContains($targets['targets'][0], app(UrlInspectionTargets::class)->for($this->site->fresh(), 3)['targets']);

        $run = app(UrlInspectionTargets::class)->start($this->site->fresh());
        $this->assertNotNull($run);
        $this->assertSame(['url_inspection_targets'], array_values(array_intersect(array_keys((array) data_get($run->request_context, 'context')), ['url_inspection_targets'])));

        // Ayarlar: Search Console sitemaps are shown and used when no sitemap URL is set.
        $this->insertFacts('gsc_sitemap_snapshot', ['digital_asset_id' => $this->site->id, 'external_resource_id' => $this->gsc->id, 'site_url' => $targets['site_url'],
            'sitemap_path' => 'https://www.panorama.example/sitemap_index.xml', 'retrieved_at' => now(), 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
            'record_fingerprint' => hash('sha256', 'sm'), 'metadata' => json_encode(['errors' => 0, 'warnings' => 2])]);
        $this->assertSame(['https://www.panorama.example/sitemap_index.xml'], SitemapChangeWatcher::searchConsoleSitemaps($this->site->fresh()));
        Livewire::test(SettingsTab::class, ['assetId' => $this->site->id])->assertSeeHtml('data-gsc-sitemaps')->assertSee('sitemap_index.xml')->assertSee('2 uyarı');
    }
}
