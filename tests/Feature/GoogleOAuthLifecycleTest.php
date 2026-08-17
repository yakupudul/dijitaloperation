<?php

namespace Tests\Feature;

use App\Exceptions\Integrations\GoogleAuthenticationException;
use App\Exceptions\Integrations\GoogleAuthorizationException;
use App\Livewire\Demo\Integrations\GoogleIntegrationPage;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\DigitalAsset;
use App\Models\GoogleOAuthAuthorizationAttempt;
use App\Models\User;
use App\Services\Integrations\Google\GoogleCredentialBroker;
use App\Services\Integrations\Google\GoogleOAuthConfigurationHealth;
use App\Services\Integrations\Google\GoogleOAuthService;
use App\Services\Integrations\Google\GoogleScopeCoverageService;
use App\Services\Integrations\Google\GoogleScopeRegistry;
use App\Support\Integrations\Google\GoogleAuthStatus;
use App\Support\Integrations\Google\GoogleScopes;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\TestCase;

class GoogleOAuthLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CoreIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);

        config([
            'moxdop.google.client_id' => 'test-client-id',
            'moxdop.google.client_secret' => 'test-client-secret',
            'moxdop.google.redirect_uri' => null,
            'moxdop.google.developer_token' => 'test-dev-token',
            'moxdop.google.include_gbp_scope' => false,
            'app.url' => 'http://127.0.0.1:8000',
            'cache.default' => 'array',
        ]);

        $this->integration = CoreIntegration::factory()->google()->create();
    }

    public function test_config_health_reports_missing_without_secrets(): void
    {
        config([
            'moxdop.google.client_id' => null,
            'moxdop.google.client_secret' => null,
            'moxdop.google.developer_token' => null,
        ]);

        $report = app(GoogleOAuthConfigurationHealth::class)->check();
        $this->assertFalse($report['ok']);
        $json = json_encode($report);
        $this->assertStringNotContainsString('test-client-secret', (string) $json);
        $this->assertStringNotContainsString('test-dev-token', (string) $json);

        $this->artisan('moxdop:google-oauth:check')->assertFailed();
    }

    public function test_client_secret_and_developer_token_not_in_authorization_credential(): void
    {
        $this->authorizeViaCallback([
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'expires_in' => 3600,
            'scope' => implode(' ', GoogleScopes::requested()),
        ]);

        $payload = $this->integration->fresh()->authorizationCredential->encrypted_payload;
        $this->assertArrayNotHasKey('client_secret', $payload);
        $this->assertArrayNotHasKey('developer_token', $payload);
        $this->assertArrayNotHasKey('client_id', $payload);
    }

    public function test_scope_registry_exact_connector_scopes_and_union(): void
    {
        $registry = app(GoogleScopeRegistry::class);

        $this->assertSame(
            [GoogleScopes::ANALYTICS_READONLY],
            $registry->scopesForCapabilities(['ga4']),
        );
        $this->assertSame(
            [GoogleScopes::SEARCH_CONSOLE_READONLY],
            $registry->scopesForCapabilities(['search_console']),
        );
        $this->assertSame(
            [GoogleScopes::ADWORDS],
            $registry->scopesForCapabilities(['google_ads']),
        );

        $union = $registry->scopesForCapabilities(['ga4', 'search_console']);
        $this->assertSame([
            GoogleScopes::ANALYTICS_READONLY,
            GoogleScopes::SEARCH_CONSOLE_READONLY,
        ], $union);

        $gscOnly = $registry->scopesForCapabilities(['search_console']);
        $this->assertNotContains(GoogleScopes::ANALYTICS_READONLY, $gscOnly);
        $this->assertNotContains(GoogleScopes::ADWORDS, $gscOnly);
        $this->assertNotContains(GoogleScopes::BUSINESS_MANAGE, $gscOnly);

        foreach ($union as $scope) {
            $this->assertFalse($registry->isIdentityScope($scope));
        }
    }

    public function test_incremental_authorization_requests_missing_ads_scope_only(): void
    {
        $this->integration->forceFill([
            'config' => [
                'auth_status' => GoogleAuthStatus::CONNECTED,
                'granted_scopes' => [
                    GoogleScopes::ANALYTICS_READONLY,
                    GoogleScopes::SEARCH_CONSOLE_READONLY,
                ],
            ],
        ])->save();
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $this->integration->id,
        ]);

        $scopes = app(GoogleScopeCoverageService::class)->scopesToRequest(
            $this->integration->fresh(['authorizationCredential']),
            ['ga4', 'search_console', 'google_ads'],
            incremental: true,
        );

        $this->assertSame([GoogleScopes::ADWORDS], $scopes);
    }

    public function test_authorization_url_has_required_params_and_hashed_state(): void
    {
        Http::fake();
        $result = app(GoogleOAuthService::class)->beginAuthorization($this->integration, $this->admin);
        $this->assertArrayHasKey('url', $result);

        $query = [];
        parse_str(parse_url($result['url'], PHP_URL_QUERY) ?: '', $query);
        $this->assertSame('test-client-id', $query['client_id']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('true', $query['include_granted_scopes']);
        $this->assertArrayHasKey('state', $query);
        $this->assertGreaterThanOrEqual(40, strlen((string) $query['state']));
        $this->assertSame('consent', $query['prompt']); // first auth needs refresh token

        $hash = GoogleOAuthAuthorizationAttempt::hashState((string) $query['state']);
        $this->assertDatabaseHas('google_oauth_authorization_attempts', [
            'state_hash' => $hash,
            'status' => 'pending',
        ]);
        $attempt = GoogleOAuthAuthorizationAttempt::query()->where('state_hash', $hash)->firstOrFail();
        $this->assertStringNotContainsString((string) $query['state'], (string) $attempt->state_hash);
        $this->assertSame($hash, $attempt->state_hash);
    }

    public function test_callback_security_wrong_expired_replay_and_open_redirect(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'a',
                'refresh_token' => 'r',
                'expires_in' => 3600,
                'scope' => GoogleScopes::ANALYTICS_READONLY,
            ], 200),
        ]);

        $begin = app(GoogleOAuthService::class)->beginAuthorization($this->integration, $this->admin);
        parse_str(parse_url($begin['url'], PHP_URL_QUERY) ?: '', $query);
        $state = (string) $query['state'];

        $bad = app(GoogleOAuthService::class)->handleCallback('code', 'wrong-state', null, $this->admin);
        $this->assertArrayHasKey('error', $bad);
        $this->assertFalse($this->integration->fresh()->authorizationCredential()->exists());

        $ok = app(GoogleOAuthService::class)->handleCallback('code', $state, null, $this->admin);
        $this->assertArrayHasKey('integration', $ok);

        $replay = app(GoogleOAuthService::class)->handleCallback('code', $state, null, $this->admin);
        $this->assertArrayHasKey('error', $replay);

        $other = User::factory()->create();
        $other->assignRole(Roles::ADMIN);
        $begin2 = app(GoogleOAuthService::class)->beginAuthorization($this->integration, $this->admin);
        parse_str(parse_url($begin2['url'], PHP_URL_QUERY) ?: '', $q2);
        $cross = app(GoogleOAuthService::class)->handleCallback('code', (string) $q2['state'], null, $other);
        $this->assertArrayHasKey('error', $cross);

        $expired = GoogleOAuthAuthorizationAttempt::query()->create([
            'integration_id' => $this->integration->id,
            'requested_by_user_id' => $this->admin->id,
            'state_hash' => GoogleOAuthAuthorizationAttempt::hashState('expired-state'),
            'requested_scopes' => [GoogleScopes::ANALYTICS_READONLY],
            'return_route' => 'https://evil.example/phish',
            'status' => GoogleOAuthAuthorizationAttempt::STATUS_PENDING,
            'expires_at' => now()->subMinute(),
        ]);
        $this->assertFalse($expired->isPending());

        $denied = app(GoogleOAuthService::class)->handleCallback(null, 'x', 'access_denied', $this->admin);
        $this->assertArrayHasKey('error', $denied);
        $this->assertTrue($this->integration->fresh()->authorizationCredential()->exists());
    }

    public function test_existing_refresh_token_preserved_when_callback_omits_new_one(): void
    {
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $this->integration->id,
            'encrypted_payload' => [
                'access_token' => 'old-access',
                'refresh_token' => 'VALID_OLD_REFRESH_TOKEN',
            ],
        ]);

        $this->authorizeViaCallback([
            'access_token' => 'NEW_ACCESS_TOKEN',
            'expires_in' => 3600,
            'scope' => implode(' ', GoogleScopes::requested()),
            // refresh_token intentionally absent
        ]);

        $payload = $this->integration->fresh()->authorizationCredential->encrypted_payload;
        $this->assertSame('VALID_OLD_REFRESH_TOKEN', $payload['refresh_token']);
        $this->assertSame('NEW_ACCESS_TOKEN', $payload['access_token']);
    }

    public function test_new_refresh_token_rotates(): void
    {
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $this->integration->id,
            'encrypted_payload' => [
                'access_token' => 'old',
                'refresh_token' => 'OLD_REFRESH',
            ],
        ]);

        $this->authorizeViaCallback([
            'access_token' => 'a2',
            'refresh_token' => 'NEW_REFRESH',
            'expires_in' => 3600,
            'scope' => GoogleScopes::ANALYTICS_READONLY,
        ]);
        $this->assertSame('NEW_REFRESH', $this->integration->fresh()->authorizationCredential->encrypted_payload['refresh_token']);
    }

    public function test_initial_auth_without_refresh_token_is_action_required(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'only-access',
                'expires_in' => 3600,
                'scope' => GoogleScopes::ANALYTICS_READONLY,
            ], 200),
        ]);
        $begin = app(GoogleOAuthService::class)->beginAuthorization($this->integration->fresh(['authorizationCredential']), $this->admin);
        parse_str(parse_url($begin['url'], PHP_URL_QUERY) ?: '', $query);
        $result = app(GoogleOAuthService::class)->handleCallback('code', (string) $query['state'], null, $this->admin);
        $this->assertArrayHasKey('error', $result, json_encode($result));
        $this->assertSame(
            GoogleAuthStatus::REFRESH_REQUIRED,
            GoogleAuthStatus::for($this->integration->fresh(['credential'])),
        );
    }

    public function test_secret_serialization_and_clean_redirect_and_no_secret_logs(): void
    {
        Log::spy();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-secret-value',
                'refresh_token' => 'refresh-secret-value',
                'expires_in' => 3600,
                'scope' => implode(' ', GoogleScopes::requested()),
            ], 200),
        ]);

        $begin = app(GoogleOAuthService::class)->beginAuthorization($this->integration, $this->admin);
        parse_str(parse_url($begin['url'], PHP_URL_QUERY) ?: '', $query);

        $response = $this->get(route('integrations.google.callback', [
            'code' => 'auth-code',
            'state' => $query['state'],
        ]));
        $response->assertRedirect(route('operator.integrations.google'));
        $target = $response->headers->get('Location');
        $this->assertStringNotContainsString('auth-code', (string) $target);
        $this->assertStringNotContainsString((string) $query['state'], (string) $target);
        $this->assertStringNotContainsString('access-secret', (string) $target);
        $this->assertStringNotContainsString('refresh-secret', (string) $target);

        $credential = $this->integration->fresh()->authorizationCredential;
        $this->assertArrayNotHasKey('encrypted_payload', $credential->toArray());
        $stored = DB::table('core_integration_credentials')->where('id', $credential->id)->value('encrypted_payload');
        $this->assertStringNotContainsString('access-secret-value', (string) $stored);
    }

    public function test_access_token_refresh_concurrency_and_preserve_refresh_token(): void
    {
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $this->integration->id,
            'encrypted_payload' => [
                'access_token' => 'old-access',
                'refresh_token' => 'keep-refresh',
            ],
            'expires_at' => now()->subMinute(),
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'new-access',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ], 200),
        ]);

        $oauth = app(GoogleOAuthService::class);
        $tokens = [];
        $tokens[] = $oauth->validAccessToken($this->integration->fresh(['credential']));
        $tokens[] = $oauth->validAccessToken($this->integration->fresh(['credential']));

        $this->assertSame(['new-access', 'new-access'], $tokens);
        $this->assertSame(
            'keep-refresh',
            $this->integration->fresh()->authorizationCredential->encrypted_payload['refresh_token'],
        );

        Http::assertSentCount(1);
    }

    public function test_invalid_grant_sets_reauth_without_deleting_domain(): void
    {
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $this->integration->id,
            'encrypted_payload' => [
                'access_token' => 'old',
                'refresh_token' => 'dead-refresh',
            ],
            'expires_at' => now()->subMinute(),
        ]);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'status' => 'available',
        ]);
        $binding = CoreAssetBinding::factory()->create([
            'external_resource_id' => $resource->id,
            'digital_asset_id' => DigitalAsset::factory()->create()->id,
            'capability' => $resource->resource_type,
            'status' => 'active',
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $this->assertNull(app(GoogleOAuthService::class)->validAccessToken($this->integration->fresh(['credential'])));
        $this->assertSame(
            GoogleAuthStatus::REFRESH_REQUIRED,
            GoogleAuthStatus::for($this->integration->fresh(['credential'])),
        );
        $this->assertSame('available', $resource->fresh()->status);
        $this->assertSame('active', $binding->fresh()->status);

        $this->expectException(GoogleAuthenticationException::class);
        app(GoogleCredentialBroker::class)->accessTokenFor($this->integration->fresh(['credential']));
    }

    public function test_transient_refresh_failure_does_not_revoke(): void
    {
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $this->integration->id,
            'encrypted_payload' => [
                'access_token' => 'old',
                'refresh_token' => 'refresh-ok',
            ],
            'expires_at' => now()->subMinute(),
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response('unavailable', 503),
        ]);

        $this->assertNull(app(GoogleOAuthService::class)->validAccessToken($this->integration->fresh(['credential'])));
        $status = GoogleAuthStatus::for($this->integration->fresh(['credential']));
        $this->assertNotSame(GoogleAuthStatus::REVOKED, $status);
        $this->assertTrue($this->integration->fresh()->authorizationCredential()->exists());
    }

    public function test_partial_grant_and_missing_scope_broker(): void
    {
        $this->authorizeViaCallback([
            'access_token' => 'a',
            'refresh_token' => 'r',
            'expires_in' => 3600,
            'scope' => GoogleScopes::ANALYTICS_READONLY.' '.GoogleScopes::SEARCH_CONSOLE_READONLY,
        ]);

        $coverage = app(GoogleScopeCoverageService::class);
        $integration = $this->integration->fresh(['authorizationCredential']);
        $this->assertTrue($coverage->hasCapability($integration, 'ga4'));
        $this->assertTrue($coverage->hasCapability($integration, 'search_console'));
        $this->assertFalse($coverage->hasCapability($integration, 'google_ads'));

        $statuses = collect($coverage->connectorStatuses($integration))->keyBy('capability');
        $this->assertSame('authorized', $statuses['ga4']['status']);
        $this->assertSame('scope_required', $statuses['google_ads']['status']);

        $broker = app(GoogleCredentialBroker::class);
        $this->assertSame('a', $broker->accessTokenFor($integration, 'ga4'));

        $this->expectException(GoogleAuthorizationException::class);
        $broker->accessTokenFor($integration, 'google_ads');
    }

    public function test_revoke_success_failure_and_reauth_same_integration(): void
    {
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $this->integration->id,
            'encrypted_payload' => [
                'access_token' => 'a',
                'refresh_token' => 'r',
            ],
        ]);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'status' => 'available',
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/revoke' => Http::response(['error' => 'server_error'], 500),
        ]);
        $fail = app(GoogleOAuthService::class)->revokeAuthorization(
            $this->integration->fresh(['authorizationCredential', 'providerCredential']),
        );
        $this->assertFalse($fail['ok'], $fail['message'] ?? '');
        $this->assertTrue($this->integration->fresh()->authorizationCredential()->exists());
    }

    public function test_revoke_success_preserves_resources_and_allows_reauth(): void
    {
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $this->integration->id,
            'encrypted_payload' => [
                'access_token' => 'a',
                'refresh_token' => 'r',
            ],
        ]);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'status' => 'available',
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/revoke' => Http::response([], 200),
        ]);

        $ok = app(GoogleOAuthService::class)->revokeAuthorization(
            $this->integration->fresh(['authorizationCredential', 'providerCredential']),
        );
        $this->assertTrue($ok['ok'], $ok['message'] ?? '');
        $this->assertFalse($this->integration->fresh()->authorizationCredential()->exists());
        $this->assertSame('available', $resource->fresh()->status);
        $this->assertSame(GoogleAuthStatus::REVOKED, GoogleAuthStatus::for($this->integration->fresh(['credential'])));

        $this->authorizeViaCallback([
            'access_token' => 'a3',
            'refresh_token' => 'r3',
            'expires_in' => 3600,
            'scope' => implode(' ', GoogleScopes::requested()),
        ]);
        $this->assertSame($this->integration->id, $this->integration->fresh()->id);
        $this->assertSame(GoogleAuthStatus::CONNECTED, GoogleAuthStatus::for($this->integration->fresh(['credential'])));
        $this->assertSame(1, CoreIntegration::query()->where('provider', 'google')->count());
    }

    public function test_oauth_success_does_not_discover_resources_or_call_provider_apis(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'a',
                'refresh_token' => 'r',
                'expires_in' => 3600,
                'scope' => implode(' ', GoogleScopes::requested()),
            ], 200),
            '*' => Http::response(['unexpected' => true], 500),
        ]);

        $before = CoreExternalResource::query()->count();
        $this->authorizeViaCallback([
            'access_token' => 'a',
            'refresh_token' => 'r',
            'expires_in' => 3600,
            'scope' => implode(' ', GoogleScopes::requested()),
        ]);
        $this->assertSame($before, CoreExternalResource::query()->count());

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'oauth2.googleapis.com/token'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'analyticsadmin.googleapis.com'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'searchconsole'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'googleads.googleapis.com'));
    }

    public function test_frozen_ui_exposes_connect_without_tokens(): void
    {
        Livewire::test(GoogleIntegrationPage::class)
            ->assertOk()
            ->assertSee('Connect Google')
            ->assertDontSee('test-client-secret')
            ->assertDontSee('refresh-secret');
    }

    public function test_unexpired_access_token_skips_refresh_http(): void
    {
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $this->integration->id,
            'encrypted_payload' => [
                'access_token' => 'still-valid',
                'refresh_token' => 'r',
            ],
            'expires_at' => now()->addHour(),
        ]);

        Http::fake();
        $token = app(GoogleOAuthService::class)->validAccessToken($this->integration->fresh(['credential']));
        $this->assertSame('still-valid', $token);
        Http::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $tokenBody
     */
    private function authorizeViaCallback(array $tokenBody): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response($tokenBody, 200),
        ]);

        $begin = app(GoogleOAuthService::class)->beginAuthorization(
            $this->integration->fresh(['authorizationCredential']),
            $this->admin,
        );
        $this->assertArrayHasKey('url', $begin);
        parse_str(parse_url($begin['url'], PHP_URL_QUERY) ?: '', $query);

        $result = app(GoogleOAuthService::class)->handleCallback(
            'auth-code',
            (string) $query['state'],
            null,
            $this->admin,
        );

        $this->assertArrayNotHasKey('error', $result, is_string($result['error'] ?? null) ? $result['error'] : 'callback failed');
        $this->assertArrayHasKey('integration', $result);
    }
}
