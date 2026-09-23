<?php

namespace Tests\Feature;

use App\Models\CoreIntegration;
use App\Models\User;
use App\Services\Integrations\Google\GoogleOAuthService;
use App\Services\Integrations\Google\GoogleProviderCredentialService;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleLiveAuthUxTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CoreIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        config([
            'app.url' => 'http://127.0.0.1:8000',
            'moxdop.google.client_id' => null,
            'moxdop.google.client_secret' => null,
            'moxdop.google.redirect_uri' => null,
            'moxdop.google.developer_token' => null,
            'moxdop.google.include_gbp_scope' => false,
            'moxdop.google.gbp_discovery_enabled' => false,
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel('app');
        Filament::bootCurrentPanel();

        $this->integration = CoreIntegration::factory()->google()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
        ]);
    }

    public function test_google_authorize_url_is_excluded_from_filament_spa_navigation(): void
    {
        $panel = Filament::getCurrentPanel();
        $this->assertNotNull($panel);
        $this->assertTrue($panel->hasSpaMode());
        $this->assertContains(
            '*/integrations/google/*/authorize',
            $panel->getSpaUrlExceptions(),
        );

        $authorizeUrl = url('/integrations/google/'.$this->integration->id.'/authorize');
        $appUrl = url('/admin/settings/integrations');

        $this->assertFalse(
            FilamentView::hasSpaMode($authorizeUrl),
            'Authorize launch URL must bypass wire:navigate for redirect()->away() to Google.',
        );
        $this->assertTrue(
            FilamentView::hasSpaMode($appUrl),
            'Normal MoxDOP panel URLs must keep SPA navigation.',
        );
    }

    public function test_oauth_callback_error_codes_are_safe_and_useful(): void
    {
        $service = app(GoogleOAuthService::class);

        $denied = $service->handleCallback(null, null, 'access_denied', $this->admin);
        $this->assertArrayHasKey('error', $denied);
        $this->assertStringContainsString('denied', strtolower($denied['error']));

        Cache::put('google_oauth_state:bad-client', [
            'integration_id' => $this->integration->id,
            'user_id' => $this->admin->id,
        ], now()->addMinutes(5));

        app(GoogleProviderCredentialService::class)->save($this->integration, [
            'client_id' => 'cid',
            'client_secret' => 'secret',
        ], $this->admin);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'error' => 'redirect_uri_mismatch',
                'error_description' => 'should-not-appear-in-ui',
            ], 400),
        ]);

        $mismatch = $service->handleCallback('code', 'bad-client', null, $this->admin);
        $this->assertArrayHasKey('error', $mismatch);
        $this->assertStringContainsString('redirect URI', $mismatch['error']);
        $this->assertStringNotContainsString('should-not-appear-in-ui', $mismatch['error']);
        $this->assertStringNotContainsString('secret', $mismatch['error']);
    }

    public function test_authorize_http_launch_redirects_away_to_google(): void
    {
        app(GoogleProviderCredentialService::class)->save($this->integration, [
            'client_id' => 'cid.apps.googleusercontent.com',
            'client_secret' => 'GOCSPX-secret',
        ], $this->admin);

        $response = $this->get(route('integrations.google.authorize', $this->integration));
        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('accounts.google.com', $location);
        $this->assertStringContainsString('redirect_uri=', $location);
    }

    public function test_guest_authorize_redirects_to_filament_login_not_missing_route(): void
    {
        auth()->logout();

        $response = $this->get(route('integrations.google.authorize', $this->integration));
        $response->assertRedirect('/login');
    }
}
