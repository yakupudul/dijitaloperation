<?php

namespace Tests\Feature;

use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Integrations\Google\GoogleOAuthService;
use App\Support\Integrations\Google\GoogleAuthStatus;
use App\Support\Integrations\Google\GoogleScopes;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
        ]))->assertRedirect(route('operator.integrations.google'));

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
            ->assertRedirect(route('operator.integrations.google'));

        $this->assertSame(GoogleAuthStatus::NOT_CONFIGURED, GoogleAuthStatus::for($this->integration->fresh()));

        config([
            'moxdop.google.client_id' => 'test-client-id',
            'moxdop.google.client_secret' => 'test-client-secret',
        ]);

        $this->get(route('integrations.google.callback', [
            'error' => 'access_denied',
            'state' => 'x',
        ]))->assertRedirect(route('operator.integrations'));

        $this->assertStringStartsWith(url('/'), route('operator.integrations.google'));
        $this->assertStringNotContainsString('/system', route('operator.integrations.google'));
        $this->assertStringNotContainsString('/system', route('operator.integrations'));
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
}
