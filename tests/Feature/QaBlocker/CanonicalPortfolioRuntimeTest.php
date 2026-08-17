<?php

namespace Tests\Feature\QaBlocker;

use App\Livewire\Demo\Dashboard;
use App\Livewire\Demo\Portfolio\AssetCreate;
use App\Livewire\Demo\Portfolio\BrandCreate;
use App\Livewire\Demo\Portfolio\BrandShow;
use App\Livewire\Demo\Portfolio\BrandsIndex;
use App\Livewire\Demo\Portfolio\CustomerCreate;
use App\Livewire\Demo\Portfolio\CustomerDetail;
use App\Livewire\Demo\Portfolio\CustomersIndex;
use App\Livewire\Demo\Portfolio\PortfolioSetupWizard;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Operator\OperatorUserDirectory;
use App\Support\Demo\DemoState;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * QA BLOCKER 002A — empty real /app, canonical portfolio persistence, no Demo runtime.
 */
class CanonicalPortfolioRuntimeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        $this->admin = User::factory()->create(['name' => 'Office QA Admin']);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
    }

    public function test_empty_real_app_has_no_demo_runtime_or_atlas_fallback(): void
    {
        $this->assertSame(0, Customer::query()->count());
        $this->assertSame(0, Brand::query()->count());
        $this->assertSame(0, DigitalAsset::query()->count());

        $html = $this->get('/app')
            ->assertOk()
            ->assertSee('Due Today')
            ->assertSee('Overdue')
            ->assertSee('Awaiting Decision')
            ->assertSee('Waiting on Client')
            ->getContent();

        $this->assertStringNotContainsString('Demo Mode', $html);
        $this->assertStringNotContainsString('Reset demo', $html);
        $this->assertStringNotContainsString('Reset Demo Mode', $html);
        $this->assertStringNotContainsString('Atlas', $html);
        $this->assertStringNotContainsString('Ayşe Demir', $html);
        $this->assertStringNotContainsString('Panorama Dental', $html);

        Livewire::test(Dashboard::class)
            ->assertOk()
            ->assertDontSee('Demo Mode')
            ->assertDontSee('Reset demo')
            ->assertDontSee('Atlas Health Group')
            ->assertDontSee('Panorama Dental');

        Livewire::test(CustomersIndex::class)
            ->assertOk()
            ->assertSee('0 records')
            ->assertSee('Create your first customer')
            ->assertDontSee('Atlas Health Group');
    }

    public function test_customer_create_persists_canonical_row_not_demo_id(): void
    {
        $before = Customer::query()->count();

        Livewire::test(CustomerCreate::class)
            ->set('name', 'Northwind Clinics')
            ->set('type', 'company')
            ->set('status', 'active')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertSame($before + 1, Customer::query()->count());
        $customer = Customer::query()->where('name', 'Northwind Clinics')->first();
        $this->assertNotNull($customer);
        $this->assertIsInt($customer->id);
        $this->assertStringStartsNotWith('c-demo-', (string) $customer->id);

        session()->regenerate();

        $this->assertTrue(Customer::query()->whereKey($customer->id)->exists());

        Livewire::test(CustomersIndex::class)
            ->assertSee('Northwind Clinics');

        Livewire::test(CustomerDetail::class, ['customerId' => (string) $customer->id])
            ->assertOk()
            ->assertSee('Northwind Clinics');

        $this->get(route('operator.customer', ['customerId' => 'c-demo-missing']))
            ->assertNotFound();
    }

    public function test_brand_create_persists_exact_customer_relation(): void
    {
        $customer = Customer::factory()->create(['name' => 'Exact Customer']);

        Livewire::test(BrandCreate::class, ['customerId' => (string) $customer->id])
            ->set('name', 'Exact Brand')
            ->set('sector', 'dental')
            ->set('primary_country', 'TR')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $brand = Brand::query()->where('name', 'Exact Brand')->first();
        $this->assertNotNull($brand);
        $this->assertSame($customer->id, $brand->customer_id);
        $this->assertNotSame('atlas-dental', (string) $brand->id);

        session()->regenerate();

        Livewire::test(BrandsIndex::class)->assertSee('Exact Brand');
        Livewire::test(BrandShow::class, ['brand' => (string) $brand->id])
            ->assertOk()
            ->assertSee('Exact Brand')
            ->assertSee('Exact Customer')
            ->assertDontSee('Atlas Dental Ankara')
            ->assertDontSee('Meta CPL');

        $this->get(route('operator.brand', ['brand' => 'atlas-dental']))->assertNotFound();
    }

    public function test_digital_assets_persist_canonical_types_without_domain_hosting(): void
    {
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id, 'name' => 'Asset Brand']);

        foreach (['website', 'google_business_profile', 'google_ads', 'meta_ads', 'ga4', 'gsc'] as $type) {
            Livewire::test(AssetCreate::class, ['brandId' => (string) $brand->id])
                ->set('name', 'Asset Brand '.$type)
                ->set('type', $type)
                ->call('save')
                ->assertHasNoErrors();
        }

        $assets = DigitalAsset::query()->where('brand_id', $brand->id)->get();
        $this->assertCount(6, $assets);
        $this->assertEqualsCanonicalizing(
            ['website', 'google_business_profile', 'google_ads', 'meta_ads', 'ga4', 'gsc'],
            $assets->pluck('type')->all(),
        );
        $this->assertSame(0, DigitalAsset::query()->whereIn('type', ['domain', 'hosting'])->count());
        $this->assertTrue($assets->every(fn (DigitalAsset $asset): bool => $asset->brand_id === $brand->id));
        $this->assertFalse($assets->contains(fn (DigitalAsset $asset): bool => str_starts_with((string) $asset->id, 'da-')));
    }

    public function test_customer_owner_options_come_from_real_users(): void
    {
        $other = User::factory()->create(['name' => 'Another Authorized User']);
        $other->assignRole(Roles::TEAM_MEMBER);

        $unauthorized = User::factory()->create(['name' => 'No App Access']);

        $options = OperatorUserDirectory::options();
        $this->assertArrayHasKey((string) $this->admin->id, $options);
        $this->assertArrayHasKey((string) $other->id, $options);
        $this->assertArrayNotHasKey((string) $unauthorized->id, $options);
        $this->assertArrayNotHasKey('u-ayse', $options);
        $this->assertStringNotContainsString('Ayşe Demir', implode(' ', $options));
        $this->assertStringNotContainsString('Mert', implode(' ', $options));

        Livewire::test(PortfolioSetupWizard::class)
            ->assertSee('Unassigned')
            ->assertSee('Office QA Admin')
            ->assertSee('Another Authorized User')
            ->assertDontSee('Ayşe Demir')
            ->assertDontSee('Selin Kaya');

        Livewire::test(CustomerCreate::class)
            ->set('name', 'Owned Customer')
            ->set('responsible_user_ids', [(string) $other->id])
            ->call('save')
            ->assertHasNoErrors();

        $customer = Customer::query()->where('name', 'Owned Customer')->first();
        $this->assertNotNull($customer);
        $this->assertTrue($customer->responsibleUsers()->whereKey($other->id)->exists());

        session()->regenerate();
        $this->assertTrue($customer->fresh()->responsibleUsers()->whereKey($other->id)->exists());
    }

    public function test_wizard_commits_canonical_portfolio_and_survives_session_flush(): void
    {
        Livewire::test(PortfolioSetupWizard::class)
            ->set('customer_name', 'Wizard Customer')
            ->set('account_owner', (string) $this->admin->id)
            ->call('next')
            ->assertSet('step', 2)
            ->set('brand_name', 'Wizard Brand')
            ->set('primary_country', 'TR')
            ->set('primary_language', 'tr')
            ->call('next')
            ->assertSet('step', 3)
            ->call('toggleAsset', 'website')
            ->call('toggleAsset', 'gbp')
            ->call('toggleAsset', 'google_ads')
            ->call('toggleAsset', 'meta_ads')
            ->call('toggleAsset', 'ga4')
            ->call('toggleAsset', 'gsc')
            ->call('next')
            ->assertSet('step', 4)
            ->assertDontSee('Atlas Dental Ankara')
            ->assertDontSee('Panorama Dental')
            ->assertDontSee('Atlas Dental Europe')
            ->assertSee('Not configured')
            ->assertSee('Configure integration first')
            ->call('next')
            ->assertSet('step', 5)
            ->assertDontSee('Dental Implant')
            ->assertDontSee('Smile Design')
            ->assertDontSee('Çankaya')
            ->call('next')
            ->assertSet('step', 6)
            ->assertSet('committed', true)
            ->assertSee('Wizard Customer')
            ->assertSee('Wizard Brand')
            ->assertDontSee('✓ Configured');

        $customer = Customer::query()->where('name', 'Wizard Customer')->first();
        $this->assertNotNull($customer);
        $brand = Brand::query()->where('customer_id', $customer->id)->where('name', 'Wizard Brand')->first();
        $this->assertNotNull($brand);
        $this->assertSame(6, DigitalAsset::query()->where('brand_id', $brand->id)->count());
        $this->assertTrue($customer->responsibleUsers()->whereKey($this->admin->id)->exists());

        session()->flush();

        $this->assertTrue(Customer::query()->whereKey($customer->id)->exists());
        $this->assertTrue(Brand::query()->whereKey($brand->id)->exists());
        $this->assertSame(6, DigitalAsset::query()->where('brand_id', $brand->id)->count());
    }

    public function test_normal_app_never_auto_seeds_demo_business_state(): void
    {
        $this->assertNull(session()->get(DemoState::SESSION_KEY));

        $this->get('/app')->assertOk();
        $this->get(route('operator.customers'))->assertOk();
        $this->get(route('operator.brands'))->assertOk();
        $this->get(route('operator.assets'))->assertOk();
        $this->get(route('operator.settings'))->assertOk();

        Livewire::test(Dashboard::class)->assertOk();
        Livewire::test(CustomersIndex::class)->assertOk();

        $state = session()->get(DemoState::SESSION_KEY);
        if (is_array($state)) {
            $this->assertSame([], $state['customers'] ?? []);
            $this->assertSame([], $state['brands'] ?? []);
            $this->assertSame([], $state['demo_assets'] ?? []);
            $encoded = json_encode($state);
            $this->assertStringNotContainsString('Atlas Health Group', (string) $encoded);
            $this->assertStringNotContainsString('Atlas Dental', (string) $encoded);
            $this->assertStringNotContainsString('Ayşe Demir', (string) $encoded);
            $this->assertStringNotContainsString('Panorama Dental', (string) $encoded);
        }

        $this->assertSame(0, Customer::query()->count());
        $this->assertSame(0, Brand::query()->count());
        $this->assertSame(0, DigitalAsset::query()->count());

        $this->assertSame([], DemoState::demoNotifications());
        $encodedNotifications = json_encode(DemoState::demoNotifications());
        $this->assertStringNotContainsString('Atlas', (string) $encodedNotifications);
        $this->assertStringNotContainsString('Ayşe Demir', (string) $encodedNotifications);
    }
}
