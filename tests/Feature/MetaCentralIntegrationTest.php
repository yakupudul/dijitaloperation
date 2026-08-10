<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Integrations\Pages\ViewIntegration;
use App\Filament\App\Resources\Integrations\RelationManagers\ExternalResourcesRelationManager;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Integrations\Meta\MetaApiClient;
use App\Services\Integrations\Meta\MetaConnectionService;
use App\Services\Integrations\Meta\MetaCredentialResolver;
use App\Services\Integrations\Meta\MetaException;
use App\Services\Integrations\Meta\MetaProviderCredentialService;
use App\Services\Integrations\Meta\MetaResourceDiscoveryService;
use App\Support\Integrations\AssetBindingCompatibility;
use App\Support\Integrations\Meta\MetaApiConfig;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

class MetaCentralIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CoreIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        config([
            'moxdop.meta.access_token' => null,
            'moxdop.meta.api_version' => 'v26.0',
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel('app');

        $this->integration = CoreIntegration::factory()->meta()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
        ]);
    }

    public function test_api_version_is_centralized(): void
    {
        $this->assertSame('v26.0', MetaApiConfig::apiVersion());
        $this->assertSame('https://graph.facebook.com/v26.0', MetaApiConfig::graphBaseUrl());
        $this->assertSame(['ads_read', 'business_management'], MetaApiConfig::requiredReadPermissions());
    }

    public function test_access_token_encrypted_at_rest_and_hidden_from_array(): void
    {
        app(MetaProviderCredentialService::class)->save($this->integration, [
            'access_token' => 'EAAG-test-secret-token',
        ], $this->admin);

        $credential = CoreIntegrationCredential::query()
            ->where('integration_id', $this->integration->id)
            ->where('credential_type', CoreIntegrationCredential::TYPE_PROVIDER)
            ->firstOrFail();

        $stored = DB::table('core_integration_credentials')->where('id', $credential->id)->value('encrypted_payload');
        $this->assertIsString($stored);
        $this->assertStringNotContainsString('EAAG-test-secret-token', $stored);
        $this->assertSame('EAAG-test-secret-token', $credential->encrypted_payload['access_token']);
        $this->assertArrayNotHasKey('encrypted_payload', $credential->toArray());
    }

    public function test_blank_edit_preserves_and_clear_removes(): void
    {
        $service = app(MetaProviderCredentialService::class);
        $service->save($this->integration, [
            'access_token' => 'EAAG-original',
        ], $this->admin);

        $service->save($this->integration->fresh(['providerCredential']), [
            'access_token' => '',
        ], $this->admin);

        $this->assertSame(
            'EAAG-original',
            app(MetaCredentialResolver::class)->accessToken($this->integration->fresh(['providerCredential'])),
        );

        $service->save($this->integration->fresh(['providerCredential']), [
            'access_token' => '',
            'clear_access_token' => true,
        ], $this->admin);

        $this->assertFalse(
            app(MetaCredentialResolver::class)->hasDatabaseAccessToken($this->integration->fresh(['providerCredential'])),
        );
    }

    public function test_resolver_prefers_database_over_environment(): void
    {
        config(['moxdop.meta.access_token' => 'EAAG-env']);

        app(MetaProviderCredentialService::class)->save($this->integration, [
            'access_token' => 'EAAG-db',
        ], $this->admin);

        $resolver = app(MetaCredentialResolver::class);
        $fresh = $this->integration->fresh(['providerCredential']);

        $this->assertSame('EAAG-db', $resolver->accessToken($fresh));
        $this->assertSame(MetaCredentialResolver::SOURCE_DATABASE, $resolver->accessTokenSource($fresh));
    }

    /**
     * @return array<string, array{status: int, body: array<string, mixed>, ok: bool, needle: string}>
     */
    public static function healthCheckCases(): array
    {
        return [
            'valid' => ['status' => 200, 'body' => ['id' => '123', 'name' => 'Agency User'], 'ok' => true, 'needle' => 'succeeded'],
            'invalid_credential' => ['status' => 401, 'body' => ['error' => ['message' => 'bad', 'code' => 190]], 'ok' => false, 'needle' => 'Authentication'],
            'permission_missing' => ['status' => 403, 'body' => ['error' => ['message' => 'perm', 'code' => 10]], 'ok' => false, 'needle' => 'Permission'],
            'rate_limited' => ['status' => 429, 'body' => ['error' => ['message' => 'limit', 'code' => 4]], 'ok' => false, 'needle' => 'Rate limited'],
            'provider_unavailable' => ['status' => 503, 'body' => ['error' => ['message' => 'down']], 'ok' => false, 'needle' => 'unavailable'],
            'malformed_error_payload' => ['status' => 200, 'body' => ['error' => ['message' => 'weird', 'code' => 1]], 'ok' => false, 'needle' => 'Unknown'],
            'malformed_missing_id' => ['status' => 200, 'body' => ['name' => 'No Id'], 'ok' => false, 'needle' => 'Unknown'],
        ];
    }

    #[DataProvider('healthCheckCases')]
    public function test_health_check_matrix_and_non_mutating(int $status, array $body, bool $ok, string $needle): void
    {
        app(MetaProviderCredentialService::class)->save($this->integration, [
            'access_token' => 'EAAG-health',
        ], $this->admin);

        Http::fake([
            'https://graph.facebook.com/*' => Http::response($body, $status),
        ]);

        $result = app(MetaConnectionService::class)->testConnection(
            $this->integration->fresh(['providerCredential']),
        );

        $this->assertSame($ok, $result['ok'], json_encode(compact('status', 'body', 'ok', 'needle', 'result')));
        $this->assertStringContainsString($needle, $result['message']);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/me')
            && ! str_contains(strtolower($request->url()), 'access_token='));
    }

    public function test_token_absent_from_exceptions_logs_and_config_fingerprint(): void
    {
        app(MetaProviderCredentialService::class)->save($this->integration, [
            'access_token' => 'EAAG-should-not-leak',
        ], $this->admin);

        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['error' => ['message' => 'nope', 'code' => 190]], 401),
        ]);

        Log::spy();

        $result = app(MetaConnectionService::class)->testConnection(
            $this->integration->fresh(['providerCredential']),
        );

        $this->assertFalse($result['ok']);
        $this->assertStringNotContainsString('EAAG-should-not-leak', $result['message']);
        $this->assertStringNotContainsString('EAAG-should-not-leak', (string) $this->integration->fresh()->last_error);
        $encoded = json_encode($this->integration->fresh()->config);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('EAAG-should-not-leak', $encoded);

        Log::shouldNotHaveReceived('error');
    }

    public function test_resource_discovery_dedupes_pagination_and_updates_metadata(): void
    {
        app(MetaProviderCredentialService::class)->save($this->integration, [
            'access_token' => 'EAAG-discover',
        ], $this->admin);

        Http::fake([
            'https://graph.facebook.com/*/me/adaccounts*' => Http::sequence()
                ->push([
                    'data' => [[
                        'account_id' => '111',
                        'id' => 'act_111',
                        'name' => 'Account One',
                        'currency' => 'USD',
                        'timezone_name' => 'America/Los_Angeles',
                        'account_status' => 1,
                    ]],
                    'paging' => [
                        'next' => 'https://graph.facebook.com/v26.0/me/adaccounts?after=cursor1&access_token=EAAG-discover',
                    ],
                ], 200)
                ->push([
                    'data' => [[
                        'account_id' => '222',
                        'id' => 'act_222',
                        'name' => 'Account Two',
                        'currency' => 'EUR',
                        'timezone_name' => 'Europe/Istanbul',
                        'account_status' => 1,
                    ]],
                ], 200),
            'https://graph.facebook.com/*/me/businesses*' => Http::response([
                'data' => [[
                    'id' => 'biz_1',
                    'name' => 'Agency Biz',
                ]],
            ], 200),
            'https://graph.facebook.com/*/biz_1/owned_ad_accounts*' => Http::response([
                'data' => [[
                    'account_id' => '111',
                    'id' => 'act_111',
                    'name' => 'Account One Updated',
                    'currency' => 'USD',
                    'timezone_name' => 'America/Los_Angeles',
                    'account_status' => 1,
                    'business' => ['id' => 'biz_1', 'name' => 'Agency Biz'],
                ]],
            ], 200),
            'https://graph.facebook.com/*/biz_1/client_ad_accounts*' => Http::response([
                'data' => [[
                    'account_id' => '333',
                    'id' => 'act_333',
                    'name' => 'Client Account',
                    'currency' => 'TRY',
                    'timezone_name' => 'Europe/Istanbul',
                    'account_status' => 1,
                ]],
            ], 200),
        ]);

        $result = app(MetaResourceDiscoveryService::class)->discover(
            $this->integration->fresh(['providerCredential']),
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(3, $result['count']);

        $this->assertSame(3, CoreExternalResource::query()
            ->where('integration_id', $this->integration->id)
            ->where('resource_type', 'meta_ads')
            ->count());

        $one = CoreExternalResource::query()
            ->where('external_id', 'act_111')
            ->firstOrFail();
        $this->assertSame('Account One Updated', $one->display_name);
        $this->assertSame('biz_1', data_get($one->metadata, 'business_id'));
        $this->assertContains('direct', (array) data_get($one->metadata, 'discovery_paths'));
        $this->assertContains('owned', (array) data_get($one->metadata, 'discovery_paths'));
        $encoded = json_encode($one->metadata);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('EAAG-discover', $encoded);

        Http::fake([
            'https://graph.facebook.com/*/me/adaccounts*' => Http::response([
                'data' => [
                    ['account_id' => '111', 'id' => 'act_111', 'name' => 'Account One Updated', 'currency' => 'USD', 'timezone_name' => 'America/Los_Angeles', 'account_status' => 1],
                    ['account_id' => '222', 'id' => 'act_222', 'name' => 'Account Two', 'currency' => 'EUR', 'timezone_name' => 'Europe/Istanbul', 'account_status' => 1],
                    ['account_id' => '333', 'id' => 'act_333', 'name' => 'Client Account', 'currency' => 'TRY', 'timezone_name' => 'Europe/Istanbul', 'account_status' => 1],
                ],
            ], 200),
            'https://graph.facebook.com/*/me/businesses*' => Http::response(['data' => []], 200),
        ]);

        // Rediscovery must not duplicate.
        app(MetaResourceDiscoveryService::class)->discover(
            $this->integration->fresh(['providerCredential']),
        );
        $this->assertSame(3, CoreExternalResource::query()
            ->where('integration_id', $this->integration->id)
            ->where('resource_type', 'meta_ads')
            ->count());

        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && ! str_contains(strtolower($request->url()), 'access_token=EAAG'));
    }

    public function test_complete_discovery_failure_preserves_existing_binding(): void
    {
        app(MetaProviderCredentialService::class)->save($this->integration, [
            'access_token' => 'EAAG-fail',
        ], $this->admin);

        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => 'meta_ads',
            'external_id' => 'act_999',
            'display_name' => 'Existing Account',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => ['provider_resource_type' => 'meta_ad_account'],
        ]);

        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'meta_ads',
        ]);
        $binding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $resource->id,
            'capability' => 'meta_ads',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);

        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['error' => ['message' => 'down']], 503),
        ]);

        $result = app(MetaResourceDiscoveryService::class)->discover(
            $this->integration->fresh(['providerCredential']),
        );

        $this->assertFalse($result['ok']);
        $this->assertSame(CoreExternalResource::STATUS_AVAILABLE, $resource->fresh()->status);
        $this->assertDatabaseHas('core_asset_bindings', [
            'id' => $binding->id,
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $resource->id,
        ]);
    }

    public function test_meta_ads_binding_compatibility_and_cross_brand_safety(): void
    {
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => 'meta_ads',
            'external_id' => 'act_555',
            'display_name' => 'Bound Account',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);

        $googleResource = CoreExternalResource::factory()->create([
            'integration_id' => CoreIntegration::factory()->google()->create()->id,
            'provider' => ProviderRegistry::GOOGLE,
            'resource_type' => 'google_ads',
            'external_id' => 'customers/1',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);

        $customer = Customer::factory()->create();
        $brandA = Brand::factory()->create(['customer_id' => $customer->id]);
        $brandB = Brand::factory()->create(['customer_id' => $customer->id]);
        $assetA = DigitalAsset::factory()->create(['brand_id' => $brandA->id, 'type' => 'meta_ads']);
        $assetB = DigitalAsset::factory()->create(['brand_id' => $brandB->id, 'type' => 'meta_ads']);
        $website = DigitalAsset::factory()->create(['brand_id' => $brandA->id, 'type' => 'website']);

        $this->assertTrue(AssetBindingCompatibility::isCompatible($assetA, $resource));
        $this->assertFalse(AssetBindingCompatibility::isCompatible($assetA, $googleResource));
        $this->assertFalse(AssetBindingCompatibility::isCompatible($website, $resource));
        $this->assertSame(['meta_ads'], AssetBindingCompatibility::capabilitiesForAssetType('meta_ads'));

        $binding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $assetA->id,
            'external_resource_id' => $resource->id,
            'capability' => 'meta_ads',
        ]);

        $this->assertSame($assetA->id, $binding->fresh()->digital_asset_id);
        $this->assertNotSame($assetB->id, $binding->digital_asset_id);
        $this->assertTrue(
            CoreAssetBinding::query()
                ->where('digital_asset_id', $assetA->id)
                ->where('external_resource_id', $resource->id)
                ->exists(),
        );
        $this->assertFalse(
            CoreAssetBinding::query()
                ->where('digital_asset_id', $assetB->id)
                ->where('external_resource_id', $resource->id)
                ->exists(),
        );
    }

    public function test_meta_api_client_exposes_no_mutation_methods(): void
    {
        $methods = collect((new ReflectionClass(MetaApiClient::class))->getMethods())
            ->filter(fn ($method) => $method->class === MetaApiClient::class)
            ->map(fn ($method) => $method->getName())
            ->all();

        foreach (['post', 'put', 'patch', 'delete', 'mutate', 'write'] as $forbidden) {
            $this->assertFalse(
                collect($methods)->contains(fn (string $name): bool => str_contains(strtolower($name), $forbidden)),
                'Forbidden mutation surface: '.$forbidden,
            );
        }

        $this->assertContains('get', $methods);
        $this->assertContains('getAbsolute', $methods);
    }

    public function test_view_integration_shows_masked_token_and_meta_actions(): void
    {
        app(MetaProviderCredentialService::class)->save($this->integration, [
            'access_token' => 'EAAG-ui-secret',
        ], $this->admin);

        Livewire::test(ViewIntegration::class, [
            'record' => $this->integration->id,
        ])
            ->assertOk()
            ->assertSee('Stored securely ✓')
            ->assertSee('Discover resources')
            ->assertSee('Test connection')
            ->assertSee('ads_read')
            ->assertDontSee('EAAG-ui-secret')
            ->assertDontSee('Credentials JSON');

        $this->assertTrue(
            ExternalResourcesRelationManager::canViewForRecord(
                $this->integration,
                ViewIntegration::class,
            ),
        );
    }

    public function test_rejects_pagination_url_outside_graph_host(): void
    {
        app(MetaProviderCredentialService::class)->save($this->integration, [
            'access_token' => 'EAAG-ssrf',
        ], $this->admin);

        $this->expectException(MetaException::class);

        app(MetaApiClient::class)->getAbsolute(
            $this->integration->fresh(['providerCredential']),
            'https://evil.example/v26.0/me/adaccounts',
        );
    }
}
