<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\Pages\ViewDigitalAsset;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers\ConnectionsRelationManager;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers\WebsiteConnectionsRelationManager;
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
use Illuminate\Support\Facades\Http;
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

    public function test_refresh_data_stays_on_workspace_and_evaluates_rules(): void
    {
        $resource = CoreExternalResource::factory()->searchConsole()->create([
            'integration_id' => $this->integration->id,
            'external_id' => 'sc-domain:moximu.com',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->website->id,
            'external_resource_id' => $resource->id,
            'capability' => 'search_console',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);

        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites/*' => Http::response([
                'rows' => [[
                    'clicks' => 200,
                    'impressions' => 2000,
                    'ctr' => 0.1,
                    'position' => 8.0,
                ]],
            ], 200),
        ]);

        Livewire::test(ViewDigitalAsset::class, [
            'record' => $this->website->getRouteKey(),
            'parentRecord' => $this->brand,
        ])
            ->assertActionExists('refreshData')
            ->assertActionHidden('collectLiveData')
            ->callAction('refreshData')
            ->assertHasNoActionErrors()
            ->assertNoRedirect();

        $this->assertDatabaseHas('runs', [
            'digital_asset_id' => $this->website->id,
            'module_id' => 'website',
        ]);
        $this->assertTrue(
            Evidence::query()->where('digital_asset_id', $this->website->id)->where('type', 'gsc_performance_summary')->exists()
        );
    }

    public function test_overview_uses_latest_successful_evidence_and_formats_kpis(): void
    {
        $run = Run::factory()->create([
            'digital_asset_id' => $this->website->id,
            'module_id' => 'website',
            'status' => 'completed',
            'metadata' => ['capability' => 'search_console'],
        ]);
        Evidence::factory()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $this->website->id,
            'source_module' => 'website',
            'type' => 'gsc_performance_summary',
            'payload' => [
                'response_ok' => true,
                'requested_period' => ['start' => '2026-07-11', 'end' => '2026-08-07'],
                'comparison_period' => ['start' => '2026-06-13', 'end' => '2026-07-10'],
                'current' => ['clicks' => 38, 'impressions' => 1000, 'ctr' => 0.038, 'position' => 12.2],
                'previous' => ['clicks' => 26, 'impressions' => 800, 'ctr' => 0.0325, 'position' => 11.0],
                'deltas' => [
                    'clicks' => ['absolute' => 12, 'percent' => 46.15],
                    'impressions' => ['absolute' => 200, 'percent' => 25.0],
                    'ctr' => ['absolute' => 0.0055, 'percent' => 16.92],
                    'position' => ['absolute' => 1.2, 'percent' => 10.9],
                ],
            ],
        ]);

        $data = app(WebsiteWorkspaceData::class)->for($this->website);
        $clicks = collect($data['kpis'])->firstWhere('label', 'Organic clicks');
        $this->assertNotNull($clicks);
        $this->assertSame('38', $clicks['value']);
        $this->assertStringContainsString('46.2%', str_replace(',', '', $clicks['delta_label'] ?? ''));
        $this->assertStringContainsString('↑', $clicks['delta_label'] ?? '');

        Livewire::test(ViewDigitalAsset::class, [
            'record' => $this->website->getRouteKey(),
            'parentRecord' => $this->brand,
        ])
            ->assertOk()
            ->assertSee('Organic clicks')
            ->assertSee('38')
            ->assertDontSee('Asset identity')
            ->assertDontSee('Credentials JSON');
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

    public function test_connections_selector_is_capability_scoped_and_hides_generic_bind_when_both_bound(): void
    {
        $gsc = CoreExternalResource::factory()->searchConsole()->create([
            'integration_id' => $this->integration->id,
            'external_id' => 'sc-domain:moximu.com',
            'display_name' => 'moximu.com',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        $ga4a = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'resource_type' => 'ga4',
            'external_id' => 'properties/111',
            'display_name' => 'GA4 A',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        $ga4b = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'resource_type' => 'ga4',
            'external_id' => 'properties/222',
            'display_name' => 'GA4 B',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);

        CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->website->id,
            'external_resource_id' => $gsc->id,
            'capability' => 'search_console',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);
        CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->website->id,
            'external_resource_id' => $ga4a->id,
            'capability' => 'ga4',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);

        $workspace = app(WebsiteWorkspaceData::class);
        $this->assertTrue($workspace->bothProviderCapabilitiesBound($this->website));

        $ga4Options = $workspace->availableResourcesForCapability(
            $this->website,
            'ga4',
            CoreAssetBinding::query()->where('capability', 'ga4')->value('id'),
        )->pluck('external_id')->all();
        $this->assertContains('properties/222', $ga4Options);
        $this->assertNotContains('sc-domain:moximu.com', $ga4Options);

        $gscOptions = $workspace->availableResourcesForCapability(
            $this->website,
            'search_console',
            CoreAssetBinding::query()->where('capability', 'search_console')->value('id'),
        )->pluck('resource_type')->unique()->all();
        $this->assertSame(['search_console'], $gscOptions);

        Livewire::test(WebsiteConnectionsRelationManager::class, [
            'ownerRecord' => $this->website,
            'pageClass' => ViewDigitalAsset::class,
        ])
            ->assertOk()
            ->assertSee('Google Analytics 4')
            ->assertSee('Google Search Console')
            ->assertSee('WordPress')
            ->assertSee('GA4 and Search Console are connected')
            ->assertDontSee('Bind resource')
            ->assertDontSee('Credentials JSON')
            ->assertDontSee('External resource')
            ->mountAction('changeGa4')
            ->assertActionMounted('changeGa4');

        Livewire::test(WebsiteConnectionsRelationManager::class, [
            'ownerRecord' => $this->website,
            'pageClass' => ViewDigitalAsset::class,
        ])
            ->mountAction('changeSearchConsole')
            ->assertActionMounted('changeSearchConsole');

        Livewire::test(WebsiteConnectionsRelationManager::class, [
            'ownerRecord' => $this->website,
            'pageClass' => ViewDigitalAsset::class,
        ])
            ->mountAction('manageWordPress')
            ->assertActionMounted('manageWordPress')
            ->assertDontSee('Credentials JSON')
            ->assertDontSee('Meta Ads')
            ->assertDontSee('Instagram');

        $this->assertSame(['wordpress'], ConnectionsRelationManager::creatableConnectionTypes());
        $this->assertContains($ga4b->id, array_keys(
            // ensure B remains choosable for change
            $workspace->availableResourcesForCapability(
                $this->website,
                'ga4',
                CoreAssetBinding::query()->where('capability', 'ga4')->value('id'),
            )->mapWithKeys(fn ($r) => [$r->id => true])->all()
        ));
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
