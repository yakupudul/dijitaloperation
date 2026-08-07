<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Customers\CustomerResource;
use App\Filament\App\Resources\Customers\Resources\Brands\Pages\ViewBrand;
use App\Filament\App\Resources\Customers\Resources\Brands\RelationManagers\DigitalAssetsRelationManager;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\Pages\ViewDigitalAsset;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers\ConnectionsRelationManager;
use App\Filament\App\Resources\Findings\FindingResource;
use App\Filament\App\Resources\Modules\ModuleResource;
use App\Filament\App\Resources\Recommendations\RecommendationResource;
use App\Filament\App\Resources\Runs\RunResource;
use App\Models\Brand;
use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
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

    public function test_brand_digital_assets_relation_manager_exposes_create_action_on_view_brand(): void
    {
        $manager = new DigitalAssetsRelationManager;
        $manager->pageClass = ViewBrand::class;
        $manager->ownerRecord = $this->brand;

        $this->assertFalse($manager->isReadOnly());

        Livewire::test(
            DigitalAssetsRelationManager::class,
            [
                'ownerRecord' => $this->brand,
                'pageClass' => ViewBrand::class,
            ],
        )
            ->assertOk()
            ->assertTableActionExists('create')
            ->assertTableActionVisible('create')
            ->assertTableActionHasLabel('create', 'Create Digital Asset')
            ->assertSee('Create Digital Asset');
    }

    public function test_digital_asset_created_from_brand_relation_manager_is_bound_to_that_brand_only(): void
    {
        $otherBrand = Brand::factory()->create(['customer_id' => $this->customer->id]);

        Livewire::test(
            DigitalAssetsRelationManager::class,
            [
                'ownerRecord' => $this->brand,
                'pageClass' => ViewBrand::class,
            ],
        )
            ->callTableAction('create', data: [
                'name' => 'Bound Asset',
                'type' => 'website',
                'status' => 'active',
            ])
            ->assertHasNoTableActionErrors();

        $asset = DigitalAsset::query()->where('name', 'Bound Asset')->firstOrFail();

        $this->assertSame($this->brand->id, $asset->brand_id);
        $this->assertNotSame($otherBrand->id, $asset->brand_id);

        Livewire::test(ViewDigitalAsset::class, [
            'record' => $asset->getRouteKey(),
            'parentRecord' => $this->brand,
        ])->assertOk();
    }

    public function test_digital_asset_connections_relation_manager_exposes_management_actions(): void
    {
        $manager = new ConnectionsRelationManager;
        $manager->pageClass = ViewDigitalAsset::class;
        $manager->ownerRecord = $this->asset;

        $this->assertFalse($manager->isReadOnly());

        Livewire::test(ConnectionsRelationManager::class, [
            'ownerRecord' => $this->asset,
            'pageClass' => ViewDigitalAsset::class,
        ])
            ->assertOk()
            ->assertTableActionExists('create')
            ->assertTableActionVisible('create')
            ->assertTableActionHasLabel('create', 'Create Connection')
            ->assertSee('No connections yet');
    }

    public function test_connection_created_from_digital_asset_is_bound_and_credentials_stay_encrypted(): void
    {
        $otherAsset = DigitalAsset::factory()->create(['brand_id' => $this->brand->id]);

        Livewire::test(ConnectionsRelationManager::class, [
            'ownerRecord' => $this->asset,
            'pageClass' => ViewDigitalAsset::class,
        ])
            ->callTableAction('create', data: [
                'type' => 'ga4',
                'name' => 'GA4 Read-Only',
                'enabled' => true,
                'config' => ['property_id' => 'properties/123'],
                'credentials_json' => json_encode([
                    'client_id' => 'ui-client',
                    'client_secret' => 'ui-secret-value',
                    'refresh_token' => 'ui-refresh',
                ], JSON_THROW_ON_ERROR),
            ])
            ->assertHasNoTableActionErrors();

        $connection = CoreConnection::query()->where('name', 'GA4 Read-Only')->firstOrFail();

        $this->assertSame($this->asset->id, $connection->digital_asset_id);
        $this->assertNotSame($otherAsset->id, $connection->digital_asset_id);
        $this->assertTrue($connection->enabled);
        $this->assertSame(['property_id' => 'properties/123'], $connection->config);

        $credential = CoreConnectionCredential::query()
            ->where('connection_id', $connection->id)
            ->firstOrFail();

        $stored = DB::table('core_connection_credentials')
            ->where('id', $credential->id)
            ->value('encrypted_payload');

        $this->assertIsString($stored);
        $this->assertStringNotContainsString('ui-secret-value', $stored);
        $this->assertStringNotContainsString('ui-refresh', $stored);
        $this->assertSame('ui-secret-value', $credential->encrypted_payload['client_secret']);
        $this->assertArrayNotHasKey('encrypted_payload', $credential->toArray());

        Livewire::test(ConnectionsRelationManager::class, [
            'ownerRecord' => $this->asset,
            'pageClass' => ViewDigitalAsset::class,
        ])
            ->assertCanSeeTableRecords([$connection])
            ->mountTableAction('edit', $connection)
            ->assertSchemaStateSet([
                'name' => 'GA4 Read-Only',
                'credentials_json' => null,
            ]);
    }

    public function test_unauthorized_user_cannot_access_management_create_routes(): void
    {
        $unauthorized = User::factory()->create();
        $this->actingAs($unauthorized);

        $this->get(CustomerResource::getUrl('view', ['record' => $this->customer]))
            ->assertForbidden();
    }

    public function test_pipeline_and_module_resources_remain_without_manual_create(): void
    {
        $this->assertFalse(RunResource::canCreate());
        $this->assertFalse(FindingResource::canCreate());
        $this->assertFalse(RecommendationResource::canCreate());
        $this->assertFalse(ModuleResource::canCreate());
    }

    public function test_view_digital_asset_exposes_connections_relation_manager(): void
    {
        Livewire::test(ViewDigitalAsset::class, [
            'record' => $this->asset->getRouteKey(),
            'parentRecord' => $this->brand,
        ])
            ->assertOk()
            ->assertSeeLivewire(ConnectionsRelationManager::class);
    }
}
