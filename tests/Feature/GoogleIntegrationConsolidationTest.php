<?php

namespace Tests\Feature;

use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\User;
use App\Services\Integrations\Google\GoogleOAuthRedirectUriResolver;
use App\Services\Integrations\Google\GoogleOAuthService;
use App\Services\Integrations\Google\GoogleProviderCredentialService;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
