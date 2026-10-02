<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Runs\Pages\ViewRun;
use App\Filament\App\Resources\Runs\RunResource;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\Run;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use MoxDop\Website\Workspace\WebsiteWorkspaceData;
use Tests\TestCase;

class WebsiteWorkspaceProductizationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Brand $brand;

    private DigitalAsset $website;

    private CoreIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel('app');

        $customer = Customer::factory()->create();
        $this->brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $this->website = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'website',
            'name' => 'Moximu Website',
            'domain' => 'moximu.com',
            'primary_url' => 'https://www.moximu.com/',
        ]);

        $this->integration = CoreIntegration::factory()->google()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
        ]);
        CoreIntegrationCredential::factory()->provider()->create([
            'integration_id' => $this->integration->id,
            'encrypted_payload' => [
                'client_id' => 'cid',
                'client_secret' => 'csecret',
                'developer_token' => 'dev',
            ],
        ]);
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $this->integration->id,
            'encrypted_payload' => [
                'access_token' => 'atok',
                'refresh_token' => 'rtok',
            ],
            'expires_at' => now()->addHour(),
        ]);
    }

    public function test_run_detail_is_human_readable_with_collapsed_raw_payload(): void
    {
        $run = Run::factory()->create([
            'digital_asset_id' => $this->website->id,
            'module_id' => 'website',
            'status' => 'completed',
            'metadata' => [
                'capability' => 'search_console',
                'resource_display_name' => 'moximu.com',
                'period' => [
                    'current' => ['start' => '2026-07-11', 'end' => '2026-08-07'],
                    'previous' => ['start' => '2026-06-13', 'end' => '2026-07-10'],
                ],
            ],
        ]);
        Evidence::factory()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $this->website->id,
            'source_module' => 'website',
            'type' => 'gsc_performance_summary',
            'payload' => [
                'response_ok' => true,
                'current' => ['clicks' => 10, 'impressions' => 100, 'ctr' => 0.1, 'position' => 5],
                'previous' => ['clicks' => 8, 'impressions' => 90, 'ctr' => 0.09, 'position' => 5.2],
                'deltas' => [
                    'clicks' => ['absolute' => 2, 'percent' => 25],
                    'impressions' => ['absolute' => 10, 'percent' => 11.11],
                    'ctr' => ['absolute' => 0.01, 'percent' => 11.11],
                    'position' => ['absolute' => -0.2, 'percent' => -3.85],
                ],
            ],
        ]);

        $this->assertSame('Search Console data refresh', RunResource::activityTitle($run));
        $this->assertSame('Activity', RunResource::getNavigationLabel());

        Livewire::test(ViewRun::class, ['record' => $run->getRouteKey()])
            ->assertOk()
            ->assertSee('Search Console data refresh')
            ->assertSee('Technical details')
            ->assertSee('Raw evidence')
            ->assertDontSee('access_token');
    }

    public function test_legacy_bindings_remain_intact_and_no_secrets_in_workspace_data(): void
    {
        $resource = CoreExternalResource::factory()->searchConsole()->create([
            'integration_id' => $this->integration->id,
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        $binding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->website->id,
            'external_resource_id' => $resource->id,
            'capability' => 'search_console',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);

        Finding::factory()->create([
            'digital_asset_id' => $this->website->id,
            'source_module' => 'website',
            'status' => 'open',
            'severity' => 'high',
            'title' => 'Search Console clicks declined',
        ]);

        $encoded = json_encode(app(WebsiteWorkspaceData::class)->for($this->website));
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('atok', $encoded);
        $this->assertStringNotContainsString('access_token', $encoded);
        $this->assertStringNotContainsString('client_secret', $encoded);
        $this->assertDatabaseHas('core_asset_bindings', ['id' => $binding->id, 'capability' => 'search_console']);
    }
}
