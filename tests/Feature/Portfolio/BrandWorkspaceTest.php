<?php

namespace Tests\Feature\Portfolio;

use App\Livewire\Demo\Portfolio\BrandCreate;
use App\Livewire\Demo\Portfolio\CustomerDetail;
use App\Livewire\Operator\Portfolio\BrandShow;
use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\BrandServiceArea;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\DigitalAsset;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\BrandIntelligence\BrandOfferingService;
use App\Services\Operator\BrandWorkspaceReadService;
use App\Services\Operator\OperatorPortfolioPresenter;
use App\Services\SearchDemand\ServiceCatalogService;
use App\Services\SearchDemand\ServiceKeywordService;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertTrue($items['search_console']['done']);
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
    }

    public function test_every_brand_tab_renders_and_old_links_still_work(): void
    {
        BrandServiceArea::query()->create(['brand_id' => $this->brand->id, 'country_code' => 'TR', 'country_name' => 'Türkiye', 'city_name' => 'İstanbul', 'normalized_key' => 'tr|istanbul', 'status' => 'active', 'priority_rank' => 1]);

        $page = Livewire::test(BrandShow::class, ['brand' => (string) $this->brand->id])
            ->assertSee('Adadent')
            ->assertSee('Klinik A.Ş.')
            ->assertSee('Kurulum')
            ->assertSee('Google Analytics 4')
            ->assertSee('Dikkat gerektirenler')
            ->assertDontSee('Not configured')
            ->assertDontSee('Never collected');

        $page->call('setTab', 'business')->assertSee('Hizmetler')->assertSee('İstanbul')->assertSee('İş bağlamı');
        $page->call('setTab', 'assets')->assertSee('Search Console')->assertSee('sc-domain:adadent.com.tr')->assertSee('henüz yok');
        $page->call('setTab', 'work')->assertSee('Bulgular')->assertSee('Bu bölümde kayıt yok.');
        $page->call('setTab', 'reports')->assertSet('tab', 'reports');
        $page->call('setTab', 'estate')->assertSet('tab', 'assets');
        $page->call('setTab', 'value')->assertSet('tab', 'reports');
        $page->call('setOps', 'work')->assertSet('ops', 'tasks')->assertSet('tab', 'work');

        $this->get(route('operator.brand', ['brand' => $this->brand->id, 'tab' => 'operations']))->assertOk()->assertSee('Bulgular');
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
            ->assertSee('role="tab"', false);
        $this->get('/setup')->assertNotFound();
    }

    public function test_priority_star_and_business_context_edit(): void
    {
        $offering = app(BrandOfferingService::class)->resolveOrCreate($this->brand, 'Diş Beyazlatma', actor: $this->admin)['offering'];

        Livewire::test(BrandShow::class, ['brand' => (string) $this->brand->id])
            ->call('toggleOfferingPriority', $offering->id)
            ->call('startEditingContext')
            ->set('context_business_summary', 'Kadıköy diş kliniği.')
            ->call('saveBusinessContext')
            ->assertSee('Kadıköy diş kliniği.');

        $this->assertTrue((bool) BrandOffering::query()->find($offering->id)->is_priority);
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
        $page->call('setTab', 'requests')->assertSet('tab', 'requests');
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
        $this->get(route('operator.brand.setup', ['brand' => $brandId, 'url' => 'yenimarka.com']))->assertOk();
        $this->assertSame(1, DB::table('brand_setup_proposals')->where('brand_id', $brandId)->count());
        $this->get(route('operator.brand.setup', ['brand' => $brandId, 'url' => 'yenimarka.com']))->assertOk();
        $this->assertSame(1, DB::table('brand_setup_proposals')->where('brand_id', $brandId)->count(), 'reopening does not start a second proposal');
    }
}
