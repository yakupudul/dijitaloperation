<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Modules\ModuleResource;
use App\Filament\App\Resources\Runs\RunResource;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Support\MoxDopNavigation;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
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
        $this->assertSame(MoxDopNavigation::OPERATIONS, RunResource::getNavigationGroup());
        $this->assertSame(MoxDopNavigation::SYSTEM, ModuleResource::getNavigationGroup());
        $this->assertFalse(ModuleResource::shouldRegisterNavigation());
    }

    public function test_admin_panel_registers_only_technical_tooling_resources(): void
    {
        $resources = Filament::getPanel('app')->getResources();
        sort($resources);

        $this->assertSame([ModuleResource::class, RunResource::class], array_values($resources));
        $this->assertSame([], Filament::getPanel('app')->getClusters());
        $this->assertFalse(Route::has('filament.app.resources.customers.index'));
        $this->assertFalse(Route::has('filament.app.resources.integrations.index'));
    }
}
