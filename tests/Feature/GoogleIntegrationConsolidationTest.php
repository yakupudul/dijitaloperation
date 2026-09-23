<?php

namespace Tests\Feature;

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

    public function test_no_hard_coded_localhost_dependency_when_app_url_is_production(): void
    {
        config(['app.url' => 'https://internal.example.com', 'moxdop.google.redirect_uri' => null]);

        $uri = app(GoogleOAuthRedirectUriResolver::class)->uri();
        $this->assertSame('https://internal.example.com/integrations/google/callback', $uri);
        $this->assertStringNotContainsString('127.0.0.1', $uri);
        $this->assertStringNotContainsString('localhost', $uri);
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
        $this->assertSame('Configured', GoogleAuthStatus::applicationConfigurationLabel($fresh));

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

            if (str_contains($url, 'googleAds:search')) {
                return Http::response(['results' => []], 200);
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
