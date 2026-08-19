<?php

namespace Tests\Feature;

use App\Livewire\Operator\AssetDataSourcesPage;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Run;
use App\Models\User;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OperatorAssetDataSourcesGuardsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Brand $brand;

    private CoreIntegration $google;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);

        $customer = Customer::factory()->create();
        $this->brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $this->google = CoreIntegration::factory()->google()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
        ]);
    }

    public function test_team_member_cannot_bind_through_data_sources(): void
    {
        $member = User::factory()->create();
        $member->assignRole(Roles::TEAM_MEMBER);
        $this->actingAs($member);

        $website = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'website',
        ]);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $this->google->id,
            'provider' => ProviderRegistry::GOOGLE,
            'resource_type' => 'ga4',
            'external_id' => 'properties/team-member',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);

        Livewire::test(AssetDataSourcesPage::class, ['assetId' => (string) $website->id])
            ->set('selectedResource.ga4', (string) $resource->id)
            ->call('bind', 'ga4')
            ->assertHasErrors(['selectedResource.ga4']);

        $this->assertSame(0, CoreAssetBinding::query()->count());
    }

    public function test_google_ads_manager_accounts_are_rejected(): void
    {
        $this->actingAs($this->admin);

        $asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'google_ads',
        ]);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $this->google->id,
            'provider' => ProviderRegistry::GOOGLE,
            'resource_type' => 'google_ads',
            'external_id' => 'customers/manager',
            'display_name' => 'MCC',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => ['is_manager' => true, 'selectable' => false],
        ]);

        Livewire::test(AssetDataSourcesPage::class, ['assetId' => (string) $asset->id])
            ->set('selectedResource.google_ads', (string) $resource->id)
            ->call('bind', 'google_ads')
            ->assertHasErrors(['selectedResource.google_ads']);

        $this->assertSame(0, CoreAssetBinding::query()->count());
    }

    public function test_changing_resource_closes_the_old_binding_and_keeps_historical_run_identity(): void
    {
        $this->actingAs($this->admin);

        $website = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'website',
        ]);
        $first = CoreExternalResource::factory()->create([
            'integration_id' => $this->google->id,
            'provider' => ProviderRegistry::GOOGLE,
            'resource_type' => 'ga4',
            'external_id' => 'properties/old',
            'display_name' => 'Old GA4',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        $second = CoreExternalResource::factory()->create([
            'integration_id' => $this->google->id,
            'provider' => ProviderRegistry::GOOGLE,
            'resource_type' => 'ga4',
            'external_id' => 'properties/new',
            'display_name' => 'New GA4',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);

        Livewire::test(AssetDataSourcesPage::class, ['assetId' => (string) $website->id])
            ->set('selectedResource.ga4', (string) $first->id)
            ->call('bind', 'ga4')
            ->assertHasNoErrors();

        $oldBinding = CoreAssetBinding::query()
            ->where('digital_asset_id', $website->id)
            ->where('external_resource_id', $first->id)
            ->firstOrFail();

        $run = Run::query()->create([
            'digital_asset_id' => $website->id,
            'core_asset_binding_id' => $oldBinding->id,
            'module_id' => 'website',
            'status' => 'completed',
            'started_at' => now()->subHour(),
            'finished_at' => now()->subHour(),
            'metadata' => ['capability' => 'ga4'],
        ]);
        Evidence::query()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $website->id,
            'source_module' => 'website',
            'type' => 'ga4_performance_summary',
            'title' => 'Old GA4 collection',
            'payload' => ['response_ok' => true, 'property' => 'properties/old'],
            'observed_at' => now()->subHour(),
        ]);

        Livewire::test(AssetDataSourcesPage::class, ['assetId' => (string) $website->id])
            ->set('selectedResource.ga4', (string) $second->id)
            ->call('bind', 'ga4')
            ->assertHasNoErrors();

        $oldBinding = $oldBinding->fresh();
        $this->assertSame(CoreAssetBinding::STATUS_DISABLED, $oldBinding->status);
        $this->assertSame($first->id, $oldBinding->external_resource_id);
        $this->assertSame('replaced', data_get($oldBinding->configuration, 'closed_reason'));
        $this->assertSame($this->admin->id, data_get($oldBinding->configuration, 'closed_by_user_id'));

        $newBinding = CoreAssetBinding::query()
            ->where('digital_asset_id', $website->id)
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->where('capability', 'ga4')
            ->firstOrFail();

        $this->assertNotSame($oldBinding->id, $newBinding->id);
        $this->assertSame($second->id, $newBinding->external_resource_id);
        $this->assertSame($this->admin->id, data_get($newBinding->configuration, 'confirmed_by_user_id'));
        $this->assertSame($oldBinding->id, $run->fresh()->core_asset_binding_id);
        $this->assertSame($first->id, $oldBinding->external_resource_id);
        $this->assertSame(
            'properties/old',
            data_get(Evidence::query()->where('run_id', $run->id)->value('payload'), 'property'),
        );
    }
}
