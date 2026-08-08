<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Integrations\IntegrationResource;
use App\Filament\App\Resources\Integrations\Pages\ViewIntegration;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\User;
use App\Services\Integrations\Google\GoogleCredentialResolver;
use App\Services\Integrations\Google\GoogleOAuthService;
use App\Services\Integrations\Google\GoogleProviderCredentialService;
use App\Support\Integrations\Google\GoogleAuthStatus;
use App\Support\Integrations\Google\GoogleScopes;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\TestCase;

class IntegrationProviderCredentialsTest extends TestCase
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
            'moxdop.google.redirect_uri' => 'http://localhost/integrations/google/callback',
            'moxdop.google.developer_token' => null,
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

    public function test_provider_credential_encrypted_at_rest(): void
    {
        app(GoogleProviderCredentialService::class)->save($this->integration, [
            'client_id' => 'db-client-id',
            'client_secret' => 'db-client-secret',
            'developer_token' => 'db-dev-token',
        ], $this->admin);

        $credential = CoreIntegrationCredential::query()
            ->where('integration_id', $this->integration->id)
            ->where('credential_type', CoreIntegrationCredential::TYPE_PROVIDER)
            ->firstOrFail();

        $stored = DB::table('core_integration_credentials')->where('id', $credential->id)->value('encrypted_payload');
        $this->assertIsString($stored);
        $this->assertStringNotContainsString('db-client-secret', $stored);
        $this->assertStringNotContainsString('db-dev-token', $stored);
        $this->assertSame('db-client-secret', $credential->encrypted_payload['client_secret']);
        $this->assertArrayNotHasKey('encrypted_payload', $credential->toArray());
    }

    public function test_authorization_credential_encrypted_at_rest(): void
    {
        config([
            'moxdop.google.client_id' => 'env-client',
            'moxdop.google.client_secret' => 'env-secret',
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-secret-value',
                'refresh_token' => 'refresh-secret-value',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
                'scope' => implode(' ', GoogleScopes::requested()),
            ], 200),
        ]);

        Cache::put('google_oauth_state:cred-state', [
            'integration_id' => $this->integration->id,
            'user_id' => $this->admin->id,
        ], now()->addMinutes(10));

        $this->get(route('integrations.google.callback', [
            'code' => 'auth-code',
            'state' => 'cred-state',
        ]))->assertRedirect();

        $credential = CoreIntegrationCredential::query()
            ->where('integration_id', $this->integration->id)
            ->where('credential_type', CoreIntegrationCredential::TYPE_AUTHORIZATION)
            ->firstOrFail();

        $stored = DB::table('core_integration_credentials')->where('id', $credential->id)->value('encrypted_payload');
        $this->assertStringNotContainsString('access-secret-value', $stored);
        $this->assertStringNotContainsString('refresh-secret-value', $stored);
        $this->assertSame('refresh-secret-value', $credential->encrypted_payload['refresh_token']);
    }

    public function test_provider_and_authorization_credentials_coexist(): void
    {
        CoreIntegrationCredential::factory()->provider()->create([
            'integration_id' => $this->integration->id,
            'encrypted_payload' => [
                'client_id' => 'cid',
                'client_secret' => 'csecret',
                'developer_token' => 'dtoken',
            ],
        ]);
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $this->integration->id,
            'encrypted_payload' => [
                'access_token' => 'atok',
                'refresh_token' => 'rtok',
            ],
        ]);

        $this->assertSame(2, $this->integration->credentials()->count());
        $this->assertTrue($this->integration->providerCredential()->exists());
        $this->assertTrue($this->integration->authorizationCredential()->exists());
        $this->assertTrue($this->integration->credential()->exists());
    }

    public function test_oauth_callback_updates_authorization_credential_only(): void
    {
        CoreIntegrationCredential::factory()->provider()->create([
            'integration_id' => $this->integration->id,
            'encrypted_payload' => [
                'client_id' => 'cid',
                'client_secret' => 'csecret',
                'developer_token' => 'keep-me',
            ],
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'new-access',
                'refresh_token' => 'new-refresh',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ], 200),
        ]);

        Cache::put('google_oauth_state:only-auth', [
            'integration_id' => $this->integration->id,
            'user_id' => $this->admin->id,
        ], now()->addMinutes(10));

        $this->get(route('integrations.google.callback', [
            'code' => 'code',
            'state' => 'only-auth',
        ]))->assertRedirect();

        $provider = $this->integration->fresh()->providerCredential;
        $this->assertSame('keep-me', $provider?->encrypted_payload['developer_token'] ?? null);
        $this->assertNull(data_get($provider?->encrypted_payload, 'access_token'));

        $auth = $this->integration->fresh()->authorizationCredential;
        $this->assertSame('new-refresh', $auth?->encrypted_payload['refresh_token'] ?? null);
        $this->assertNull(data_get($auth?->encrypted_payload, 'client_secret'));
    }

    public function test_refresh_updates_authorization_only(): void
    {
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
                'access_token' => 'old-access',
                'refresh_token' => 'refresh-me',
            ],
            'expires_at' => now()->subMinute(),
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'rotated-access',
                'expires_in' => 3600,
            ], 200),
        ]);

        $token = app(GoogleOAuthService::class)->validAccessToken($this->integration->fresh(['credential', 'providerCredential']));
        $this->assertSame('rotated-access', $token);

        $provider = $this->integration->fresh()->providerCredential;
        $this->assertSame('dev', $provider?->encrypted_payload['developer_token'] ?? null);
        $auth = $this->integration->fresh()->authorizationCredential;
        $this->assertSame('rotated-access', $auth?->encrypted_payload['access_token'] ?? null);
        $this->assertSame('refresh-me', $auth?->encrypted_payload['refresh_token'] ?? null);
    }

    public function test_disconnect_removes_authorization_but_preserves_provider_credentials(): void
    {
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
        ]);

        Http::fake(['https://oauth2.googleapis.com/revoke' => Http::response([], 200)]);

        $result = app(GoogleOAuthService::class)->disconnect($this->integration->fresh(['credential', 'providerCredential']));
        $this->assertTrue($result['ok']);

        $fresh = $this->integration->fresh();
        $this->assertFalse($fresh->authorizationCredential()->exists());
        $this->assertTrue($fresh->providerCredential()->exists());
        $this->assertSame('csecret', $fresh->providerCredential->encrypted_payload['client_secret']);
        $this->assertSame('dev', $fresh->providerCredential->encrypted_payload['developer_token']);
        $this->assertDatabaseHas('core_integrations', ['id' => $this->integration->id]);
    }

    public function test_admin_can_configure_provider_credentials_via_filament(): void
    {
        Livewire::test(ViewIntegration::class, ['record' => $this->integration->getRouteKey()])
            ->callAction('configureGoogleApplication', data: [
                'client_id' => 'ui-client-id',
                'client_secret' => 'ui-client-secret',
                'developer_token' => 'ui-dev-token',
                'clear_client_secret' => false,
                'clear_developer_token' => false,
            ])
            ->assertHasNoActionErrors();

        $resolver = app(GoogleCredentialResolver::class);
        $fresh = $this->integration->fresh(['providerCredential']);
        $this->assertSame('ui-client-id', $resolver->clientId($fresh));
        $this->assertSame('ui-client-secret', $resolver->clientSecret($fresh));
        $this->assertSame('ui-dev-token', $resolver->developerToken($fresh));
        $this->assertSame(GoogleCredentialResolver::SOURCE_DATABASE, $resolver->clientSecretSource($fresh));
    }

    public function test_team_member_cannot_configure_provider_credentials(): void
    {
        $team = User::factory()->create();
        $team->assignRole(Roles::TEAM_MEMBER);
        $this->actingAs($team);

        $this->assertFalse(IntegrationResource::canAccess());

        $this->expectException(\RuntimeException::class);
        app(GoogleProviderCredentialService::class)->save($this->integration, [
            'client_id' => 'nope',
            'client_secret' => 'nope',
        ], $team);
    }

    public function test_secrets_are_write_only_in_filament_view(): void
    {
        app(GoogleProviderCredentialService::class)->save($this->integration, [
            'client_id' => 'visible-client-id',
            'client_secret' => 'never-show-secret',
            'developer_token' => 'never-show-dev',
        ], $this->admin);

        Livewire::test(ViewIntegration::class, ['record' => $this->integration->fresh()->getRouteKey()])
            ->assertOk()
            ->assertSee('Application configuration')
            ->assertSee('Configured')
            ->assertSee('visible-client-id')
            ->assertDontSee('never-show-secret')
            ->assertDontSee('never-show-dev')
            ->assertSee('OAuth Redirect URI')
            ->assertSee('http://localhost/integrations/google/callback');
    }

    public function test_blank_secret_edit_preserves_stored_value(): void
    {
        app(GoogleProviderCredentialService::class)->save($this->integration, [
            'client_id' => 'cid',
            'client_secret' => 'original-secret',
            'developer_token' => 'original-dev',
        ], $this->admin);

        app(GoogleProviderCredentialService::class)->save($this->integration->fresh(), [
            'client_id' => 'cid-updated',
            'client_secret' => '',
            'developer_token' => '',
            'clear_client_secret' => false,
            'clear_developer_token' => false,
        ], $this->admin);

        $payload = $this->integration->fresh()->providerCredential?->encrypted_payload ?? [];
        $this->assertSame('cid-updated', $payload['client_id'] ?? null);
        $this->assertSame('original-secret', $payload['client_secret'] ?? null);
        $this->assertSame('original-dev', $payload['developer_token'] ?? null);
    }

    public function test_env_fallback_works_when_database_provider_credential_missing(): void
    {
        config([
            'moxdop.google.client_id' => 'env-client-id',
            'moxdop.google.client_secret' => 'env-client-secret',
            'moxdop.google.developer_token' => 'env-dev-token',
        ]);

        $resolver = app(GoogleCredentialResolver::class);
        $this->assertTrue($resolver->isAppConfigured($this->integration));
        $this->assertSame('env-client-id', $resolver->clientId($this->integration));
        $this->assertSame('env-client-secret', $resolver->clientSecret($this->integration));
        $this->assertSame('env-dev-token', $resolver->developerToken($this->integration));
        $this->assertSame(GoogleCredentialResolver::SOURCE_ENVIRONMENT, $resolver->clientIdSource($this->integration));
        $this->assertSame('Configured by environment', $resolver->configurationLabel(
            GoogleCredentialResolver::SOURCE_ENVIRONMENT,
            true,
        ));
    }

    public function test_database_provider_credential_takes_precedence_over_env(): void
    {
        config([
            'moxdop.google.client_id' => 'env-client-id',
            'moxdop.google.client_secret' => 'env-client-secret',
            'moxdop.google.developer_token' => 'env-dev-token',
        ]);

        app(GoogleProviderCredentialService::class)->save($this->integration, [
            'client_id' => 'db-client-id',
            'client_secret' => 'db-client-secret',
            'developer_token' => 'db-dev-token',
        ], $this->admin);

        $resolver = app(GoogleCredentialResolver::class);
        $fresh = $this->integration->fresh(['providerCredential']);
        $this->assertSame('db-client-id', $resolver->clientId($fresh));
        $this->assertSame('db-client-secret', $resolver->clientSecret($fresh));
        $this->assertSame('db-dev-token', $resolver->developerToken($fresh));
        $this->assertSame(GoogleCredentialResolver::SOURCE_DATABASE, $resolver->developerTokenSource($fresh));
    }

    public function test_missing_configuration_gives_clean_setup_required_state(): void
    {
        $this->assertSame(GoogleAuthStatus::NOT_CONFIGURED, GoogleAuthStatus::for($this->integration));
        $this->assertSame('Incomplete', GoogleAuthStatus::applicationConfigurationLabel($this->integration));
        $this->assertSame('Developer token missing', GoogleAuthStatus::adsDeveloperTokenLabel($this->integration));

        $begin = app(GoogleOAuthService::class)->beginAuthorization($this->integration, $this->admin);
        $this->assertArrayHasKey('error', $begin);
        $this->assertStringContainsString('application credentials', $begin['error']);
    }

    public function test_google_ads_developer_token_resolves_through_resolver(): void
    {
        app(GoogleProviderCredentialService::class)->save($this->integration, [
            'client_id' => 'cid',
            'client_secret' => 'csecret',
            'developer_token' => 'ads-token-from-db',
        ], $this->admin);

        $this->assertSame(
            'ads-token-from-db',
            app(GoogleCredentialResolver::class)->developerToken($this->integration->fresh(['providerCredential'])),
        );
    }

    public function test_explicit_clear_removes_stored_secret_deliberately(): void
    {
        app(GoogleProviderCredentialService::class)->save($this->integration, [
            'client_id' => 'cid',
            'client_secret' => 'secret',
            'developer_token' => 'dev',
        ], $this->admin);

        app(GoogleProviderCredentialService::class)->save($this->integration->fresh(), [
            'client_id' => 'cid',
            'clear_client_secret' => true,
            'clear_developer_token' => true,
        ], $this->admin);

        $payload = $this->integration->fresh()->providerCredential?->encrypted_payload ?? [];
        $this->assertSame('cid', $payload['client_id'] ?? null);
        $this->assertArrayNotHasKey('client_secret', $payload);
        $this->assertArrayNotHasKey('developer_token', $payload);
    }

    public function test_provider_and_authorization_categories_cannot_overwrite_each_other(): void
    {
        CoreIntegrationCredential::factory()->provider()->create([
            'integration_id' => $this->integration->id,
        ]);
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $this->integration->id,
        ]);

        CoreIntegrationCredential::query()->updateOrCreate(
            [
                'integration_id' => $this->integration->id,
                'credential_type' => CoreIntegrationCredential::TYPE_AUTHORIZATION,
            ],
            [
                'encrypted_payload' => [
                    'access_token' => 'only-auth',
                    'refresh_token' => 'only-auth-r',
                ],
            ],
        );

        $provider = $this->integration->fresh()->providerCredential;
        $this->assertSame('sample-client-secret', $provider?->encrypted_payload['client_secret'] ?? null);
        $this->assertNull(data_get($provider?->encrypted_payload, 'access_token'));
    }

    public function test_no_plaintext_secrets_in_logs_during_save(): void
    {
        Log::spy();

        app(GoogleProviderCredentialService::class)->save($this->integration, [
            'client_id' => 'cid',
            'client_secret' => 'log-should-not-see-this-secret',
            'developer_token' => 'log-should-not-see-this-dev',
        ], $this->admin);

        Log::shouldNotHaveReceived('info', fn (...$args): bool => str_contains(json_encode($args) ?: '', 'log-should-not-see-this-secret'));
        Log::shouldNotHaveReceived('warning', fn (...$args): bool => str_contains(json_encode($args) ?: '', 'log-should-not-see-this-dev'));
    }
}
