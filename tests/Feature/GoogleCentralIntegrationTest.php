<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\Pages\ViewDigitalAsset;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers\AssetBindingsRelationManager;
use App\Filament\App\Resources\Integrations\IntegrationResource;
use App\Filament\App\Resources\Integrations\Pages\ViewIntegration;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Integrations\Google\GoogleOAuthService;
use App\Services\Integrations\Google\GoogleResourceRefreshService;
use App\Support\Integrations\AssetBindingCompatibility;
use App\Support\Integrations\Google\GoogleAuthStatus;
use App\Support\Integrations\Google\GoogleScopes;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class GoogleCentralIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CoreIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        config([
            'moxdop.google.client_id' => 'test-client-id',
            'moxdop.google.client_secret' => 'test-client-secret',
            'moxdop.google.redirect_uri' => null,
            'app.url' => 'http://127.0.0.1:8000',
            'moxdop.google.developer_token' => 'test-dev-token',
            'moxdop.google.include_gbp_scope' => false,
            'moxdop.google.gbp_discovery_enabled' => false,
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel('app');

        $this->integration = CoreIntegration::factory()->google()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
        ]);
    }

    public function test_authorize_redirect_includes_state_and_readonly_scopes(): void
    {
        $response = $this->get(route('integrations.google.authorize', $this->integration));

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('accounts.google.com', $location);
        $this->assertStringContainsString('access_type=offline', $location);
        $this->assertStringContainsString(urlencode(GoogleScopes::SEARCH_CONSOLE_READONLY), $location);
        $this->assertStringContainsString(urlencode(GoogleScopes::ANALYTICS_READONLY), $location);
        $this->assertStringContainsString(urlencode(GoogleScopes::ADWORDS), $location);
        $this->assertStringNotContainsString(urlencode(GoogleScopes::BUSINESS_MANAGE), $location);
        $this->assertMatchesRegularExpression('/[?&]state=[A-Za-z0-9]+/', $location);
    }

    public function test_oauth_callback_encrypts_tokens_and_rejects_invalid_state(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-secret-value',
                'refresh_token' => 'refresh-secret-value',
                'expires_in' => 3600,
                'scope' => implode(' ', GoogleScopes::requested()),
                'token_type' => 'Bearer',
            ], 200),
        ]);

        Cache::put('google_oauth_state:valid-state', [
            'integration_id' => $this->integration->id,
            'user_id' => $this->admin->id,
        ], now()->addMinutes(10));

        $this->get(route('integrations.google.callback', [
            'code' => 'auth-code',
            'state' => 'valid-state',
        ]))->assertRedirect(route('demo.integrations.google'));

        $credential = CoreIntegrationCredential::query()
            ->where('integration_id', $this->integration->id)
            ->firstOrFail();

        $stored = DB::table('core_integration_credentials')
            ->where('id', $credential->id)
            ->value('encrypted_payload');

        $this->assertIsString($stored);
        $this->assertStringNotContainsString('access-secret-value', $stored);
        $this->assertStringNotContainsString('refresh-secret-value', $stored);
        $this->assertSame('refresh-secret-value', $credential->encrypted_payload['refresh_token']);
        $this->assertArrayNotHasKey('encrypted_payload', $credential->toArray());
        $this->assertSame(GoogleAuthStatus::CONNECTED, GoogleAuthStatus::for($this->integration->fresh(['credential'])));

        $this->get(route('integrations.google.callback', [
            'code' => 'auth-code',
            'state' => 'bad-state',
        ]))->assertRedirect();
    }

    public function test_oauth_denial_and_missing_app_config_are_safe(): void
    {
        config(['moxdop.google.client_id' => null, 'moxdop.google.client_secret' => null]);

        $this->get(route('integrations.google.authorize', $this->integration))
            ->assertRedirect(route('demo.integrations.google'));

        $this->assertSame(GoogleAuthStatus::NOT_CONFIGURED, GoogleAuthStatus::for($this->integration->fresh()));

        config([
            'moxdop.google.client_id' => 'test-client-id',
            'moxdop.google.client_secret' => 'test-client-secret',
        ]);

        $this->get(route('integrations.google.callback', [
            'error' => 'access_denied',
            'state' => 'x',
        ]))->assertRedirect(route('demo.integrations'));

        $this->assertStringStartsWith(url('/app'), route('demo.integrations.google'));
        $this->assertStringNotContainsString('/system', route('demo.integrations.google'));
        $this->assertStringNotContainsString('/system', route('demo.integrations'));
    }

    public function test_token_refresh_updates_encrypted_access_token(): void
    {
        CoreIntegrationCredential::factory()->create([
            'integration_id' => $this->integration->id,
            'encrypted_payload' => [
                'access_token' => 'old-access',
                'refresh_token' => 'refresh-secret-value',
            ],
            'expires_at' => now()->subMinute(),
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'new-access-secret',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ], 200),
        ]);

        $token = app(GoogleOAuthService::class)->validAccessToken($this->integration->fresh(['credential']));
        $this->assertSame('new-access-secret', $token);

        $credential = $this->integration->fresh(['credential'])->credential;
        $stored = DB::table('core_integration_credentials')->where('id', $credential->id)->value('encrypted_payload');
        $this->assertStringNotContainsString('new-access-secret', (string) $stored);
        $this->assertSame('new-access-secret', $credential->encrypted_payload['access_token']);
    }

    public function test_resource_refresh_normalizes_upserts_and_handles_partial_failure(): void
    {
        CoreIntegrationCredential::factory()->create([
            'integration_id' => $this->integration->id,
            'encrypted_payload' => [
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
            ],
            'expires_at' => now()->addHour(),
        ]);

        // Missing developer token => Ads setup_required; GBP scope off => scope_required.
        config([
            'moxdop.google.developer_token' => null,
            'moxdop.google.include_gbp_scope' => false,
            'moxdop.google.gbp_discovery_enabled' => false,
        ]);

        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites' => Http::response([
                'siteEntry' => [
                    ['siteUrl' => 'sc-domain:moximu.com', 'permissionLevel' => 'siteFullUser'],
                    ['siteUrl' => 'https://www.moximu.com/', 'permissionLevel' => 'siteOwner'],
                ],
            ], 200),
            'https://analyticsadmin.googleapis.com/v1beta/accountSummaries*' => Http::response([
                'accountSummaries' => [[
                    'account' => 'accounts/1',
                    'displayName' => 'Moximu',
                    'propertySummaries' => [[
                        'property' => 'properties/123456',
                        'displayName' => 'Moximu GA4',
                        'propertyType' => 'PROPERTY_TYPE_ORDINARY',
                    ]],
                ]],
            ], 200),
        ]);

        $result = app(GoogleResourceRefreshService::class)->refresh($this->integration->fresh(['credential']));

        $this->assertTrue($result['ok']);
        $this->assertSame('ok', $result['results']['search_console']['status']);
        $this->assertSame('ok', $result['results']['ga4']['status']);
        $this->assertSame('setup_required', $result['results']['google_ads']['status']);
        $this->assertSame('scope_required', $result['results']['google_business_profile']['status']);

        $this->assertDatabaseHas('core_external_resources', [
            'integration_id' => $this->integration->id,
            'resource_type' => 'search_console',
            'external_id' => 'sc-domain:moximu.com',
            'status' => 'available',
        ]);
        $this->assertDatabaseHas('core_external_resources', [
            'integration_id' => $this->integration->id,
            'resource_type' => 'ga4',
            'external_id' => 'properties/123456',
            'status' => 'available',
        ]);

        // Second refresh must not duplicate canonical resources.
        app(GoogleResourceRefreshService::class)->refresh($this->integration->fresh(['credential']));
        $this->assertSame(1, CoreExternalResource::query()->where([
            'integration_id' => $this->integration->id,
            'resource_type' => 'search_console',
            'external_id' => 'sc-domain:moximu.com',
        ])->count());
        $this->assertSame(1, CoreExternalResource::query()->where([
            'integration_id' => $this->integration->id,
            'resource_type' => 'ga4',
            'external_id' => 'properties/123456',
        ])->count());
        $this->assertSame(
            3,
            CoreExternalResource::query()->where('integration_id', $this->integration->id)->count(),
        );
    }

    public function test_stale_resources_marked_unavailable_without_deleting_bindings_identity(): void
    {
        CoreIntegrationCredential::factory()->create([
            'integration_id' => $this->integration->id,
            'encrypted_payload' => [
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
            ],
            'expires_at' => now()->addHour(),
        ]);

        $stale = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => ProviderRegistry::GOOGLE,
            'resource_type' => 'search_console',
            'external_id' => 'sc-domain:old.example',
            'display_name' => 'old.example',
            'status' => 'available',
        ]);

        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites' => Http::response([
                'siteEntry' => [
                    ['siteUrl' => 'sc-domain:moximu.com', 'permissionLevel' => 'siteOwner'],
                ],
            ], 200),
            'https://analyticsadmin.googleapis.com/v1beta/accountSummaries*' => Http::response(['accountSummaries' => []], 200),
            'https://googleads.googleapis.com/*/customers:listAccessibleCustomers' => Http::response(['resourceNames' => []], 200),
        ]);

        app(GoogleResourceRefreshService::class)->refresh($this->integration->fresh(['credential']));

        $this->assertSame('unavailable', $stale->fresh()->status);
        $this->assertDatabaseHas('core_external_resources', [
            'external_id' => 'sc-domain:old.example',
        ]);
        $this->assertDatabaseHas('core_external_resources', [
            'external_id' => 'sc-domain:moximu.com',
            'status' => 'available',
        ]);
    }

    public function test_google_ads_discovery_normalizes_customer_ids(): void
    {
        CoreIntegrationCredential::factory()->create([
            'integration_id' => $this->integration->id,
            'encrypted_payload' => [
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
            ],
            'expires_at' => now()->addHour(),
        ]);

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, 'webmasters/v3/sites')) {
                return Http::response(['siteEntry' => []], 200);
            }

            if (str_contains($url, 'analyticsadmin.googleapis.com')) {
                return Http::response(['accountSummaries' => []], 200);
            }

            if (str_contains($url, 'customers:listAccessibleCustomers')) {
                return Http::response([
                    'resourceNames' => ['customers/1234567890', 'customers/9988776655'],
                ], 200);
            }

            if (str_contains($url, 'googleAds:search')) {
                return Http::response(['results' => []], 200);
            }

            return Http::response(['error' => 'unexpected '.$url], 500);
        });

        $result = app(GoogleResourceRefreshService::class)->refresh($this->integration->fresh(['credential']));
        $this->assertSame('ok', $result['results']['google_ads']['status']);
        $this->assertDatabaseHas('core_external_resources', [
            'resource_type' => 'google_ads',
            'external_id' => '1234567890',
        ]);
    }

    public function test_binding_compatibility_and_no_asset_level_google_credential(): void
    {
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $website = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website']);
        $adsAsset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'google_ads']);

        $gsc = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => ProviderRegistry::GOOGLE,
            'resource_type' => 'search_console',
            'external_id' => 'sc-domain:moximu.com',
            'display_name' => 'moximu.com',
            'status' => 'available',
        ]);
        $ads = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => ProviderRegistry::GOOGLE,
            'resource_type' => 'google_ads',
            'external_id' => '1234567890',
            'display_name' => 'Ads 1234567890',
            'status' => 'available',
        ]);

        $this->assertTrue(AssetBindingCompatibility::isCompatible($website, $gsc));
        $this->assertFalse(AssetBindingCompatibility::isCompatible($website, $ads));
        $this->assertTrue(AssetBindingCompatibility::isCompatible($adsAsset, $ads));

        Livewire::test(AssetBindingsRelationManager::class, [
            'ownerRecord' => $website,
            'pageClass' => ViewDigitalAsset::class,
        ])
            ->callTableAction('create', data: [
                'external_resource_id' => $ads->id,
                'status' => CoreAssetBinding::STATUS_ACTIVE,
            ])
            ->assertHasTableActionErrors();

        Livewire::test(AssetBindingsRelationManager::class, [
            'ownerRecord' => $website,
            'pageClass' => ViewDigitalAsset::class,
        ])
            ->callTableAction('create', data: [
                'external_resource_id' => $gsc->id,
                'status' => CoreAssetBinding::STATUS_ACTIVE,
            ])
            ->assertHasNoTableActionErrors();

        $binding = CoreAssetBinding::query()->firstOrFail();
        $this->assertSame($website->id, $binding->digital_asset_id);
        $this->assertNull(data_get($binding->configuration, 'access_token'));
        $this->assertNull(data_get($binding->externalResource->metadata, 'refresh_token'));
    }

    public function test_team_member_cannot_authorize_google(): void
    {
        $team = User::factory()->create();
        $team->assignRole(Roles::TEAM_MEMBER);
        $this->actingAs($team);

        $this->get(route('integrations.google.authorize', $this->integration))->assertForbidden();
        $this->get(route('integrations.google.callback'))->assertForbidden();
        $this->assertFalse(IntegrationResource::canAccess());
    }

    public function test_disconnect_clears_credentials_and_preserves_resources_bindings(): void
    {
        CoreIntegrationCredential::factory()->provider()->create([
            'integration_id' => $this->integration->id,
            'encrypted_payload' => [
                'client_id' => 'keep-client-id',
                'client_secret' => 'keep-client-secret',
                'developer_token' => 'keep-dev-token',
            ],
        ]);
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $this->integration->id,
            'encrypted_payload' => [
                'access_token' => 'access-secret',
                'refresh_token' => 'refresh-secret',
            ],
        ]);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'status' => 'available',
        ]);
        $asset = DigitalAsset::factory()->create();
        CoreAssetBinding::factory()->create([
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $resource->id,
            'capability' => $resource->resource_type,
            'status' => 'active',
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/revoke' => Http::response([], 200),
        ]);

        $result = app(GoogleOAuthService::class)->disconnect($this->integration->fresh(['credential', 'providerCredential']));
        $this->assertTrue($result['ok']);
        $this->assertFalse($this->integration->fresh()->authorizationCredential()->exists());
        $this->assertTrue($this->integration->fresh()->providerCredential()->exists());
        $this->assertSame('keep-client-secret', $this->integration->fresh()->providerCredential->encrypted_payload['client_secret']);
        $this->assertSame('available', $resource->fresh()->status);
        $this->assertSame('active', CoreAssetBinding::query()->first()->status);
        $this->assertDatabaseHas('digital_assets', ['id' => $asset->id]);
        $this->assertSame(GoogleAuthStatus::REVOKED, GoogleAuthStatus::for($this->integration->fresh(['credential'])));
    }

    public function test_google_integration_view_shows_setup_and_actions_without_secrets(): void
    {
        Livewire::test(ViewIntegration::class, ['record' => $this->integration->getRouteKey()])
            ->assertOk()
            ->assertSee('Application configuration')
            ->assertSee('Authorization')
            ->assertSee('Authorize Google')
            ->assertSee('Configure')
            ->assertSee('Test connection')
            ->assertSee('Refresh resources')
            ->assertSee('Configured by environment')
            ->assertDontSee('test-client-secret')
            ->assertDontSee('refresh-secret');
    }
}
