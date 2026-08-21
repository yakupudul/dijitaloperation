<?php

namespace Tests\Feature;

use App\Livewire\Operator\AssetDataSourcesPage;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Run;
use App\Models\User;
use App\Services\Integrations\Meta\MetaCredentialValidator;
use App\Services\Integrations\Meta\MetaOAuthService;
use App\Services\Integrations\Meta\SelectMetaDiscoveryContextService;
use App\Support\Integrations\Google\GoogleOAuthConfig;
use App\Support\Integrations\Google\GoogleScopes;
use App\Support\Integrations\Meta\MetaResourceType;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    public function test_team_member_cannot_discover_google_or_meta_resources(): void
    {
        $member = User::factory()->create();
        $member->assignRole(Roles::TEAM_MEMBER);
        $this->actingAs($member);

        $website = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'website',
        ]);
        $metaAsset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'meta_ads',
        ]);

        $this->authorizedGoogleIntegration();
        $this->authorizedMetaIntegration();

        Http::fake();

        Livewire::test(AssetDataSourcesPage::class, ['assetId' => (string) $website->id])
            ->call('discover', ProviderRegistry::GOOGLE)
            ->assertForbidden();

        Livewire::test(AssetDataSourcesPage::class, ['assetId' => (string) $metaAsset->id])
            ->call('discover', ProviderRegistry::META)
            ->assertForbidden();

        Http::assertNothingSent();
        $this->assertSame(0, CoreExternalResource::query()->count());
    }

    public function test_admin_can_discover_google_resources_from_data_sources(): void
    {
        $this->actingAs($this->admin);

        $website = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'website',
        ]);
        $this->authorizedGoogleIntegration();

        Http::fake([
            'https://analyticsadmin.googleapis.com/v1beta/accountSummaries*' => Http::response([
                'accountSummaries' => [[
                    'account' => 'accounts/1',
                    'displayName' => 'Acct',
                    'propertySummaries' => [[
                        'property' => 'properties/111',
                        'displayName' => 'Prop A',
                    ]],
                ]],
            ], 200),
            'https://www.googleapis.com/webmasters/v3/sites' => Http::response(['siteEntry' => []], 200),
            GoogleOAuthConfig::adsApiUrl('customers:listAccessibleCustomers') => Http::response(['resourceNames' => []], 200),
            'https://mybusinessaccountmanagement.googleapis.com/v1/accounts' => Http::response(['accounts' => []], 200),
            'https://mybusinessbusinessinformation.googleapis.com/v1/*' => Http::response(['locations' => []], 200),
        ]);

        Livewire::test(AssetDataSourcesPage::class, ['assetId' => (string) $website->id])
            ->call('discover', ProviderRegistry::GOOGLE)
            ->assertHasNoErrors()
            ->assertSet('messageTone', 'success');

        $this->assertSame(1, CoreExternalResource::query()->where('resource_type', 'ga4')->where('external_id', 'properties/111')->count());
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'analyticsadmin.googleapis.com'));
    }

    public function test_meta_data_sources_refresh_uses_selected_business_context_only(): void
    {
        $this->actingAs($this->admin);

        $asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'meta_ads',
        ]);
        $integration = $this->authorizedMetaIntegration();

        $selected = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => MetaResourceType::META_BUSINESS,
            'external_id' => 'biz_selected',
            'display_name' => 'Selected BM',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => MetaResourceType::META_BUSINESS,
            'external_id' => 'biz_other',
            'display_name' => 'Other BM',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        app(SelectMetaDiscoveryContextService::class)->select($integration, (string) $selected->id, $this->admin);

        $requested = [];
        Http::fake(function ($request) use (&$requested) {
            $url = $request->url();
            $requested[] = $url;

            if (str_contains($url, 'me/adaccounts')) {
                return Http::response([
                    'data' => [[
                        'account_id' => '999',
                        'id' => 'act_999',
                        'name' => 'Broad Me Account',
                    ]],
                ]);
            }
            if (str_contains($url, 'me/businesses')) {
                return Http::response([
                    'data' => [
                        ['id' => 'biz_selected', 'name' => 'Selected BM'],
                        ['id' => 'biz_other', 'name' => 'Other BM'],
                    ],
                ]);
            }
            if (str_contains($url, 'biz_selected/owned_ad_accounts')) {
                return Http::response([
                    'data' => [[
                        'account_id' => '111',
                        'id' => 'act_111',
                        'name' => 'Selected Account',
                        'account_status' => 1,
                        'currency' => 'USD',
                        'timezone_name' => 'America/Los_Angeles',
                    ]],
                ]);
            }
            if (str_contains($url, 'biz_selected/client_ad_accounts')) {
                return Http::response(['data' => []]);
            }
            if (str_contains($url, 'biz_other/')) {
                return Http::response([
                    'data' => [[
                        'account_id' => '222',
                        'id' => 'act_222',
                        'name' => 'Unselected Account',
                        'account_status' => 1,
                    ]],
                ]);
            }

            return Http::response(['data' => []]);
        });

        Livewire::test(AssetDataSourcesPage::class, ['assetId' => (string) $asset->id])
            ->call('discover', ProviderRegistry::META)
            ->assertHasNoErrors()
            ->assertSet('messageTone', 'success');

        $this->assertFalse(collect($requested)->contains(fn (string $url): bool => str_contains($url, 'me/adaccounts')));
        $this->assertFalse(collect($requested)->contains(fn (string $url): bool => str_contains($url, 'biz_other/')));
        $this->assertTrue(collect($requested)->contains(fn (string $url): bool => str_contains($url, 'biz_selected/owned_ad_accounts')));

        $this->assertSame(1, CoreExternalResource::query()->where('resource_type', MetaResourceType::META_AD_ACCOUNT)->where('external_id', 'act_111')->count());
        $this->assertSame(0, CoreExternalResource::query()->where('external_id', 'act_222')->count());
        $this->assertSame(0, CoreExternalResource::query()->where('external_id', 'act_999')->count());

        Livewire::test(AssetDataSourcesPage::class, ['assetId' => (string) $asset->id])
            ->assertSee('Selected Account')
            ->assertSee('act_111')
            ->assertDontSee('Unselected Account')
            ->assertDontSee('act_222')
            ->assertDontSee('Broad Me Account');
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

    private function authorizedGoogleIntegration(): CoreIntegration
    {
        config([
            'moxdop.google.client_id' => 'test-client.apps.googleusercontent.com',
            'moxdop.google.client_secret' => 'test-client-secret',
            'moxdop.google.developer_token' => 'test-dev-token',
            'moxdop.google.include_gbp_scope' => false,
            'moxdop.google.gbp_discovery_enabled' => false,
            'moxdop.google.ads_api_version' => 'v25',
        ]);

        $this->google->forceFill([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => [
                'granted_scopes' => [
                    GoogleScopes::ANALYTICS_READONLY,
                    GoogleScopes::SEARCH_CONSOLE_READONLY,
                    GoogleScopes::ADWORDS,
                ],
            ],
        ])->save();

        CoreIntegrationCredential::factory()->provider()->create([
            'integration_id' => $this->google->id,
            'encrypted_payload' => [
                'client_id' => 'test-client.apps.googleusercontent.com',
                'client_secret' => 'test-client-secret',
                'developer_token' => 'test-dev-token',
            ],
        ]);

        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $this->google->id,
            'encrypted_payload' => [
                'access_token' => 'atok',
                'refresh_token' => 'rtok',
                'scope' => implode(' ', [
                    GoogleScopes::ANALYTICS_READONLY,
                    GoogleScopes::SEARCH_CONSOLE_READONLY,
                    GoogleScopes::ADWORDS,
                ]),
            ],
            'expires_at' => now()->addHour(),
        ]);

        return $this->google->fresh(['authorizationCredential', 'providerCredential']);
    }

    private function authorizedMetaIntegration(): CoreIntegration
    {
        config([
            'moxdop.meta.app_id' => '111222333',
            'moxdop.meta.app_secret' => 'test-meta-app-secret',
            'moxdop.meta.use_appsecret_proof' => false,
        ]);

        $integration = CoreIntegration::factory()->meta()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => [
                'auth_status' => 'connected',
                'connection_status' => 'connected',
                'credential_status' => MetaCredentialValidator::STATUS_VALID,
                'granted_permissions' => ['ads_read', 'business_management'],
                'requested_permissions' => ['ads_read', 'business_management'],
                'auth_method' => 'oauth',
            ],
        ]);
        CoreIntegrationCredential::factory()->create([
            'integration_id' => $integration->id,
            'credential_type' => CoreIntegrationCredential::TYPE_PROVIDER,
            'encrypted_payload' => [
                'access_token' => 'EAAG-authorized',
                'token_type' => MetaOAuthService::TOKEN_TYPE_LONG_LIVED_USER,
                'granted_permissions' => ['ads_read', 'business_management'],
            ],
        ]);

        return $integration->fresh(['providerCredential']);
    }
}
