<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Integrations\Pages\EditIntegration;
use App\Filament\App\Resources\Integrations\Pages\ViewIntegration;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\User;
use App\Services\Integrations\Google\GoogleCredentialResolver;
use App\Services\Integrations\Google\GoogleOAuthRedirectUriResolver;
use App\Services\Integrations\Google\GoogleOAuthService;
use App\Services\Integrations\Google\GoogleProviderCredentialService;
use App\Services\Integrations\Google\GoogleResourceRefreshService;
use App\Support\Integrations\Google\GoogleAuthStatus;
use App\Support\Integrations\Google\GoogleIntegrationConfigGuard;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class GoogleIntegrationConsolidationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CoreIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        config([
            'moxdop.google.client_id' => null,
            'moxdop.google.client_secret' => null,
            'moxdop.google.redirect_uri' => null,
            'moxdop.google.developer_token' => null,
            'moxdop.google.include_gbp_scope' => false,
            'moxdop.google.gbp_discovery_enabled' => false,
            'app.url' => 'http://127.0.0.1:8000',
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel('app');

        $this->integration = CoreIntegration::factory()->google()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => [],
        ]);
    }

    public function test_redirect_uri_follows_localhost_app_url(): void
    {
        config(['app.url' => 'http://127.0.0.1:8000', 'moxdop.google.redirect_uri' => null]);

        $uri = app(GoogleOAuthRedirectUriResolver::class)->uri();

        $this->assertSame('http://127.0.0.1:8000/integrations/google/callback', $uri);
        $this->assertStringNotContainsString('localhost', $uri);
    }

    public function test_redirect_uri_follows_production_https_app_url(): void
    {
        config(['app.url' => 'https://dop.moximu.com', 'moxdop.google.redirect_uri' => null]);

        $uri = app(GoogleOAuthRedirectUriResolver::class)->uri();

        $this->assertSame('https://dop.moximu.com/integrations/google/callback', $uri);
    }

    public function test_displayed_uri_matches_authorize_and_token_exchange_redirect_uri(): void
    {
        config(['app.url' => 'https://dop.moximu.com', 'moxdop.google.redirect_uri' => null]);

        app(GoogleProviderCredentialService::class)->save($this->integration, [
            'client_id' => 'cid.apps.googleusercontent.com',
            'client_secret' => 'GOCSPX-test-secret',
        ], $this->admin);

        $expected = app(GoogleOAuthRedirectUriResolver::class)->uri();

        Livewire::test(ViewIntegration::class, ['record' => $this->integration->getRouteKey()])
            ->assertSee($expected)
            ->assertSee('Authorized redirect URIs');

        $begin = app(GoogleOAuthService::class)->beginAuthorization(
            $this->integration->fresh(['providerCredential']),
            $this->admin,
        );
        $this->assertArrayHasKey('url', $begin);
        parse_str((string) parse_url($begin['url'], PHP_URL_QUERY), $query);
        $this->assertSame($expected, $query['redirect_uri'] ?? null);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'atok',
                'refresh_token' => 'rtok',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ], 200),
        ]);

        // Drive token exchange path via handleCallback internals by posting through service.
        cache()->put('google_oauth_state:cons-state', [
            'integration_id' => $this->integration->id,
            'user_id' => $this->admin->id,
        ], now()->addMinutes(5));

        $result = app(GoogleOAuthService::class)->handleCallback('code', 'cons-state', null, $this->admin);
        $this->assertArrayHasKey('integration', $result);

        Http::assertSent(function ($request) use ($expected): bool {
            if (! str_contains($request->url(), 'oauth2.googleapis.com/token')) {
                return false;
            }

            $data = $request->data();

            return ($data['redirect_uri'] ?? null) === $expected
                && ($data['grant_type'] ?? null) === 'authorization_code';
        });
    }

    public function test_no_hard_coded_localhost_dependency_when_app_url_is_production(): void
    {
        config(['app.url' => 'https://internal.example.com', 'moxdop.google.redirect_uri' => null]);

        $uri = app(GoogleOAuthRedirectUriResolver::class)->uri();
        $this->assertSame('https://internal.example.com/integrations/google/callback', $uri);
        $this->assertStringNotContainsString('127.0.0.1', $uri);
        $this->assertStringNotContainsString('localhost', $uri);
    }

    public function test_google_edit_cannot_store_provider_secrets_in_config(): void
    {
        Livewire::test(EditIntegration::class, ['record' => $this->integration->getRouteKey()])
            ->fillForm([
                'name' => 'Google',
                'status' => CoreIntegration::STATUS_ACTIVE,
                // Even if somehow posted, prepareIntegrationAttributes ignores Google config mutations.
                'config' => [
                    'client_secret' => 'should-not-persist',
                    'developer_token' => 'should-not-persist-either',
                ],
                'credentials_json' => json_encode([
                    'client_secret' => 'json-should-not-persist',
                ], JSON_THROW_ON_ERROR),
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $this->integration->fresh();
        $this->assertSame([], $fresh->config ?? []);
        $this->assertFalse($fresh->providerCredential()->exists());
        $this->assertFalse(GoogleIntegrationConfigGuard::containsUnsafe($fresh->config ?? []));
    }

    public function test_configure_writes_encrypted_provider_credentials_and_hides_secrets_in_html(): void
    {
        Livewire::test(ViewIntegration::class, ['record' => $this->integration->getRouteKey()])
            ->callAction('configureGoogleApplication', data: [
                'client_id' => 'visible-client.apps.googleusercontent.com',
                'client_secret' => 'GOCSPX-never-show-in-html',
                'developer_token' => 'dev-token-never-show-in-html',
                'clear_client_secret' => false,
                'clear_developer_token' => false,
            ])
            ->assertHasNoActionErrors();

        $credential = CoreIntegrationCredential::query()
            ->where('integration_id', $this->integration->id)
            ->where('credential_type', CoreIntegrationCredential::TYPE_PROVIDER)
            ->firstOrFail();

        $this->assertSame('GOCSPX-never-show-in-html', $credential->encrypted_payload['client_secret']);
        $this->assertArrayNotHasKey('encrypted_payload', $credential->toArray());

        Livewire::test(ViewIntegration::class, ['record' => $this->integration->fresh()->getRouteKey()])
            ->assertOk()
            ->assertSee('visible-client.apps.googleusercontent.com')
            ->assertSee('Configured')
            ->assertDontSee('GOCSPX-never-show-in-html')
            ->assertDontSee('dev-token-never-show-in-html');

        $html = Livewire::test(ViewIntegration::class, ['record' => $this->integration->fresh()->getRouteKey()])->html();
        $this->assertStringNotContainsString('GOCSPX-never-show-in-html', $html);
        $this->assertStringNotContainsString('dev-token-never-show-in-html', $html);
    }

    public function test_authorize_test_and_refresh_use_same_configure_credentials(): void
    {
        app(GoogleProviderCredentialService::class)->save($this->integration, [
            'client_id' => 'shared-client-id',
            'client_secret' => 'shared-client-secret',
            'developer_token' => 'shared-dev-token',
        ], $this->admin);

        $fresh = $this->integration->fresh(['providerCredential']);
        $resolver = app(GoogleCredentialResolver::class);

        $this->assertSame('shared-client-id', $resolver->clientId($fresh));
        $this->assertSame('shared-client-secret', $resolver->clientSecret($fresh));
        $this->assertSame('shared-dev-token', $resolver->developerToken($fresh));
        $this->assertSame('Complete', GoogleAuthStatus::applicationConfigurationLabel($fresh));

        $begin = app(GoogleOAuthService::class)->beginAuthorization($fresh, $this->admin);
        $this->assertArrayHasKey('url', $begin);
        $this->assertStringContainsString(urlencode('shared-client-id'), $begin['url']);

        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $this->integration->id,
            'encrypted_payload' => [
                'access_token' => 'atok',
                'refresh_token' => 'rtok',
            ],
            'expires_at' => now()->addHour(),
        ]);

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, 'oauth2/v3/userinfo')) {
                return Http::response(['email' => 'ops@moximu.com'], 200);
            }
            if (str_contains($url, 'webmasters/v3/sites')) {
                return Http::response(['siteEntry' => []], 200);
            }
            if (str_contains($url, 'analyticsadmin.googleapis.com')) {
                return Http::response(['accountSummaries' => []], 200);
            }
            if (str_contains($url, 'customers:listAccessibleCustomers')) {
                return Http::response(['resourceNames' => []], 200);
            }

            return Http::response(['error' => 'unexpected '.$url], 500);
        });

        $loaded = $this->integration->fresh(['credential', 'providerCredential']);
        $this->assertSame('shared-client-secret', app(GoogleCredentialResolver::class)->clientSecret($loaded));
        $this->assertSame('shared-dev-token', app(GoogleCredentialResolver::class)->developerToken($loaded));

        $test = app(GoogleOAuthService::class)->testConnection($loaded);
        $this->assertTrue($test['ok'], $test['message'] ?? 'testConnection failed');
        $this->assertStringNotContainsString('Setup required', $test['message']);

        $refresh = app(GoogleResourceRefreshService::class)->refresh($this->integration->fresh(['credential', 'providerCredential']));
        $this->assertTrue($refresh['ok'], $refresh['message'] ?? 'refresh failed');
        $this->assertSame('ok', $refresh['results']['search_console']['status'] ?? null);
        $this->assertNotSame('setup_required', $refresh['results']['google_ads']['status'] ?? 'missing');
    }

    public function test_blank_secret_edit_preserves_and_disconnect_preserves_provider_config(): void
    {
        app(GoogleProviderCredentialService::class)->save($this->integration, [
            'client_id' => 'cid',
            'client_secret' => 'keep-secret',
            'developer_token' => 'keep-dev',
        ], $this->admin);

        app(GoogleProviderCredentialService::class)->save($this->integration->fresh(), [
            'client_id' => 'cid',
            'client_secret' => '',
            'developer_token' => '',
        ], $this->admin);

        $payload = $this->integration->fresh()->providerCredential?->encrypted_payload ?? [];
        $this->assertSame('keep-secret', $payload['client_secret'] ?? null);
        $this->assertSame('keep-dev', $payload['developer_token'] ?? null);

        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $this->integration->id,
        ]);

        Http::fake(['https://oauth2.googleapis.com/revoke' => Http::response([], 200)]);
        app(GoogleOAuthService::class)->disconnect($this->integration->fresh(['credential', 'providerCredential']));

        $this->assertFalse($this->integration->fresh()->authorizationCredential()->exists());
        $this->assertSame('keep-secret', $this->integration->fresh()->providerCredential?->encrypted_payload['client_secret'] ?? null);
    }

    public function test_config_guard_strips_misentered_client_id_keyvalue_pairs(): void
    {
        $dirty = [
            '842455333-abc.apps.googleusercontent.com' => 'GOCSPX-leaked',
            'auth_status' => 'authorization_required',
        ];

        $this->assertTrue(GoogleIntegrationConfigGuard::containsUnsafe($dirty));
        $clean = GoogleIntegrationConfigGuard::stripUnsafe($dirty);
        $this->assertSame(['auth_status' => 'authorization_required'], $clean);
    }
}
