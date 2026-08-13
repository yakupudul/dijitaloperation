<?php

namespace Tests\Feature;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Livewire\Demo\Portfolio\AssetCreate;
use App\Livewire\Demo\Portfolio\BrandCreate;
use App\Livewire\Demo\Portfolio\CustomerCreate;
use App\Livewire\Demo\Portfolio\CustomerDetail;
use App\Livewire\Demo\Portfolio\CustomerEdit;
use App\Livewire\Demo\Portfolio\CustomersIndex;
use App\Models\Customer;
use App\Models\User;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use App\Support\Options\AgencyServiceOptions;
use App\Support\Options\CmsOptions;
use App\Support\Options\CountryOptions;
use App\Support\Options\IndustryOptions;
use App\Support\Options\LanguageOptions;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerFoundationUxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole(Roles::ADMIN);
        $this->actingAs($user);

        DemoState::reset();
    }

    public function test_customers_index_renders_directory_and_open_findings_label(): void
    {
        Livewire::test(CustomersIndex::class)
            ->assertSee('Customers')
            ->assertSee('Atlas Health Group')
            ->assertSee('Open findings')
            ->assertDontSee('Open issues')
            ->assertSee(__('operator.portfolio.new_customer_setup'))
            ->assertSee(route('demo.setup', ['entry' => 'customer'], absolute: false));
    }

    public function test_customers_index_search_and_filters_compose(): void
    {
        DemoState::addCustomer([
            'id' => 'c-filter-demo',
            'name' => 'Horizon Clinics',
            'legal_name' => 'Horizon Clinics Ltd',
            'type' => 'company',
            'status' => 'active',
            'industry' => 'dental',
            'hq_country' => 'DE',
            'hq_city' => 'Berlin',
            'services' => ['seo'],
            'responsible_user_ids' => ['u-selin'],
            'primary_email' => 'hello@horizon.example',
            'brands_count' => 0,
            'open_findings' => 0,
            'open_tasks' => 0,
        ]);

        Livewire::test(CustomersIndex::class)
            ->set('search', 'Horizon')
            ->assertSee('Horizon Clinics')
            ->assertDontSee('Atlas Health Group')
            ->call('clearFilters')
            ->set('industry', 'dental')
            ->assertSee('Horizon Clinics')
            ->set('hq_country', 'TR')
            ->assertSee('No customers match these filters');
    }

    public function test_customer_create_validates_and_persists_demo_state(): void
    {
        Livewire::test(CustomerCreate::class)
            ->set('name', '')
            ->call('save')
            ->assertHasErrors(['name'])
            ->set('name', 'Nova Health Group')
            ->set('type', 'company')
            ->set('status', 'active')
            ->set('industry', 'healthcare')
            ->set('hq_country', 'TR')
            ->set('hq_city', 'Istanbul')
            ->set('services', ['meta_ads', 'seo'])
            ->set('primary_email', 'not-an-email')
            ->call('save')
            ->assertHasErrors(['primary_email'])
            ->set('primary_email', 'ops@nova.example')
            ->set('responsible_user_ids', ['u-ayse'])
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $created = collect(DemoState::all()['customers'])->firstWhere('name', 'Nova Health Group');
        $this->assertNotNull($created);
        $this->assertSame('healthcare', $created['industry']);
        $this->assertSame('TR', $created['hq_country']);
        $this->assertSame(['meta_ads', 'seo'], $created['services']);
        $this->assertSame(['u-ayse'], $created['responsible_user_ids']);
    }

    public function test_customer_detail_workspace_tabs_and_contacts(): void
    {
        Livewire::test(CustomerDetail::class, ['customerId' => DemoCatalog::CUSTOMER_ID])
            ->assertSee('Atlas Health Group')
            ->assertSee('Needs attention')
            ->assertSee(__('operator.portfolio.account_owner_responsible'))
            ->call('setTab', 'contacts')
            ->assertSee('Dr. Elif Arslan')
            ->call('openContactForm')
            ->set('contact_name', 'Yeni Kişi')
            ->set('contact_role', 'marketing')
            ->set('contact_email', 'yeni@atlashealth.example')
            ->call('saveContact')
            ->assertSee('Yeni Kişi')
            ->call('setTab', 'operations')
            ->assertSee('Findings')
            ->assertSee('View all findings')
            ->call('setTab', 'activity')
            ->assertSee('Activity');
    }

    public function test_customer_directory_cta_is_localized(): void
    {
        app()->setLocale('tr');

        Livewire::test(CustomersIndex::class)
            ->assertSee(__('operator.portfolio.new_customer_setup'))
            ->assertSee(route('demo.setup', ['entry' => 'customer'], absolute: false));

        Livewire::test(CustomerDetail::class, ['customerId' => DemoCatalog::CUSTOMER_ID])
            ->assertSee(__('operator.portfolio.account_owner_responsible'));
    }

    public function test_customer_edit_prefills_and_saves(): void
    {
        Livewire::test(CustomerEdit::class, ['customerId' => DemoCatalog::CUSTOMER_ID])
            ->assertSet('name', 'Atlas Health Group')
            ->assertSet('hq_country', 'TR')
            ->set('hq_city', 'Ankara')
            ->set('services', ['google_ads', 'local_seo'])
            ->call('save')
            ->assertRedirect();

        $customer = DemoState::findCustomer(DemoCatalog::CUSTOMER_ID);
        $this->assertSame(['google_ads', 'local_seo'], $customer['services'] ?? null);
    }

    public function test_brand_create_uses_option_catalogs(): void
    {
        Livewire::test(BrandCreate::class, ['customerId' => DemoCatalog::CUSTOMER_ID])
            ->assertSee('Atlas Health Group')
            ->assertSee('Customer:')
            ->set('name', 'Atlas Implant EU')
            ->set('sector', 'dental')
            ->set('primary_country', 'DE')
            ->set('target_markets', ['DE', 'NL'])
            ->set('languages', ['de', 'en'])
            ->set('responsible_user_ids', ['u-selin'])
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $brand = collect(DemoState::all()['brands'])->firstWhere('name', 'Atlas Implant EU');
        $this->assertSame('dental', $brand['sector']);
        $this->assertSame('DE', $brand['primary_country']);
        $this->assertSame(['DE', 'NL'], $brand['target_markets']);
        $this->assertSame(['de', 'en'], $brand['languages']);
    }

    public function test_asset_create_shows_website_fields_conditionally_and_hides_module_id(): void
    {
        Livewire::test(AssetCreate::class, ['brandId' => DemoCatalog::BRAND_ID])
            ->assertSee('Brand:')
            ->assertDontSee('module_id')
            ->assertDontSee('Linked module')
            ->set('type', 'website')
            ->assertSee('Website details')
            ->assertSee('CMS')
            ->set('type', 'meta_ads')
            ->assertDontSee('Website details')
            ->set('type', 'website')
            ->set('name', 'Atlas Dental Website 2')
            ->set('domain', 'atlasdental.example')
            ->set('cms', 'wordpress')
            ->set('site_type', 'lead_generation')
            ->set('languages', ['tr', 'en'])
            ->set('target_countries', ['TR'])
            ->call('save')
            ->assertHasNoErrors();

        $asset = collect(DemoState::all()['demo_assets'])->firstWhere('name', 'Atlas Dental Website 2');
        $this->assertSame('wordpress', $asset['cms']);
        $this->assertSame('website', $asset['module_id']);
        $this->assertSame(['tr', 'en'], $asset['languages']);
    }

    public function test_customer_model_supports_profile_fields_and_responsible_users(): void
    {
        $user = User::factory()->create(['name' => 'Ops Lead']);

        $customer = Customer::factory()->create([
            'name' => 'Canonical Client',
            'type' => CustomerType::Company,
            'status' => CustomerStatus::Active,
            'industry' => 'healthcare',
            'hq_country' => 'TR',
            'hq_city' => 'Ankara',
            'services' => ['meta_ads', 'seo'],
        ]);

        $customer->responsibleUsers()->sync([$user->id]);

        $this->assertSame('Healthcare', $customer->industryLabel());
        $this->assertSame('Ankara, Türkiye', $customer->hqDisplay());
        $this->assertSame(['Meta Ads Management', 'SEO'], $customer->serviceLabels());
        $this->assertTrue($customer->responsibleUsers()->whereKey($user->id)->exists());
        $this->assertStringContainsString('Meta Ads Management', (string) $customer->fresh()->services_received);
    }

    public function test_option_catalogs_expose_stable_codes(): void
    {
        $this->assertSame('Türkiye', CountryOptions::label('TR'));
        $this->assertSame('Turkish', LanguageOptions::label('tr'));
        $this->assertSame('Healthcare', IndustryOptions::label('healthcare'));
        $this->assertSame('WordPress', CmsOptions::label('wordpress'));
        $this->assertArrayHasKey('google_ads', AgencyServiceOptions::options());
    }

    public function test_customer_foundation_routes_resolve(): void
    {
        $this->get(route('demo.customers'))->assertOk();
        $this->get(route('demo.customer.create'))->assertOk()->assertSee(__('operator.portfolio.add_customer'));
        $this->get(route('demo.customer', ['customerId' => DemoCatalog::CUSTOMER_ID]))->assertOk();
        $this->get(route('demo.customer.edit', ['customerId' => DemoCatalog::CUSTOMER_ID]))->assertOk();
        $this->get(route('demo.brand.create', ['customerId' => DemoCatalog::CUSTOMER_ID]))->assertOk();
        $this->get(route('demo.brand.edit', ['brandId' => DemoCatalog::BRAND_ID]))->assertOk();
        $this->get(route('demo.asset.create', ['brandId' => DemoCatalog::BRAND_ID]))->assertOk()->assertSee('Add digital asset');
    }
}
