<?php

namespace Tests\Feature\Portfolio;

use App\Enums\CustomerStatus;
use App\Enums\DigitalAssetStatus;
use App\Livewire\Operator\Portfolio\BrandShow;
use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\OfferingPage;
use App\Models\Page;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\BrandIntelligence\BrandOfferingService;
use App\Services\Gbp\GbpSuggestions;
use App\Services\GoogleAds\GoogleAdsSpecialistBindingResolver;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Integrations\Google\GoogleScopes;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\InsertsFacts;
use Tests\TestCase;

/**
 * Brand page "Özet": period KPIs with change (and "veri yok" + the fix when a source is missing), one card per digital
 * asset with its data status and screen link, open suggestions, services with page mapping; channel tabs explain what
 * is missing instead of a bare "Hazırlanıyor"; the default tab and old tab links keep working.
 */
final class BrandOverviewTest extends TestCase
{
    use InsertsFacts;
    use RefreshDatabase;

    private User $admin;

    private Brand $brand;

    private DigitalAsset $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00:00', 'Europe/Istanbul'));
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        $customer = Customer::factory()->create(['name' => 'Panorama Sağlık A.Ş.', 'status' => CustomerStatus::Active]);
        $this->brand = Brand::factory()->create(['customer_id' => $customer->id, 'name' => 'Panorama Ankara']);
        $this->site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'status' => 'active', 'module_id' => 'website',
            'name' => 'panorama.example', 'domain' => 'panorama.example', 'primary_url' => 'https://panorama.example/']);
    }

    public function test_ozet_is_the_default_tab_and_old_tab_links_still_work(): void
    {
        $this->get(route('operator.brand', ['brand' => $this->brand->id]))->assertOk()
            ->assertSee('data-brand-overview', false)
            ->assertSee('data-period-selector', false)
            ->assertSee('Panorama Sağlık A.Ş.')
            ->assertSee('data-brand-state="active"', false)
            ->assertSee('data-open-website="'.$this->site->id.'"', false)
            ->assertDontSee('Bu hafta yapılacaklar');

        Livewire::test(BrandShow::class, ['brand' => (string) $this->brand->id])->assertSet('tab', 'ozet')
            ->call('setTab', 'bogus')->assertSet('tab', 'ozet')
            ->call('setTab', 'arama')->assertSet('tab', 'arama')->assertSee('data-workspace-pending="arama"', false)
            ->call('setTab', 'ayarlar')->assertSet('tab', 'settings')
            ->call('setTab', 'work')->assertSet('tab', 'overview')->assertSee('Dikkat gerektirenler')
            ->call('setTab', 'estate')->assertSet('tab', 'assets')
            ->call('setTab', 'discovery')->assertSet('tab', 'business')
            ->call('setTab', 'ozet')->assertSee('data-brand-kpis', false);

        foreach (['google_ads', 'meta', 'harita', 'files'] as $tab) {
            $this->get(route('operator.brand', ['brand' => $this->brand->id, 'tab' => $tab]))->assertOk();
        }
        $this->get(route('operator.brand', ['brand' => $this->brand->id, 'tab' => 'operations']))->assertOk()->assertSee('Dikkat gerektirenler');
        $this->get(route('operator.brand', ['brand' => $this->brand->id, 'tab' => 'bilinmeyen']))->assertOk()->assertSee('data-brand-overview', false);
    }

    public function test_kpis_without_bound_sources_say_veri_yok_with_the_fix(): void
    {
        $page = Livewire::test(BrandShow::class, ['brand' => (string) $this->brand->id])
            ->assertSee('veri yok')
            ->assertSee('Search Console bağlı değil')
            ->assertSee('Google Analytics bağlı değil')
            ->assertSee('Reklam hesabı bağlı değil')
            ->assertSee('İşletme Profili bağlı değil')
            ->assertSee(route('operator.asset.sources', ['assetId' => $this->site->id]), false);
        $kpis = collect($page->viewData('kpis'))->keyBy('key');

        $this->assertSame(['organic_clicks', 'sessions', 'web_conversions', 'ad_spend', 'ad_conversions', 'gbp_actions'], $kpis->keys()->all());
        $this->assertSame(['not_bound'], $kpis->pluck('state')->unique()->values()->all(), 'nothing bound: never 0, always veri yok');
        $this->assertNull($kpis['organic_clicks']['value']);
        $this->assertSame(route('operator.brand', ['brand' => $this->brand->id, 'tab' => 'assets']), $kpis['ad_spend']['action']['url']);

        // Search Console bound but nothing collected yet: "veri henüz gelmedi", not a zero.
        $gsc = CoreExternalResource::factory()->searchConsole()->create(['display_name' => 'sc-domain:panorama.example']);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $this->site->id, 'external_resource_id' => $gsc->id, 'capability' => 'search_console']);
        $kpis = collect(Livewire::test(BrandShow::class, ['brand' => (string) $this->brand->id])->assertSee('Bağlı · veri henüz gelmedi')->viewData('kpis'))->keyBy('key');
        $this->assertSame('no_data', $kpis['organic_clicks']['state']);
        $this->assertSame('not_bound', $kpis['sessions']['state']);
    }

    public function test_kpis_with_data_show_the_period_value_and_the_change(): void
    {
        $gsc = CoreExternalResource::factory()->searchConsole()->create();
        $ga4 = CoreExternalResource::factory()->create();
        CoreAssetBinding::factory()->create(['digital_asset_id' => $this->site->id, 'external_resource_id' => $gsc->id, 'capability' => 'search_console']);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $this->site->id, 'external_resource_id' => $ga4->id, 'capability' => 'ga4']);
        // Last Search Console day 2026-10-12: current 28 days 09-15 … 10-12, previous 08-18 … 09-14.
        foreach ([['2026-10-12', 30], ['2026-10-01', 20], ['2026-09-10', 25], ['2026-03-01', 99]] as [$date, $clicks]) {
            $this->insertFacts('gsc_query_page_daily', ['digital_asset_id' => null, 'external_resource_id' => $gsc->id, 'site_url' => 'sc-domain:panorama.example',
                'search_type' => 'web', 'reporting_date' => $date, 'query' => 'implant ankara', 'page' => 'https://panorama.example/implant/', 'clicks' => $clicks,
                'impressions' => $clicks * 10, 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => Str::random(20),
                'metadata' => json_encode(['provider_average_position' => 4.0])]);
        }
        foreach ([['2026-10-05', 200, 10], ['2026-09-01', 100, 5]] as [$date, $sessions, $keyEvents]) {
            $this->insertFacts('ga4_landing_source_daily', ['digital_asset_id' => null, 'external_resource_id' => $ga4->id, 'property_id' => 'properties/1',
                'reporting_date' => $date, 'landingPage' => '/implant/', 'sessionSource' => 'google', 'sessionMedium' => 'organic', 'sessions' => $sessions,
                'keyEvents' => $keyEvents, 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => Str::random(20)]);
        }
        $this->googleAds(40);
        $gbp = $this->gbp();
        for ($day = 0; $day < 56; $day++) {
            // Last profile day 10-10: 2 calls a day in the current 28 days, 1 a day before.
            $this->insertFacts('gbp_performance_daily', ['external_resource_id' => $gbp['resource']->id, 'digital_asset_id' => $gbp['asset']->id,
                'reporting_date' => CarbonImmutable::parse('2026-10-10')->subDays($day)->toDateString(), 'metric' => 'CALL_CLICKS', 'run_id' => 1,
                'location_name' => 'locations/22', 'value' => $day < 28 ? 2 : 1, 'collected_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }

        $page = Livewire::test(BrandShow::class, ['brand' => (string) $this->brand->id]);
        $kpis = collect($page->viewData('kpis'))->keyBy('key');

        $this->assertSame(['ok'], $kpis->pluck('state')->unique()->values()->all());
        $this->assertSame(['50', 100], [$kpis['organic_clicks']['value'], $kpis['organic_clicks']['delta']]);
        $this->assertSame(['200', 100], [$kpis['sessions']['value'], $kpis['sessions']['delta']]);
        $this->assertSame(['10', 100], [$kpis['web_conversions']['value'], $kpis['web_conversions']['delta']]);
        // Google Ads: 30 a day for 40 days before today → 28 days = 840, the 28 before = 12 days = 360.
        $this->assertSame(['₺840', 133], [$kpis['ad_spend']['value'], $kpis['ad_spend']['delta']]);
        $this->assertSame('önceki 28 gün: ₺360', $kpis['ad_spend']['note']);
        $this->assertSame(['28', 133], [$kpis['ad_conversions']['value'], $kpis['ad_conversions']['delta']]);
        $this->assertSame(['56', 100], [$kpis['gbp_actions']['value'], $kpis['gbp_actions']['delta']]);
        $page->assertSee('▲ +%100')->assertSee('₺840');

        // 90 days: the compact selector reuses the shared period preset.
        $kpis90 = collect($page->call('setPeriod', 'last_90')->assertSet('period', 'last_90')->assertSee('Son 90 gün')->viewData('kpis'))->keyBy('key');
        $this->assertSame('₺1.200', $kpis90['ad_spend']['value']);
        $this->assertNull($kpis90['ad_spend']['delta'], 'no previous 90 days of data: no made-up change');
        $this->assertSame(['75', null], [$kpis90['organic_clicks']['value'], $kpis90['organic_clicks']['delta']]);
    }

    public function test_asset_cards_show_type_status_and_link_to_their_screens(): void
    {
        $gsc = CoreExternalResource::factory()->searchConsole()->create(['display_name' => 'sc-domain:panorama.example']);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $this->site->id, 'external_resource_id' => $gsc->id, 'capability' => 'search_console']);
        $ads = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_ads', 'module_id' => 'google_ads', 'status' => DigitalAssetStatus::Active, 'name' => 'Panorama Ads']);
        $gbpAsset = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_business_profile', 'status' => DigitalAssetStatus::Active, 'name' => 'Panorama Çankaya']);
        $lost = CoreExternalResource::factory()->create(['resource_type' => 'google_business_profile', 'external_id' => 'locations/9', 'status' => CoreExternalResource::STATUS_UNAVAILABLE]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $gbpAsset->id, 'external_resource_id' => $lost->id, 'capability' => 'google_business_profile']);
        DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'domain', 'name' => 'panorama-domain']);

        $page = Livewire::test(BrandShow::class, ['brand' => (string) $this->brand->id])
            ->assertSee('data-asset-card="'.$this->site->id.'"', false)
            ->assertSee('data-asset-card="'.$ads->id.'"', false)
            ->assertDontSee('panorama-domain')
            ->assertSee(route('operator.website', ['assetId' => $this->site->id]), false)
            ->assertSee(route('operator.google-ads.overview', ['assetId' => $ads->id]), false)
            ->assertSee(route('operator.gbp', ['assetId' => $gbpAsset->id]), false)
            ->assertSee(route('operator.asset.sources', ['assetId' => $ads->id]), false)
            ->assertSee('İlk veri yükleniyor')
            ->assertSee('Erişim sorunu')
            ->assertSee('Kaynağı bağla')
            ->assertSee('Varlık ekle')
            ->assertSee('Hesap bağla');
        $cards = collect($page->viewData('assetCards'))->keyBy('id');

        $site = collect($cards[$this->site->id]['sources'])->keyBy('capability');
        $this->assertSame('Web sitesi', $cards[$this->site->id]['type_label']);
        $this->assertSame('first_load', $site['search_console']['state']);
        $this->assertSame('sc-domain:panorama.example', $site['search_console']['resource']);
        $this->assertSame('not_bound', $site['ga4']['state']);
        $this->assertSame(route('operator.asset.sources', ['assetId' => $this->site->id]), $site['ga4']['action_url']);
        $this->assertSame('warn', $cards[$this->site->id]['tone']);
        $this->assertSame('bad', $cards[$gbpAsset->id]['tone']);
        $this->assertSame('muted', $cards[$ads->id]['tone']);
        $this->assertSame('Google Ads: Bağlı değil', $cards[$ads->id]['summary']);
    }

    public function test_open_work_lists_actionable_suggestions_with_where_to_act(): void
    {
        Livewire::test(BrandShow::class, ['brand' => (string) $this->brand->id])->assertSee('Açık öneri yok.')->assertSee('data-work-empty', false);

        $gbp = $this->gbp()['asset'];
        app(GbpSuggestions::class)->replaceGroup($gbp, 'standard', [
            ['key' => 'standard:hours', 'title' => 'Çalışma saatlerini ekle', 'reason' => 'Profilde saat yok; aramaların %30\'u saat soruyor.', 'priority' => 1,
                'evidence' => [], 'action_type' => 'gbp_standard', 'action' => ['standard' => 'hours']],
            ['key' => 'standard:photos', 'title' => 'Fotoğraf ekle', 'reason' => 'Son 90 günde fotoğraf yok.', 'priority' => 2,
                'evidence' => [], 'action_type' => 'gbp_standard', 'action' => ['standard' => 'photos']],
            ['key' => 'standard:posts', 'title' => 'Gönderi paylaş', 'reason' => 'Son gönderi 60 gün önce.', 'priority' => 3,
                'evidence' => [], 'action_type' => 'gbp_standard', 'action' => ['standard' => 'posts']],
        ], sweep: false);
        Suggestion::query()->where('title', 'Fotoğraf ekle')->update(['status' => Suggestion::DISMISSED]);
        Suggestion::query()->where('title', 'Gönderi paylaş')->update(['status' => Suggestion::SNOOZED, 'snoozed_until' => now()->subDay()]);

        $page = Livewire::test(BrandShow::class, ['brand' => (string) $this->brand->id])
            ->assertSee('Çalışma saatlerini ekle')
            ->assertSee('Gönderi paylaş')
            ->assertDontSee('Fotoğraf ekle')
            ->assertSee(route('operator.gbp', ['assetId' => $gbp->id]), false);
        $work = $page->viewData('work');
        $this->assertSame(2, $work['total']);
        $this->assertSame(['Harita' => 2], $work['by_channel']);
        $this->assertSame('Çalışma saatlerini ekle', $work['items'][0]['title'], 'most urgent first');

        // The Harita tab lists the same open work instead of a bare "Hazırlanıyor".
        Livewire::withQueryParams(['tab' => 'harita'])->test(BrandShow::class, ['brand' => (string) $this->brand->id])
            ->assertSee('Çalışma saatlerini ekle')->assertSee('data-asset-card="'.$gbp->id.'"', false)->assertDontSee('Hazırlanıyor');
    }

    public function test_services_show_count_priority_and_page_mapping(): void
    {
        Livewire::test(BrandShow::class, ['brand' => (string) $this->brand->id])->assertSee('Markaya hizmet eklenmedi.');

        $service = app(BrandOfferingService::class);
        $implant = $service->resolveOrCreate($this->brand, 'İmplant Tedavisi', actor: $this->admin)['offering'];
        $service->resolveOrCreate($this->brand, 'Diş Beyazlatma', actor: $this->admin);
        BrandOffering::query()->whereKey($implant->id)->update(['is_priority' => true]);
        $page = Page::query()->create(['website_asset_id' => $this->site->id, 'url' => 'https://panorama.example/implant/', 'url_hash' => hash('sha256', 'implant'), 'path' => '/implant/']);
        OfferingPage::query()->create(['brand_offering_id' => $implant->id, 'page_id' => $page->id, 'source' => 'rule']);

        $view = Livewire::test(BrandShow::class, ['brand' => (string) $this->brand->id])
            ->assertSee('İmplant Tedavisi')->assertSee('1 sayfa')->assertSee('Sayfa eşlenmedi')
            ->assertSee('1 öncelikli · 1/2 hizmetin sitede sayfası eşlendi');
        $summary = $view->viewData('serviceSummary');
        $this->assertSame([2, 1, 1], [$summary['total'], $summary['priority'], $summary['mapped']]);
        $this->assertSame('İmplant Tedavisi', $summary['rows'][0]['name'], 'priority services first');
        $view->call('setTab', 'business')->assertSet('tab', 'business');
    }

    public function test_channel_tabs_say_what_is_missing_and_how_to_fix_it(): void
    {
        Livewire::withQueryParams(['tab' => 'harita'])->test(BrandShow::class, ['brand' => (string) $this->brand->id])
            ->assertSee('Markaya bağlı İşletme Profili yok.')
            ->assertSee('data-channel-missing', false)
            ->assertSee(route('operator.brand', ['brand' => $this->brand->id, 'tab' => 'assets']), false)
            ->assertSee('Bu kanalda açık öneri yok.')
            ->assertDontSee('Hazırlanıyor');
        Livewire::withQueryParams(['tab' => 'meta'])->test(BrandShow::class, ['brand' => (string) $this->brand->id])
            ->assertSee('Markaya bağlı Meta reklam hesabı yok.');

        Livewire::withQueryParams(['tab' => 'arama'])->test(BrandShow::class, ['brand' => (string) $this->brand->id])
            ->assertSee('data-asset-card="'.$this->site->id.'"', false)
            ->assertSee('Search Console')
            ->assertSee('Bağlı değil')
            ->assertSee('Kaynağı bağla')
            ->assertSee(route('operator.website', ['assetId' => $this->site->id]), false)
            ->assertDontSee('data-channel-missing', false);

        $this->googleAds(10);
        Livewire::withQueryParams(['tab' => 'google_ads'])->test(BrandShow::class, ['brand' => (string) $this->brand->id])
            ->assertSee('Panorama Ads')
            ->assertSee('Google Ads')
            ->assertDontSee('data-channel-missing', false);
    }

    public function test_passive_brand_is_marked_and_says_it_is_not_served(): void
    {
        $this->brand->customer->update(['status' => CustomerStatus::Inactive]);

        $this->get(route('operator.brand', ['brand' => $this->brand->id]))->assertOk()
            ->assertSee('data-brand-state="passive"', false)
            ->assertSee('Pasif')
            ->assertSee('data-brand-not-served', false);
    }

    /** A bound Google Ads account with $days days (before today) of 2 campaigns: 30 cost and 1 conversion a day. */
    private function googleAds(int $days): DigitalAsset
    {
        config(['moxdop.google.client_id' => 'cid', 'moxdop.google.client_secret' => 'csecret', 'moxdop.google.developer_token' => 'devtoken']);
        $asset = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_ads', 'module_id' => 'google_ads', 'status' => DigitalAssetStatus::Active, 'name' => 'Panorama Ads']);
        $integration = CoreIntegration::query()->where('provider', 'google')->first() ?? CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $integration->update(['status' => CoreIntegration::STATUS_ACTIVE, 'config' => ['granted_scopes' => [GoogleScopes::ADWORDS]]]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $integration->id, 'encrypted_payload' => ['client_id' => 'cid', 'client_secret' => 'csecret', 'developer_token' => 'devtoken']]);
        CoreIntegrationCredential::factory()->authorization()->create(['integration_id' => $integration->id, 'encrypted_payload' => ['access_token' => 'a', 'refresh_token' => 'r', 'scope' => GoogleScopes::ADWORDS], 'expires_at' => now()->addHour()]);
        $resource = CoreExternalResource::factory()->create(['integration_id' => $integration->id, 'provider' => 'google', 'resource_type' => GoogleResourceType::GOOGLE_ADS_CUSTOMER,
            'external_id' => '1112223333', 'status' => CoreExternalResource::STATUS_AVAILABLE, 'metadata' => ['timezone' => 'Europe/Istanbul', 'currency' => 'TRY']]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id, 'capability' => GoogleAdsSpecialistBindingResolver::CAPABILITY, 'status' => CoreAssetBinding::STATUS_ACTIVE]);
        for ($day = 1; $day <= $days; $day++) {
            foreach ([['101', 20, 1], ['102', 10, 0]] as [$campaign, $cost, $conversions]) {
                $this->insertFacts('google_ads_campaign_daily', ['campaign_id' => $campaign, 'reporting_date' => now('Europe/Istanbul')->subDays($day)->toDateString(),
                    'impressions' => 100, 'clicks' => 5, 'cost_micros' => 0, 'cost_amount' => $cost, 'conversions' => $conversions, 'currency' => 'TRY', 'metadata' => '{}',
                    'digital_asset_id' => null, 'external_resource_id' => $resource->id, 'customer_id' => '1112223333', 'source_timezone' => 'Europe/Istanbul',
                    'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => bin2hex(random_bytes(32)),
                    'created_at' => now(), 'updated_at' => now()]);
            }
        }

        return $asset;
    }

    /** @return array{asset: DigitalAsset, resource: CoreExternalResource} a bound İşletme Profili */
    private function gbp(): array
    {
        $asset = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_business_profile', 'status' => DigitalAssetStatus::Active, 'name' => 'Panorama Çankaya']);
        $resource = CoreExternalResource::factory()->create(['provider' => 'google', 'resource_type' => 'google_business_profile', 'external_id' => 'locations/22',
            'status' => CoreExternalResource::STATUS_AVAILABLE]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id, 'capability' => 'google_business_profile', 'status' => CoreAssetBinding::STATUS_ACTIVE]);

        return ['asset' => $asset, 'resource' => $resource];
    }
}
