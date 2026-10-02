<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Modules\ModuleResource;
use App\Filament\App\Resources\Runs\RunResource;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FilamentManagementFlowAuditTest extends TestCase
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
        $this->brand = Brand::factory()->create(['customer_id' => $this->customer->id]);
        $this->asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'website',
            'name' => 'Audit Website',
        ]);
    }

    public function test_pipeline_and_module_resources_remain_without_manual_create(): void
    {
        $this->assertFalse(RunResource::canCreate());
        $this->assertFalse(ModuleResource::canCreate());
    }
}
