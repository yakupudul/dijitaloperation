<?php

namespace Tests\Feature;

use App\Filament\App\Clusters\Settings\Pages\GeneralSettings;
use App\Filament\App\Pages\Portfolio\BrandsDirectory;
use App\Filament\App\Pages\Portfolio\DigitalAssetsDirectory;
use App\Filament\App\Resources\Customers\Pages\ViewCustomer;
use App\Filament\App\Resources\Customers\Resources\Brands\Pages\ViewBrand;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\Pages\ViewDigitalAsset;
use App\Filament\App\Resources\Findings\FindingResource;
use App\Filament\App\Resources\Modules\ModuleResource;
use App\Filament\App\Resources\Recommendations\RecommendationResource;
use App\Filament\App\Resources\Runs\RunResource;
use App\Models\Brand;
use App\Models\CoreConnection;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\Task;
use App\Models\User;
use App\Support\BrandOperationalSummary;
use App\Support\MoxDopNavigation;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MoxDopUiFoundationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    private Brand $brand;

    private DigitalAsset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel('app');

        $this->customer = Customer::factory()->create();
        $this->brand = Brand::factory()->create([
            'customer_id' => $this->customer->id,
            'sector' => 'Agency',
            'primary_country' => 'TR',
        ]);
        $this->asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'website',
            'name' => 'Moximu Website',
            'primary_url' => 'https://www.moximu.com/',
        ]);
    }

    public function test_navigation_groups_follow_agency_operations_structure(): void
    {
        $this->assertSame(MoxDopNavigation::OPERATIONS, FindingResource::getNavigationGroup());
        $this->assertSame(MoxDopNavigation::OPERATIONS, RecommendationResource::getNavigationGroup());
        $this->assertSame(MoxDopNavigation::OPERATIONS, RunResource::getNavigationGroup());
        $this->assertSame(MoxDopNavigation::SYSTEM, ModuleResource::getNavigationGroup());
        $this->assertFalse(ModuleResource::shouldRegisterNavigation());
        $this->assertSame(MoxDopNavigation::PORTFOLIO, BrandsDirectory::getNavigationGroup());
        $this->assertSame(MoxDopNavigation::PORTFOLIO, DigitalAssetsDirectory::getNavigationGroup());
    }

    public function test_portfolio_directories_list_existing_records_without_duplicate_crud(): void
    {
        Livewire::test(BrandsDirectory::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$this->brand])
            ->assertSee($this->customer->name);

        Livewire::test(DigitalAssetsDirectory::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$this->asset])
            ->assertSee('Moximu Website');
    }

    public function test_brand_workspace_shows_deterministic_operational_summary(): void
    {
        CoreConnection::query()->create([
            'digital_asset_id' => $this->asset->id,
            'type' => 'ga4',
            'name' => 'GA4',
            'enabled' => true,
            'config' => [],
            'last_error' => null,
        ]);

        Finding::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'status' => 'open',
        ]);

        Recommendation::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'status' => 'open',
        ]);

        Task::factory()->create([
            'brand_id' => $this->brand->id,
            'customer_id' => $this->customer->id,
            'digital_asset_id' => $this->asset->id,
            'status' => 'open',
        ]);

        $summary = BrandOperationalSummary::for($this->brand);

        $this->assertSame(1, $summary['digital_assets']);
        $this->assertSame(1, $summary['healthy_connected_assets']);
        $this->assertSame(1, $summary['open_findings']);
        $this->assertSame(1, $summary['open_recommendations']);
        $this->assertSame(1, $summary['open_tasks']);

        Livewire::test(ViewBrand::class, [
            'record' => $this->brand->getRouteKey(),
            'parentRecord' => $this->customer,
        ])
            ->assertOk()
            ->assertSee($this->brand->name)
            ->assertSee('Agency')
            ->assertSee('Digital assets')
            ->assertSee('Open findings')
            ->assertDontSee('Logo URL');
    }

    public function test_digital_asset_workspace_groups_analysis_actions(): void
    {
        Livewire::test(ViewDigitalAsset::class, [
            'record' => $this->asset->getRouteKey(),
            'parentRecord' => $this->brand,
        ])
            ->assertOk()
            ->assertSee('Moximu Website')
            ->assertSee('www.moximu.com')
            ->assertSee('More')
            ->assertSee('Refresh data')
            ->assertActionExists('refreshData')
            ->assertActionExists('runWebsiteDiagnosis')
            ->assertActionExists('runWebsiteGbpWebsiteUrlConsistency')
            ->assertActionExists('runWebsiteGoogleAdsLandingConsistency');
    }

    public function test_customer_workspace_uses_overview_tab_pattern(): void
    {
        Livewire::test(ViewCustomer::class, [
            'record' => $this->customer->getRouteKey(),
        ])
            ->assertOk()
            ->assertSee($this->customer->name)
            ->assertSee('Overview');
    }

    public function test_settings_general_shell_is_available_without_fake_integrations(): void
    {
        Livewire::test(GeneralSettings::class)
            ->assertOk()
            ->assertSee('Agency operations profile')
            ->assertSee('Moximu')
            ->assertDontSee('Connect Google')
            ->assertDontSee('OAuth')
            ->assertDontSee('later milestone');
    }
}
