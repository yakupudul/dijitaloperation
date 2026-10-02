<?php

namespace Tests\Feature;

use App\Livewire\Demo\Integrations\MetaIntegrationPage;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\CoreIntegrationDiscoveryContext;
use App\Models\DigitalAsset;
use App\Models\MetaOAuthAuthorizationAttempt;
use App\Models\User;
use App\Services\Integrations\Meta\DiscoverMetaResourcesService;
use App\Services\Integrations\Meta\MetaCredentialValidator;
use App\Services\Integrations\Meta\MetaIntegrationReadModel;
use App\Services\Integrations\Meta\MetaOAuthService;
use App\Services\Integrations\Meta\SelectMetaDiscoveryContextService;
use App\Support\Integrations\Meta\MetaAdAccountId;
use App\Support\Integrations\Meta\MetaApiConfig;
use App\Support\Integrations\Meta\MetaAuthStatus;
use App\Support\Integrations\Meta\MetaPermissionRegistry;
use App\Support\Integrations\Meta\MetaResourceType;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class MetaAuthorizationDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);

        config([
            'moxdop.meta.app_id' => '111222333',
            'moxdop.meta.app_secret' => 'test-meta-app-secret',
            'moxdop.meta.login_configuration_id' => 'cfg_login_for_business',
            'moxdop.meta.access_token' => null,
            'moxdop.meta.use_appsecret_proof' => false,
            'app.url' => 'https://moxdop.test',
        ]);

        // Do not call Http::fake() here — empty fakes swallow unmatched Graph URLs as {}.
    }

    public function test_config_health_and_permission_registry_are_read_only(): void
    {
        $this->assertTrue(MetaApiConfig::isApplicationConfigured());
        $this->assertSame('v26.0', MetaApiConfig::apiVersion());
        $this->assertSame(['ads_read', 'business_management'], MetaPermissionRegistry::requiredForMetaAds());
        $this->assertNotContains('ads_management', MetaPermissionRegistry::requiredForMetaAds());
        foreach (MetaPermissionRegistry::forbiddenWriteOrUnrelated() as $forbidden) {
            $this->assertNotContains($forbidden, MetaPermissionRegistry::requiredForMetaAds());
        }
    }

    public function test_authorization_url_uses_login_for_business_config_id_and_secure_state(): void
    {
        $integration = CoreIntegration::factory()->meta()->create();
        $result = app(MetaOAuthService::class)->beginAuthorization($integration, $this->admin);

        $this->assertArrayHasKey('url', $result);
        $this->assertStringContainsString('config_id=cfg_login_for_business', $result['url']);
        $this->assertStringContainsString('response_type=code', $result['url']);
        $this->assertStringNotContainsString('ads_management', $result['url']);
        $this->assertStringNotContainsString('refresh_token', $result['url']);
        $this->assertSame(1, MetaOAuthAuthorizationAttempt::query()->count());
        $attempt = MetaOAuthAuthorizationAttempt::query()->first();
        $this->assertNotSame($integration->id, $attempt->state_hash);
        $this->assertTrue($attempt->isPending());
    }

    public function test_wrong_expired_and_replayed_state_rejected(): void
    {
        $integration = CoreIntegration::factory()->meta()->create();
        $oauth = app(MetaOAuthService::class);
        $begin = $oauth->beginAuthorization($integration, $this->admin);
        $this->assertArrayHasKey('url', $begin);

        $wrong = $oauth->handleCallback('code', 'wrong-state', null, $this->admin);
        $this->assertArrayHasKey('error', $wrong);
        $this->assertSame(0, CoreIntegrationCredential::query()->count());

        $attempt = MetaOAuthAuthorizationAttempt::query()->first();
        $attempt->forceFill(['expires_at' => now()->subMinute()])->save();
        parse_str(parse_url($begin['url'], PHP_URL_QUERY), $query);
        $expired = $oauth->handleCallback('code', $query['state'], null, $this->admin);
        $this->assertArrayHasKey('error', $expired);

        $attempt2 = MetaOAuthAuthorizationAttempt::query()->create([
            'integration_id' => $integration->id,
            'requested_by_user_id' => $this->admin->id,
            'state_hash' => MetaOAuthAuthorizationAttempt::hashState('replay-state'),
            'requested_permissions' => MetaPermissionRegistry::requiredForMetaAds(),
            'return_route' => 'operator.integrations.meta',
            'status' => MetaOAuthAuthorizationAttempt::STATUS_CONSUMED,
            'expires_at' => now()->addMinutes(10),
            'consumed_at' => now(),
        ]);
        $replay = $oauth->handleCallback('code', 'replay-state', null, $this->admin);
        $this->assertArrayHasKey('error', $replay);
        $this->assertNotNull($attempt2->fresh());
    }

    public function test_provider_denial_preserves_existing_credential(): void
    {
        $integration = CoreIntegration::factory()->meta()->create();
        CoreIntegrationCredential::factory()->create([
            'integration_id' => $integration->id,
            'credential_type' => CoreIntegrationCredential::TYPE_PROVIDER,
            'encrypted_payload' => ['access_token' => 'EAAG-existing'],
        ]);

        $oauth = app(MetaOAuthService::class);
        $begin = $oauth->beginAuthorization($integration, $this->admin);
        parse_str(parse_url($begin['url'], PHP_URL_QUERY), $query);

        $result = $oauth->handleCallback(null, $query['state'], 'access_denied', $this->admin);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame(
            'EAAG-existing',
            $integration->fresh(['providerCredential'])->providerCredential->encrypted_payload['access_token'],
        );
    }

    public function test_callback_stores_encrypted_token_without_google_refresh_token(): void
    {
        Http::fake([
            '*oauth/access_token*' => Http::sequence()
                ->push(['access_token' => 'EAAG-short', 'token_type' => 'bearer', 'expires_in' => 3600])
                ->push(['access_token' => 'EAAG-long', 'token_type' => 'bearer', 'expires_in' => 5184000]),
            '*debug_token*' => Http::response([
                'data' => [
                    'app_id' => '111222333',
                    'is_valid' => true,
                    'user_id' => 'user-9',
                    'scopes' => ['ads_read', 'business_management'],
                    'expires_at' => now()->addDays(60)->timestamp,
                    'data_access_expires_at' => now()->addDays(90)->timestamp,
                ],
            ]),
        ]);

        $integration = CoreIntegration::factory()->meta()->create();
        $oauth = app(MetaOAuthService::class);
        $begin = $oauth->beginAuthorization($integration, $this->admin);
        parse_str(parse_url($begin['url'], PHP_URL_QUERY), $query);

        $result = $oauth->handleCallback('auth-code', $query['state'], null, $this->admin);
        $this->assertArrayHasKey('integration', $result, json_encode($result));

        $credential = $integration->fresh(['providerCredential'])->providerCredential;
        $payload = $credential->encrypted_payload;
        $this->assertSame('EAAG-long', $payload['access_token']);
        $this->assertArrayNotHasKey('refresh_token', $payload);
        $this->assertArrayNotHasKey('app_secret', $payload);
        $this->assertArrayNotHasKey('encrypted_payload', $credential->toArray());
        $this->assertSame(MetaOAuthService::TOKEN_TYPE_LONG_LIVED_USER, $payload['token_type']);
        $this->assertSame(MetaAuthStatus::CONNECTED, MetaAuthStatus::for($integration->fresh()));

        $detail = app(MetaIntegrationReadModel::class)->detail();
        $this->assertStringNotContainsString('EAAG-long', (string) json_encode($detail));
        $this->assertStringNotContainsString('test-meta-app-secret', (string) json_encode($detail));
        $this->assertNull($detail['secrets']);
    }

    public function test_token_validation_wrong_app_and_transient_failure(): void
    {
        $integration = CoreIntegration::factory()->meta()->create([
            'config' => ['auth_status' => 'connected', 'connection_status' => 'connected'],
        ]);
        CoreIntegrationCredential::factory()->create([
            'integration_id' => $integration->id,
            'credential_type' => CoreIntegrationCredential::TYPE_PROVIDER,
            'encrypted_payload' => ['access_token' => 'EAAG-token'],
        ]);

        $mode = 'wrong_app';
        Http::fake(function () use (&$mode) {
            if ($mode === 'transient') {
                return Http::response(['error' => 'down'], 503);
            }

            return Http::response([
                'data' => [
                    'app_id' => 'other-app',
                    'is_valid' => true,
                    'scopes' => ['ads_read'],
                ],
            ]);
        });

        $validator = app(MetaCredentialValidator::class);
        $result = $validator->validate($integration->fresh(['providerCredential']));
        $this->assertSame(MetaCredentialValidator::STATUS_WRONG_APP, $result['status']);
        $validator->persist($integration->fresh(['providerCredential']), $result);
        $this->assertSame(MetaAuthStatus::REAUTH_REQUIRED, MetaAuthStatus::for($integration->fresh()));
        $this->assertSame(0, CoreExternalResource::query()->count()); // inventory untouched

        $mode = 'transient';
        $integration->forceFill([
            'config' => [
                'auth_status' => 'connected',
                'connection_status' => 'connected',
                'credential_status' => MetaCredentialValidator::STATUS_VALID,
                'granted_permissions' => ['ads_read', 'business_management'],
            ],
        ])->save();
        $transient = $validator->validate($integration->fresh(['providerCredential']));
        $this->assertSame(MetaCredentialValidator::STATUS_TRANSIENT_FAILURE, $transient['status']);
        $validator->persist($integration->fresh(['providerCredential']), $transient);
        $this->assertSame(
            MetaCredentialValidator::STATUS_VALID,
            data_get($integration->fresh()->config, 'credential_status'),
        );
    }

    public function test_business_discovery_pagination_idempotency_and_no_auto_asset_binding(): void
    {
        $integration = $this->authorizedIntegration();

        $page = 0;
        Http::fake(function ($request) use (&$page) {
            if (! str_contains($request->url(), 'me/businesses')) {
                return Http::response(['error' => ['message' => 'unexpected']], 500);
            }
            $page++;
            if ($page === 1) {
                return Http::response([
                    'data' => [['id' => 'biz_1', 'name' => 'Alpha Business']],
                    'paging' => ['next' => 'https://graph.facebook.com/v26.0/me/businesses?after=c1'],
                ]);
            }
            if ($page === 2) {
                return Http::response([
                    'data' => [['id' => 'biz_2', 'name' => 'Beta Business']],
                ]);
            }

            // Second discovery run (idempotent rename).
            return Http::response([
                'data' => [
                    ['id' => 'biz_1', 'name' => 'Alpha Business Renamed'],
                    ['id' => 'biz_2', 'name' => 'Beta Business'],
                ],
            ]);
        });

        $first = app(DiscoverMetaResourcesService::class)->discoverBusinesses($integration, $this->admin);
        if (! ($first['ok'] ?? false)) {
            $this->fail('Business discovery failed: '.json_encode($first));
        }
        $this->assertSame(2, $first['count']);

        $second = app(DiscoverMetaResourcesService::class)->discoverBusinesses(
            $integration->fresh(['providerCredential']),
            $this->admin,
        );
        if (! ($second['ok'] ?? false)) {
            $this->fail('Second business discovery failed: '.json_encode($second));
        }
        $this->assertSame(2, CoreExternalResource::query()->where('resource_type', MetaResourceType::META_BUSINESS)->count());
        $this->assertSame(
            'Alpha Business Renamed',
            CoreExternalResource::query()->where('external_id', 'biz_1')->value('display_name'),
        );
        $this->assertSame(0, DigitalAsset::query()->count());
        $this->assertSame(0, CoreAssetBinding::query()->count());
    }

    public function test_business_selection_is_not_binding_and_rejects_foreign_resource(): void
    {
        $integration = $this->authorizedIntegration();
        $business = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => MetaResourceType::META_BUSINESS,
            'external_id' => 'biz_sel',
        ]);

        $selection = app(SelectMetaDiscoveryContextService::class);
        $selection->select($integration, (string) $business->id, $this->admin);
        $this->assertTrue($selection->hasSelection($integration));
        $this->assertSame(0, CoreAssetBinding::query()->count());

        $other = CoreIntegration::factory()->create(['provider' => 'openai']);
        $foreign = CoreExternalResource::factory()->create([
            'integration_id' => $other->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => MetaResourceType::META_BUSINESS,
            'external_id' => 'biz_foreign',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $selection->select($integration, (string) $foreign->id, $this->admin);
    }

    public function test_ad_account_owned_client_dedupe_act_normalization_and_partial_edge(): void
    {
        $integration = $this->authorizedIntegration();
        $business = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => MetaResourceType::META_BUSINESS,
            'external_id' => 'biz_a',
            'display_name' => 'Agency BM',
        ]);
        app(SelectMetaDiscoveryContextService::class)->select($integration, (string) $business->id, $this->admin);

        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'owned_ad_accounts')) {
                return Http::response([
                    'data' => [[
                        'account_id' => '123456789',
                        'id' => 'act_123456789',
                        'name' => 'Owned Account',
                        'account_status' => 1,
                        'currency' => 'USD',
                        'timezone_name' => 'America/Los_Angeles',
                    ]],
                ]);
            }
            if (str_contains($url, 'client_ad_accounts')) {
                return Http::response(['error' => ['message' => 'rate limit', 'code' => 17]], 403);
            }

            return Http::response(['data' => []]);
        });

        $result = app(DiscoverMetaResourcesService::class)->discoverAdAccounts($integration->fresh(), $this->admin);
        $this->assertSame('partial', $result['status'], $result['message'] ?? '');
        $this->assertTrue($result['ok']); // owned edge succeeded; client edge failed
        $this->assertSame(1, CoreExternalResource::query()->where('resource_type', MetaResourceType::META_AD_ACCOUNT)->count());
        $account = CoreExternalResource::query()->where('resource_type', MetaResourceType::META_AD_ACCOUNT)->first();
        $this->assertSame('act_123456789', $account->external_id);
        $this->assertTrue(MetaAdAccountId::equals('123456789', $account->external_id));
        $this->assertSame('USD', $account->metadata['currency']);
        $this->assertSame('America/Los_Angeles', $account->metadata['timezone_name']);
        $this->assertSame(0, DigitalAsset::query()->count());
        $this->assertSame(0, CoreAssetBinding::query()->count());
    }

    public function test_same_account_via_owned_and_client_is_one_resource(): void
    {
        $integration = $this->authorizedIntegration();
        $business = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => MetaResourceType::META_BUSINESS,
            'external_id' => 'biz_b',
        ]);
        app(SelectMetaDiscoveryContextService::class)->select($integration, (string) $business->id, $this->admin);

        Http::fake(function ($request) {
            $url = $request->url();
            $row = [
                'account_id' => '555',
                'id' => 'act_555',
                'name' => 'Shared Account',
                'currency' => 'EUR',
                'timezone_name' => 'Europe/Berlin',
                'account_status' => 1,
            ];
            if (str_contains($url, 'owned_ad_accounts') || str_contains($url, 'client_ad_accounts')) {
                return Http::response(['data' => [$row]]);
            }

            return Http::response(['data' => []]);
        });

        $result = app(DiscoverMetaResourcesService::class)->discoverAdAccounts($integration->fresh(), $this->admin);
        $this->assertTrue($result['ok']);
        $this->assertSame(1, CoreExternalResource::query()->where('resource_type', MetaResourceType::META_AD_ACCOUNT)->count());
    }

    public function test_failed_business_refresh_preserves_inventory(): void
    {
        $integration = $this->authorizedIntegration();
        CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => MetaResourceType::META_BUSINESS,
            'external_id' => 'biz_keep',
        ]);

        Http::fake([
            '*me/businesses*' => Http::response(['error' => ['message' => 'down']], 500),
        ]);

        $result = app(DiscoverMetaResourcesService::class)->discoverBusinesses($integration->fresh(), $this->admin);
        $this->assertFalse($result['ok'], $result['message'] ?? '');
        $this->assertSame(1, CoreExternalResource::query()->where('external_id', 'biz_keep')->count());
    }

    public function test_page_render_makes_zero_meta_http_calls(): void
    {
        $this->authorizedIntegration();

        Http::fake();
        // Authorized integration already exists — Connect Meta may be Reauthorize.
        $this->get(route('operator.integrations'))->assertOk();
        $this->get(route('operator.integrations.meta'))
            ->assertOk()
            ->assertSee('Meta')
            ->assertDontSee('EAAG-authorized');
        Livewire::test(MetaIntegrationPage::class)->assertOk();
        app(MetaIntegrationReadModel::class)->detail();
        Http::assertNothingSent();
    }

    public function test_read_model_next_actions_separate_authorization_from_inventory(): void
    {
        config(['moxdop.meta.app_id' => null, 'moxdop.meta.app_secret' => null]);
        $detail = app(MetaIntegrationReadModel::class)->detail();
        $this->assertSame('configure', $detail['next_action']);

        config(['moxdop.meta.app_id' => '111', 'moxdop.meta.app_secret' => 'sec']);
        $detail = app(MetaIntegrationReadModel::class)->detail();
        $this->assertSame('authorize', $detail['next_action']);
        $this->assertTrue($detail['actions']['authorize']);

        $integration = $this->authorizedIntegration();
        $detail = app(MetaIntegrationReadModel::class)->detail();
        $this->assertSame('discover_businesses', $detail['next_action']);
        $this->assertSame(0, $detail['businesses_discovered']);
        $this->assertSame(0, $detail['ad_accounts_discovered']);
    }

    public function test_reauth_preserves_businesses_and_ad_accounts(): void
    {
        $integration = $this->authorizedIntegration();
        CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => MetaResourceType::META_BUSINESS,
            'external_id' => 'biz_keep',
        ]);
        CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => MetaResourceType::META_AD_ACCOUNT,
            'external_id' => 'act_keep',
        ]);

        Http::fake([
            '*oauth/access_token*' => Http::response([
                'access_token' => 'EAAG-new',
                'token_type' => 'bearer',
                'expires_in' => 5184000,
            ]),
            '*debug_token*' => Http::response([
                'data' => [
                    'app_id' => '111222333',
                    'is_valid' => true,
                    'scopes' => ['ads_read', 'business_management'],
                    'expires_at' => now()->addDays(60)->timestamp,
                ],
            ]),
        ]);

        $oauth = app(MetaOAuthService::class);
        $begin = $oauth->beginAuthorization($integration, $this->admin);
        parse_str(parse_url($begin['url'], PHP_URL_QUERY), $query);
        $oauth->handleCallback('new-code', $query['state'], null, $this->admin);

        $this->assertSame(1, CoreExternalResource::query()->where('external_id', 'biz_keep')->count());
        $this->assertSame(1, CoreExternalResource::query()->where('external_id', 'act_keep')->count());
        $this->assertSame(
            'EAAG-new',
            $integration->fresh(['providerCredential'])->providerCredential->encrypted_payload['access_token'],
        );
    }

    public function test_deselect_business_preserves_ad_account_inventory(): void
    {
        $integration = $this->authorizedIntegration();
        $business = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => MetaResourceType::META_BUSINESS,
            'external_id' => 'biz_d',
        ]);
        CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => MetaResourceType::META_AD_ACCOUNT,
            'external_id' => 'act_d',
            'parent_external_id' => 'biz_d',
        ]);

        $selection = app(SelectMetaDiscoveryContextService::class);
        $selection->select($integration, (string) $business->id, $this->admin);
        $selection->deselect($integration, (string) $business->id, $this->admin);

        $this->assertFalse($selection->hasSelection($integration));
        $this->assertSame(1, CoreExternalResource::query()->where('external_id', 'act_d')->count());
        $this->assertSame(0, CoreIntegrationDiscoveryContext::query()->where('status', 'active')->count());
    }

    public function test_graph_version_centralized_in_dialog_and_api(): void
    {
        $this->assertStringContainsString('/v26.0/', MetaApiConfig::dialogBaseUrl());
        $this->assertStringContainsString('/v26.0', MetaApiConfig::graphBaseUrl());
        config(['moxdop.meta.api_version' => 'bad']);
        $this->assertSame('v26.0', MetaApiConfig::apiVersion());
    }

    private function authorizedIntegration(): CoreIntegration
    {
        $integration = CoreIntegration::factory()->meta()->create([
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
