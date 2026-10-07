<?php

namespace Tests\Feature\Portfolio;

use App\Livewire\Demo\Portfolio\BrandCreate;
use App\Livewire\Demo\Portfolio\CustomerDetail;
use App\Livewire\Operator\Portfolio\BrandSetupPage;
use App\Livewire\Operator\Portfolio\BrandShow;
use App\Models\Brand;
use App\Models\BrandConversionSource;
use App\Models\BrandIntelligenceContext;
use App\Models\BrandOffering;
use App\Models\BrandServiceArea;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\DigitalAsset;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\Brand\BrandDossier;
use App\Services\BrandIntelligence\BrandOfferingService;
use App\Services\Catalog\ServiceCatalogService;
use App\Services\Catalog\ServiceKeywordService;
use App\Services\Operator\BrandWorkspaceReadService;
use App\Services\Operator\OperatorPortfolioPresenter;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Brand and customer pages: real connection state from confirmed account bindings, a setup
 * checklist, and every tab rendering from database data only.
 */
final class BrandWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Brand $brand;

    private DigitalAsset $website;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);

        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['name' => 'Klinik A.Ş.'])->id, 'name' => 'Adadent']);
        $this->website = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'name' => 'adadent.com.tr', 'primary_url' => 'https://adadent.com.tr/', 'module_id' => 'website']);
        $gsc = CoreExternalResource::factory()->create(['resource_type' => 'search_console', 'display_name' => 'sc-domain:adadent.com.tr']);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $this->website->id, 'external_resource_id' => $gsc->id, 'capability' => 'search_console']);
        DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_ads', 'name' => 'Adadent Ads']);
    }

    public function test_connection_state_comes_from_confirmed_bindings(): void
    {
        $presented = OperatorPortfolioPresenter::brand($this->brand->fresh());
        $this->assertSame(1, $presented['connected_assets']);
        $this->assertSame('connected', OperatorPortfolioPresenter::asset($this->website)['connection']);

        $assets = app(BrandWorkspaceReadService::class)->assets($this->brand);
        $site = collect($assets)->firstWhere('type', 'website');
        $this->assertSame(['Search Console'], array_column($site['accounts'], 'label'));
        $this->assertNull($site['accounts'][0]['last_sync'], 'never collected is shown as missing, not as a date');
        $this->assertFalse(collect($assets)->firstWhere('type', 'google_ads')['connected']);
    }

    public function test_setup_checklist_names_what_is_missing(): void
    {
        $workspace = app(BrandWorkspaceReadService::class);
        $checklist = $workspace->checklist($this->brand, $workspace->assets($this->brand), $workspace->services($this->brand));
        $items = collect($checklist['items'])->keyBy('key');

        $this->assertFalse($checklist['complete']);
        $this->assertTrue($items['website']['done']);
        $this->assertFalse($items['search_console']['done'], 'bound but no data yet is not set up');
        $this->assertStringContainsString('veri henüz gelmedi', $items['search_console']['detail']);
        $this->assertSame('varliklar', $items['search_console']['fix']);
        $this->assertFalse($items['ga4']['done']);
        $this->assertFalse($items['meta_ads']['required'], 'channels the brand may not have are optional');
        $this->assertFalse($items['services']['done']);
        $this->assertFalse($items['areas']['done']);

        ServiceCategory::query()->create(['code' => 'saglik', 'name' => 'Sağlık', 'normalized_key' => 'saglik']);
        $item = app(ServiceCatalogService::class)->resolveOrCreate('İmplant Tedavisi', 'saglik', actor: $this->admin)['service'];
        app(BrandOfferingService::class)->resolveOrCreate($this->brand, 'İmplant Tedavisi', actor: $this->admin);
        $services = $workspace->services($this->brand);
        $this->assertSame(0, $services[0]['matching_count']);
        $this->assertFalse(collect($workspace->checklist($this->brand, [], $services)['items'])->firstWhere('key', 'matching')['done']);

        app(ServiceKeywordService::class)->append($item, ['vidalı diş']);
        $this->assertTrue(collect($workspace->checklist($this->brand, [], $workspace->services($this->brand))['items'])->firstWhere('key', 'matching')['done']);

        // The first data came (the automation's "data through"): Search Console is set up now.
        $gscId = (int) CoreAssetBinding::query()->where('capability', 'search_console')->value('external_resource_id');
        DB::table('resource_automations')->insert(['external_resource_id' => $gscId, 'data_through' => '2026-10-01', 'created_at' => now(), 'updated_at' => now()]);
        $items = collect($workspace->checklist($this->brand, $workspace->assets($this->brand), $workspace->services($this->brand))['items'])->keyBy('key');
        $this->assertTrue($items['search_console']['done']);
        $this->assertSame('sc-domain:adadent.com.tr', $items['search_console']['detail']);
    }

    /**
     * Marka eksikleri (yakup, 2026-10-07): ★ main service, sector, counted conversions and İş bağlamı join the list;
     * what was filled automatically and never checked shows as "Kontrol et".
     */
    public function test_brand_card_lists_main_service_sector_conversions_and_context_and_asks_to_check_automatic_ones(): void
    {
        $workspace = app(BrandWorkspaceReadService::class);
        $items = fn (): Collection => collect($workspace->checklist($this->brand->fresh(), [], $workspace->services($this->brand))['items'])->keyBy('key');
        $this->brand->forceFill(['sector' => null, 'sector_id' => null])->save();

        $this->assertSame([false, false, false, false], [$items()['main_service']['done'], $items()['sector']['done'], $items()['conversions']['done'], $items()['context']['done']]);
        Livewire::test(BrandShow::class, ['brand' => (string) $this->brand->id])
            ->assertSeeHtml('data-setup-item="conversions"')->assertSeeHtml('data-setup-state="missing"')->assertSee('Marka eksikleri');

        ServiceCategory::query()->create(['code' => 'saglik', 'name' => 'Sağlık', 'normalized_key' => 'saglik']);
        $this->brand->forceFill(['sector' => 'saglik'])->save();
        app(ServiceCatalogService::class)->resolveOrCreate('İmplant Tedavisi', 'saglik', actor: $this->admin);
        app(BrandOfferingService::class)->resolveOrCreate($this->brand, 'İmplant Tedavisi', actor: $this->admin);
        BrandOffering::query()->where('brand_id', $this->brand->id)->update(['priority' => 'main', 'is_priority' => true]);
        BrandConversionSource::query()->create(['brand_id' => $this->brand->id, 'source' => 'ga4_key_event', 'source_key' => 'form', 'label' => 'Form gönderimi',
            'conversion_type' => 'lead', 'counts' => true, 'origin' => 'auto']);
        BrandIntelligenceContext::query()->create(['brand_id' => $this->brand->id, 'business_summary' => 'Kadıköy diş kliniği.', 'differentiators' => ['Aynı gün implant'], 'source' => 'public_discovery']);

        $now = $items();
        $this->assertSame([true, true, true, true], [$now['main_service']['done'], $now['sector']['done'], $now['conversions']['done'], $now['context']['done']]);
        $this->assertTrue($now['conversions']['review']);
        $this->assertTrue($now['context']['review']);
        $this->assertStringContainsString('İmplant', $now['main_service']['detail']);
        Livewire::test(BrandShow::class, ['brand' => (string) $this->brand->id])
            ->assertSeeHtml('data-setup-state="review"')->assertSee('Kontrol et')->assertDontSeeHtml('data-setup-item="sector"');

        BrandIntelligenceContext::query()->where('brand_id', $this->brand->id)->update(['source' => 'operator']);
        $this->assertFalse($items()['context']['review']);
    }

    public function test_service_areas_need_a_city_for_a_local_business(): void
    {
        $workspace = app(BrandWorkspaceReadService::class);
        $areas = fn (): array => collect($workspace->checklist($this->brand, $workspace->assets($this->brand), [])['items'])->firstWhere('key', 'areas');
        $country = BrandServiceArea::query()->create(['brand_id' => $this->brand->id, 'country_code' => 'TR', 'country_name' => 'Türkiye', 'normalized_key' => 'tr', 'status' => 'active', 'priority_rank' => 1]);

        $this->assertTrue($areas()['done'], 'no physical place known: a country is enough (online / national brand)');

        $country->update(['physical_branch' => true]);
        $this->assertFalse($areas()['done'], 'a branch needs a city');
        $this->assertStringContainsString('Yalnız ülke düzeyinde (Türkiye)', $areas()['detail']);

        $country->update(['physical_branch' => false]);
        DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_business_profile', 'name' => 'Adadent Kadıköy']);
        $this->assertFalse($areas()['done'], 'an İşletme Profili means a local business: "Türkiye" alone does not complete it');
        $this->assertSame('ayarlar', $areas()['fix']);

        BrandServiceArea::query()->create(['brand_id' => $this->brand->id, 'country_code' => 'TR', 'country_name' => 'Türkiye', 'city_name' => 'İstanbul', 'district_name' => 'Kadıköy',
            'normalized_key' => 'tr|istanbul|kadikoy', 'status' => 'active', 'priority_rank' => 2, 'physical_branch' => true]);
        $this->assertTrue($areas()['done']);
        $this->assertSame('2 bölge · 1 şube', $areas()['detail']);
    }

    public function test_every_brand_tab_renders_and_old_links_still_work(): void
    {
        BrandServiceArea::query()->create(['brand_id' => $this->brand->id, 'country_code' => 'TR', 'country_name' => 'Türkiye', 'city_name' => 'İstanbul', 'normalized_key' => 'tr|istanbul', 'status' => 'active', 'priority_rank' => 1]);

        // Old "Kurulum" sub-tab: now Dijital varlıklar (setup status, missing accounts, bindings).
        $page = Livewire::withQueryParams(['tab' => 'overview'])->test(BrandShow::class, ['brand' => (string) $this->brand->id])
            ->assertSet('tab', 'varliklar')
            ->assertSee('Adadent')
            ->assertSee('Klinik A.Ş.')
            ->assertSee('Kurulum durumu')
            ->assertSee('Google Analytics 4')
            ->assertSee('data-accounts-missing', false)
            ->assertSee('data-asset-row="'.$this->website->id.'"', false)
            ->assertDontSee('Dikkat gerektirenler')
            ->assertDontSee('Not configured')
            ->assertDontSee('Never collected');

        $page->call('setTab', 'business')->assertSet('tab', 'ayarlar')->assertSee('Hizmetler')->assertSee('İstanbul')->assertSee('İş bağlamı')->assertSee('Kapsam');
        $page->call('setTab', 'assets')->assertSet('tab', 'varliklar')->assertSee('Search Console')->assertSee('sc-domain:adadent.com.tr')->assertSee('Hesap ekle');
        $page->call('setTab', 'dosya')->assertSee('data-brand-dossier', false);
        $page->call('setTab', 'work')->assertSet('tab', 'ozet');
        $page->call('setTab', 'estate')->assertSet('tab', 'varliklar');
        $page->call('setTab', 'value')->assertSet('tab', 'ozet');

        $this->get(route('operator.brand', ['brand' => $this->brand->id, 'tab' => 'operations']))->assertOk()->assertSee('data-brand-overview', false);
    }

    /** Hooks the Playwright specs (tests/e2e/02, 09, 10) rely on. */
    public function test_browser_test_hooks_are_present(): void
    {
        $this->get(route('operator.brand', ['brand' => $this->brand->id, 'tab' => 'assets']))
            ->assertOk()
            ->assertSee('role="tablist" aria-label="Marka"', false)
            ->assertSee('data-asset-row="'.$this->website->id.'"', false);
        $this->get(route('operator.customer', ['customerId' => $this->brand->customer_id]))
            ->assertOk()
            ->assertSee('aria-label="Diğer"', false)
            ->assertSee('data-customer-brand="'.$this->brand->id.'"', false);
        $this->get('/setup')->assertNotFound();
    }

    public function test_priority_star_and_business_context_edit(): void
    {
        $offering = app(BrandOfferingService::class)->resolveOrCreate($this->brand, 'Diş Beyazlatma', actor: $this->admin)['offering'];

        $page = Livewire::test(BrandShow::class, ['brand' => (string) $this->brand->id])
            ->call('toggleOfferingPriority', $offering->id)
            ->call('startEditingContext')->assertSet('tab', 'ayarlar')
            ->set('context_business_summary', 'Kadıköy diş kliniği.')
            ->set('context_business_goals', 'Ayda 40 implant randevusu')
            ->set('context_constraints', 'Fiyat yazılmaz')
            ->call('saveBusinessContext')
            ->assertSee('Kadıköy diş kliniği.')->assertSee('Ayda 40 implant randevusu');

        $fresh = BrandOffering::query()->find($offering->id);
        $this->assertSame(['main', true], [$fresh->priority, (bool) $fresh->is_priority], '★ = priority main, mirrored');

        // Goals and constraints: one source, the brand file's notes (and the file is rebuilt with them and the context).
        $this->assertSame(['goals' => 'Ayda 40 implant randevusu', 'constraints' => 'Fiyat yazılmaz'], BrandDossier::notes($this->brand));
        $stored = BrandDossier::stored($this->brand);
        $this->assertStringContainsString('Kadıköy diş kliniği.', $stored['sections']['context']['markdown']);
        $this->assertStringContainsString('Fiyat yazılmaz', $stored['sections']['notes']['markdown']);

        // The next edit starts from that one source.
        $page->call('startEditingContext')->assertSet('context_business_goals', 'Ayda 40 implant randevusu')->assertSet('context_constraints', 'Fiyat yazılmaz');

        $page->call('toggleOfferingPriority', $offering->id);
        $fresh = BrandOffering::query()->find($offering->id);
        $this->assertSame(['secondary', false, null], [$fresh->priority, (bool) $fresh->is_priority, $fresh->priority_rank]);
    }

    public function test_priority_fields_stay_in_sync_whichever_is_written(): void
    {
        $offering = app(BrandOfferingService::class)->resolveOrCreate($this->brand, 'Zirkonyum', actor: $this->admin)['offering'];

        $offering->forceFill(['is_priority' => true])->save();
        $this->assertSame('main', $offering->fresh()->priority, 'older writers (★ is_priority) set main');

        $offering->forceFill(['priority' => 'secondary'])->save();
        $this->assertFalse((bool) $offering->fresh()->is_priority);

        app(BrandOfferingService::class)->setPriorityOrder($this->brand, [$offering->id], $this->admin);
        $this->assertSame(['main', true, 1], [$offering->fresh()->priority, (bool) $offering->fresh()->is_priority, $offering->fresh()->priority_rank]);
        app(BrandOfferingService::class)->setPriorityOrder($this->brand, [], $this->admin);
        $this->assertSame(['secondary', false], [$offering->fresh()->priority, (bool) $offering->fresh()->is_priority]);

        // The backfill migration: a star in either field becomes main in both, nothing is lowered.
        DB::table('brand_offerings')->where('id', $offering->id)->update(['is_priority' => true, 'priority' => 'secondary']);
        $other = app(BrandOfferingService::class)->resolveOrCreate($this->brand, 'Beyazlatma', actor: $this->admin)['offering'];
        DB::table('brand_offerings')->where('id', $other->id)->update(['is_priority' => false, 'priority' => 'main']);
        (require database_path('migrations/2026_11_27_090000_brand_offerings_priority_sync.php'))->up();
        $this->assertSame(['main', true], [$offering->fresh()->priority, (bool) $offering->fresh()->is_priority]);
        $this->assertSame(['main', true], [$other->fresh()->priority, (bool) $other->fresh()->is_priority]);
    }

    public function test_customer_page_lists_brands_with_setup_state_and_keeps_contact_role(): void
    {
        $customer = $this->brand->customer;
        CustomerContact::query()->create(['customer_id' => $customer->id, 'name' => 'Ayşe Yılmaz', 'title' => 'Other title']);

        $page = Livewire::test(CustomerDetail::class, ['customerId' => (string) $customer->id])
            ->assertSee('Adadent')
            ->assertSee('Kurulum')
            ->assertSee('Search Console')
            ->assertSee('Ayşe Yılmaz');
        $contactId = (string) CustomerContact::query()->value('id');
        $page->call('openContactForm', $contactId)->assertSet('contact_role', 'other')->assertSet('contact_title_custom', 'Other title');

        $page->call('setTab', 'relationship')->assertSet('tab', 'overview');
        $page->call('setTab', 'requests')->assertSet('tab', 'overview');
    }

    public function test_new_brand_with_website_opens_otomatik_kur_and_starts_the_proposal(): void
    {
        $customer = Customer::factory()->create();

        Livewire::test(BrandCreate::class, ['customerId' => (string) $customer->id])
            ->set('name', 'Yeni Marka')
            ->set('website_url', 'yenimarka.com')
            ->call('save')
            ->assertRedirect(route('operator.brand.setup', ['brand' => Brand::query()->where('name', 'Yeni Marka')->value('id'), 'url' => 'yenimarka.com']));

        $brandId = Brand::query()->where('name', 'Yeni Marka')->value('id');
        // The site's pages are not collected yet: Otomatik kur warns and waits for "Yine de getir".
        $this->get(route('operator.brand.setup', ['brand' => $brandId, 'url' => 'yenimarka.com']))->assertOk()->assertSee('Yine de getir');
        $this->assertSame(0, DB::table('brand_setup_proposals')->where('brand_id', $brandId)->count());
        Livewire::test(BrandSetupPage::class, ['brand' => (string) $brandId])->set('websiteUrl', 'yenimarka.com')->call('start', true);
        $this->assertSame(1, DB::table('brand_setup_proposals')->where('brand_id', $brandId)->count());
        $this->get(route('operator.brand.setup', ['brand' => $brandId, 'url' => 'yenimarka.com']))->assertOk();
        $this->assertSame(1, DB::table('brand_setup_proposals')->where('brand_id', $brandId)->count(), 'reopening does not start a second proposal');
    }
}
