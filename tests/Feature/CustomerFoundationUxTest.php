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
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Operator\OperatorPortfolioPresenter;
use App\Support\Options\AgencyServiceOptions;
use App\Support\Options\CityOptions;
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

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        $this->user = User::factory()->create();
        $this->user->assignRole(Roles::ADMIN);
        $this->actingAs($this->user);
    }

    public function test_customers_index_renders_directory_and_open_findings_label(): void
    {
        Customer::factory()->create(['name' => 'Horizon Clinics']);

        Livewire::test(CustomersIndex::class)
            ->assertSee('Customers')
            ->assertSee('Horizon Clinics')
            ->assertSee('Open findings')
            ->assertDontSee('Open issues')
            ->assertSee(__('operator.portfolio.new_customer_setup'))
            ->assertSee(route('operator.setup', ['entry' => 'customer'], absolute: false));
    }

    public function test_customers_index_search_and_filters_compose(): void
    {
        Customer::factory()->create([
            'name' => 'Horizon Clinics',
            'legal_name' => 'Horizon Clinics Ltd',
            'type' => CustomerType::Company,
            'status' => CustomerStatus::Active,
            'industry' => 'dental',
            'hq_country' => 'DE',
            'hq_city' => 'Berlin',
            'services' => ['seo'],
            'primary_email' => 'hello@horizon.example',
        ]);
        Customer::factory()->create(['name' => 'Other Client', 'industry' => 'healthcare']);

        Livewire::test(CustomersIndex::class)
            ->set('search', 'Horizon')
            ->assertSee('Horizon Clinics')
            ->assertDontSee('Other Client')
            ->call('clearFilters')
            ->set('industry', 'dental')
            ->assertSee('Horizon Clinics')
            ->set('hq_country', 'TR')
            ->assertSee(__('operator.forms.no_match_filters'));
    }

    public function test_customer_create_validates_and_persists_canonical_customer(): void
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
            ->set('responsible_user_ids', [(string) $this->user->id])
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $created = Customer::query()->where('name', 'Nova Health Group')->first();
        $this->assertNotNull($created);
        $this->assertSame('healthcare', $created->industry);
        $this->assertSame('TR', $created->hq_country);
        $this->assertSame(['meta_ads', 'seo'], $created->services);
        $this->assertTrue($created->responsibleUsers()->whereKey($this->user->id)->exists());
    }

    public function test_customer_detail_workspace_tabs_and_contacts(): void
    {
        $customer = Customer::factory()->create(['name' => 'Nova Health Group']);

        Livewire::test(CustomerDetail::class, ['customerId' => (string) $customer->id])
            ->assertSee('Nova Health Group')
            ->assertSee(__('operator.portfolio.account_owner_responsible'))
            ->call('setTab', 'relationship')
            ->assertSee(__('operator.service_scope.title'))
            ->call('openContactForm')
            ->set('contact_name', 'Yeni Kişi')
            ->set('contact_role', 'marketing')
            ->set('contact_email', 'yeni@nova.example')
            ->call('saveContact')
            ->assertSee('Yeni Kişi')
            ->assertSee(__('operator.customer.actions.open_files'))
            ->assertSee(__('operator.customer.actions.view_activity'))
            ->assertSee(__('operator.customer.actions.open_work'));
    }

    public function test_customer_directory_cta_is_localized(): void
    {
        $customer = Customer::factory()->create();
        app()->setLocale('tr');

        Livewire::test(CustomersIndex::class)
            ->assertSee(__('operator.portfolio.new_customer_setup'))
            ->assertSee(route('operator.setup', ['entry' => 'customer'], absolute: false));

        Livewire::test(CustomerDetail::class, ['customerId' => (string) $customer->id])
            ->assertSee(__('operator.portfolio.account_owner_responsible'));
    }

    public function test_customer_edit_prefills_and_saves(): void
    {
        $customer = Customer::factory()->create([
            'name' => 'Nova Health Group',
            'hq_country' => 'TR',
            'hq_city' => 'Istanbul',
            'services' => ['seo'],
        ]);

        Livewire::test(CustomerEdit::class, ['customerId' => (string) $customer->id])
            ->assertSet('name', 'Nova Health Group')
            ->assertSet('hq_country', 'TR')
            ->set('hq_city', 'Ankara')
            ->set('services', ['google_ads', 'local_seo'])
            ->call('save')
            ->assertRedirect();

        $customer->refresh();
        $this->assertSame(['google_ads', 'local_seo'], $customer->services);
        $this->assertSame('Ankara', $customer->hq_city);
    }

    public function test_brand_create_uses_option_catalogs(): void
    {
        $customer = Customer::factory()->create(['name' => 'Nova Health Group']);

        Livewire::test(BrandCreate::class, ['customerId' => (string) $customer->id])
            ->assertSee('Nova Health Group')
            ->assertSee('Customer:')
            ->set('name', 'Nova Implant EU')
            ->set('sector', 'dental')
            ->set('primary_country', 'DE')
            ->set('target_markets', ['DE', 'NL'])
            ->set('languages', ['de', 'en'])
            ->set('responsible_user_ids', [(string) $this->user->id])
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $brand = Brand::query()->where('name', 'Nova Implant EU')->first();
        $this->assertNotNull($brand);
        $this->assertSame($customer->id, $brand->customer_id);
        $this->assertSame('dental', $brand->sector);
        $this->assertSame('DE', $brand->primary_country);
        $this->assertSame(['DE', 'NL'], $brand->target_markets);
        $this->assertSame(['de', 'en'], $brand->languages);
    }

    public function test_asset_create_shows_website_fields_conditionally_and_hides_module_id(): void
    {
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);

        Livewire::test(AssetCreate::class, ['brandId' => (string) $brand->id])
            ->assertSee('Brand:')
            ->assertDontSee('module_id')
            ->assertDontSee('Linked module')
            ->set('type', 'website')
            ->assertSee('Website details')
            ->assertSee('CMS')
            ->set('type', 'meta_ads')
            ->assertDontSee('Website details')
            ->set('type', 'website')
            ->set('name', 'Nova Website 2')
            ->set('domain', 'nova.example')
            ->set('cms', 'wordpress')
            ->set('site_type', 'lead_generation')
            ->set('languages', ['tr', 'en'])
            ->set('target_countries', ['TR'])
            ->call('save')
            ->assertHasNoErrors();

        $asset = DigitalAsset::query()->where('name', 'Nova Website 2')->first();
        $this->assertNotNull($asset);
        $this->assertSame($brand->id, $asset->brand_id);
        $this->assertSame('wordpress', $asset->cms);
        $this->assertSame('website', $asset->type);
        $this->assertSame(['tr', 'en'], $asset->languages);
    }

    public function test_customer_model_supports_profile_fields_and_responsible_users(): void
    {
        $user = User::factory()->create(['name' => 'Ops Lead']);
        $user->assignRole(Roles::TEAM_MEMBER);

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
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);

        $this->get(route('operator.customers'))->assertOk();
        $this->get(route('operator.customer.create'))->assertOk()->assertSee(__('operator.portfolio.add_customer'));
        $this->get(route('operator.customer', ['customerId' => $customer->id]))->assertOk();
        $this->get(route('operator.customer.edit', ['customerId' => $customer->id]))->assertOk();
        $this->get(route('operator.brand.create', ['customerId' => $customer->id]))->assertOk();
        $this->get(route('operator.brand.edit', ['brandId' => $brand->id]))->assertOk();
        $this->get(route('operator.asset.create', ['brandId' => $brand->id]))->assertOk()->assertSee('Add digital asset');
        $this->get(route('operator.customer', ['customerId' => 'c-demo-atlas']))->assertNotFound();
        $this->get(route('operator.brand', ['brand' => 'atlas-dental']))->assertNotFound();
    }

    public function test_hq_city_clears_when_country_changes_to_incompatible_catalog(): void
    {
        Livewire::test(CustomerCreate::class)
            ->set('hq_country', 'TR')
            ->set('hq_city', 'Istanbul')
            ->assertSet('hq_city', 'Istanbul')
            ->set('hq_country', 'DE')
            ->assertSet('hq_city', '')
            ->assertSet('hq_city_other', '');
    }

    public function test_hq_city_keeps_compatible_catalog_value_when_country_unchanged(): void
    {
        Livewire::test(CustomerCreate::class)
            ->set('hq_country', 'TR')
            ->set('hq_city', 'Istanbul')
            ->set('hq_country', 'TR')
            ->assertSet('hq_city', 'Istanbul');
    }

    public function test_hq_city_other_persists_manual_entry_not_other_token(): void
    {
        Livewire::test(CustomerCreate::class)
            ->set('name', 'City Other Client')
            ->set('type', 'company')
            ->set('status', 'active')
            ->set('hq_country', 'TR')
            ->set('hq_city', CityOptions::OTHER)
            ->set('hq_city_other', 'Canakkale')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $created = Customer::query()->where('name', 'City Other Client')->first();
        $this->assertNotNull($created);
        $this->assertSame('Canakkale', $created->hq_city);
        $this->assertNotSame(CityOptions::OTHER, $created->hq_city);
    }

    public function test_specialist_open_url_includes_canonical_asset_id(): void
    {
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);

        foreach (['website', 'google_business_profile', 'google_ads', 'meta_ads', 'ga4', 'gsc'] as $type) {
            $asset = DigitalAsset::factory()->create([
                'brand_id' => $brand->id,
                'type' => $type,
                'name' => 'Open '.$type,
            ]);
            $presented = OperatorPortfolioPresenter::asset($asset->fresh(['brand.customer']));
            $this->assertSame(['assetId' => $asset->id], $presented['route_params']);
            $this->assertStringContainsString('/'.$asset->id, parse_url($presented['url'], PHP_URL_PATH) ?: '');
            $this->assertDoesNotMatchRegularExpression('#/assets/(website|gbp|google-ads|meta|analytics|search-console)$#', parse_url($presented['url'], PHP_URL_PATH) ?: '');
        }
    }
}
