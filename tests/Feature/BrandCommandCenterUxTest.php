<?php

namespace Tests\Feature;

use App\Livewire\Demo\Portfolio\BrandShow;
use App\Livewire\Demo\Portfolio\BrandsIndex;
use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\SeedsCanonicalWorkTasks;
use Tests\TestCase;

class BrandCommandCenterUxTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCanonicalWorkTasks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole(Roles::ADMIN);
        $this->actingAs($user);

        $this->seedCanonicalWorkTasks();
    }

    public function test_brands_directory_search_filters_and_rollups(): void
    {
        Livewire::test(BrandsIndex::class)
            ->assertSee('Brands')
            ->assertSee('Brands managed across customer accounts')
            ->assertSee('Atlas Dental Ankara')
            ->assertSee('Atlas Health Group')
            ->assertSee('digital assets')
            ->assertSee(__('operator.portfolio.add_brand_wizard'))
            ->assertSee(route('operator.setup', ['entry' => 'brand'], absolute: false))
            ->set('search', 'Atlas Dental')
            ->assertSee('Atlas Dental Ankara')
            ->set('search', 'NoSuchBrandXYZ')
            ->assertSee('No brands match these filters.')
            ->call('clearFilters')
            ->set('customer', (string) $this->workCustomer->id)
            ->assertSee('Atlas Dental Ankara')
            ->set('asset_type', 'website')
            ->assertSee('Atlas Dental Ankara');
    }

    public function test_brands_directory_cta_is_localized(): void
    {
        app()->setLocale('tr');

        Livewire::test(BrandsIndex::class)
            ->assertSee(__('operator.portfolio.add_brand_wizard'))
            ->assertSee(route('operator.setup', ['entry' => 'brand'], absolute: false));
    }

    public function test_brands_directory_prevents_empty_onboarding_when_filtered(): void
    {
        Livewire::test(BrandsIndex::class)
            ->set('search', 'zzzz-no-match')
            ->assertSee('No brands match these filters.')
            ->assertDontSee('No brands yet');
    }

    public function test_brand_overview_command_center(): void
    {
        Livewire::test(BrandShow::class, ['brand' => (string) $this->workBrand->id])
            ->assertSee('Atlas Dental Ankara')
            ->assertSee('Atlas Health Group')
            ->assertSee('Digital estate')
            ->assertSee('Business context')
            ->assertSee('Add digital asset')
            ->assertSee('Edit brand')
            ->assertSee('Open customer')
            ->assertDontSee('Brand Health')
            ->assertDontSee('Media spend')
            ->assertDontSee('Meta CPL deteriorated');
    }

    public function test_brand_tabs_do_not_inject_fixture_intelligence(): void
    {
        Livewire::test(BrandShow::class, ['brand' => (string) $this->workBrand->id])
            ->call('setTab', 'assets')
            ->assertSee('Digital assets')
            ->assertSee('Atlas Dental Website')
            ->assertDontSee('Atlas Dental — Meta')
            ->call('setTab', 'cross_channel')
            ->assertSee('Evidence-based consistency checks')
            ->call('setTab', 'context')
            ->call('setBusinessSection', 'context')
            ->assertSee('Operator maintained')
            ->assertDontSee('Dental implants')
            ->call('setTab', 'operations')
            ->assertSee('Findings, decisions and active work')
            ->assertDontSee('Meta CPL deteriorated')
            ->assertDontSee('Replace underperforming Meta creative');
    }

    public function test_public_discovery_is_empty_without_canonical_candidates(): void
    {
        Livewire::test(BrandShow::class, ['brand' => (string) $this->workBrand->id])
            ->call('setTab', 'discovery')
            ->assertSet('tab', 'business')
            ->assertSet('businessSection', 'discovery')
            ->assertSee('Public Discovery')
            ->assertDontSee('Dental Implant')
            ->assertDontSee('Smile Design')
            ->assertDontSee('Çankaya');
    }

    public function test_brand_growth_tab_does_not_show_demo_mode_or_fixture_recommendations(): void
    {
        Livewire::test(BrandShow::class, ['brand' => (string) $this->workBrand->id])
            ->call('setTab', 'ai')
            ->assertSet('tab', 'growth')
            ->assertSee(__('operator.brand.tabs.growth'))
            ->assertDontSee('Demo Mode')
            ->assertDontSee('Replace underperforming Meta creative');
    }

    public function test_brand_scope_does_not_leak_other_brand_assets(): void
    {
        $other = Brand::factory()->create([
            'customer_id' => $this->workCustomer->id,
            'name' => 'Other Brand Leak Test',
        ]);
        DigitalAsset::factory()->create([
            'brand_id' => $other->id,
            'type' => 'website',
            'name' => 'other-leak.example',
        ]);

        Livewire::test(BrandShow::class, ['brand' => (string) $this->workBrand->id])
            ->call('setTab', 'assets')
            ->assertSee('Atlas Dental Website')
            ->assertDontSee('other-leak.example');

        Livewire::test(BrandShow::class, ['brand' => (string) $other->id])
            ->call('setTab', 'assets')
            ->assertSee('other-leak.example')
            ->assertDontSee('Atlas Dental Website');
    }

    public function test_legacy_research_tab_redirects_to_discovery(): void
    {
        Livewire::test(BrandShow::class, ['brand' => (string) $this->workBrand->id, 'tab' => 'research'])
            ->assertSet('tab', 'business');
    }

    public function test_catalog_brand_id_is_not_found(): void
    {
        $this->get(route('operator.brand', ['brand' => 'atlas-dental']))->assertNotFound();
    }
}
