<?php

namespace Tests\Feature;

use App\Enums\DigitalAssetStatus;
use App\Filament\App\Resources\Customers\Resources\Brands\Pages\ViewBrand;
use App\Filament\App\Resources\Customers\Resources\Brands\RelationManagers\DigitalAssetsRelationManager;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\Pages\CreateDigitalAsset;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\Pages\EditDigitalAsset;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\Pages\ViewDigitalAsset;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DigitalAssetResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    private Brand $brand;

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
        ]);
    }

    public function test_digital_asset_can_be_created_via_filament_nested_under_brand(): void
    {
        Livewire::test(CreateDigitalAsset::class, [
            'parentRecord' => $this->brand,
        ])
            ->assertOk()
            ->fillForm([
                'name' => 'Acme Website',
                'type' => 'website',
                'status' => DigitalAssetStatus::Active->value,
                'module_id' => 'website',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertDatabaseHas('digital_assets', [
            'brand_id' => $this->brand->id,
            'name' => 'Acme Website',
            'type' => 'website',
            'status' => DigitalAssetStatus::Active->value,
            'module_id' => 'website',
        ]);
    }

    public function test_digital_asset_cannot_be_created_without_a_brand(): void
    {
        $this->expectException(QueryException::class);

        DigitalAsset::query()->create([
            'name' => 'Orphan Asset',
            'type' => 'website',
            'status' => DigitalAssetStatus::Active,
        ]);
    }

    public function test_digital_asset_mvp_fields_are_visible_on_view_page(): void
    {
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'name' => 'Visible Asset',
            'type' => 'meta_ads',
            'status' => DigitalAssetStatus::Inactive,
            'module_id' => 'meta-ads',
        ]);

        Livewire::test(ViewDigitalAsset::class, [
            'record' => $asset->getRouteKey(),
            'parentRecord' => $this->brand,
        ])
            ->assertOk()
            ->assertSchemaStateSet([
                'brand.name' => $this->brand->name,
                'name' => 'Visible Asset',
                'type' => 'meta_ads',
                'status' => DigitalAssetStatus::Inactive,
                'module_id' => 'meta-ads',
            ]);
    }

    public function test_google_ads_workspace_view_shows_productized_overview(): void
    {
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'name' => 'Ads Workspace Asset',
            'type' => 'google_ads',
            'status' => DigitalAssetStatus::Active,
            'module_id' => 'google-ads',
        ]);

        Livewire::test(ViewDigitalAsset::class, [
            'record' => $asset->getRouteKey(),
            'parentRecord' => $this->brand,
        ])
            ->assertOk()
            ->assertSee('Ads Workspace Asset')
            ->assertSee('Account snapshot')
            ->assertSee('Collect live data')
            ->assertSee('Generate AI guidance')
            ->assertDontSee('Provider resources');
    }

    public function test_digital_asset_mvp_fields_are_editable_via_filament(): void
    {
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'name' => 'Old Asset',
            'type' => 'website',
            'status' => DigitalAssetStatus::Active,
        ]);

        Livewire::test(EditDigitalAsset::class, [
            'record' => $asset->getRouteKey(),
            'parentRecord' => $this->brand,
        ])
            ->assertOk()
            ->fillForm([
                'name' => 'Updated Asset',
                'type' => 'instagram',
                'status' => DigitalAssetStatus::Archived->value,
                'module_id' => 'instagram',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertDatabaseHas('digital_assets', [
            'id' => $asset->id,
            'brand_id' => $this->brand->id,
            'name' => 'Updated Asset',
            'type' => 'instagram',
            'status' => DigitalAssetStatus::Archived->value,
            'module_id' => 'instagram',
        ]);
    }

    public function test_digital_asset_can_be_deleted_via_filament(): void
    {
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
        ]);

        Livewire::test(EditDigitalAsset::class, [
            'record' => $asset->getRouteKey(),
            'parentRecord' => $this->brand,
        ])
            ->assertOk()
            ->callAction(DeleteAction::class)
            ->assertNotified();

        $this->assertDatabaseMissing('digital_assets', [
            'id' => $asset->id,
        ]);
    }

    public function test_brand_view_exposes_digital_assets_relation_manager(): void
    {
        $brandView = Livewire::test(ViewBrand::class, [
            'record' => $this->brand->getRouteKey(),
            'parentRecord' => $this->customer,
        ])
            ->assertOk()
            ->set('activeRelationManager', 'digitalAssets');

        $this->assertStringContainsString(
            DigitalAssetsRelationManager::class,
            $brandView->html(),
            'Brand workspace Digital Assets tab must mount DigitalAssetsRelationManager',
        );
    }
}
