<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Integrations\Pages\ViewIntegration;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\User;
use App\Services\Integrations\Google\GoogleCredentialResolver;
use App\Services\Integrations\Google\GoogleOAuthService;
use App\Services\Integrations\Google\GoogleProviderCredentialService;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Filament\Support\Facades\FilamentView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
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

    public function test_authorize_action_renders_without_wire_navigate_and_uses_full_browser_get(): void
    {
        app(GoogleProviderCredentialService::class)->save($this->integration, [
            'client_id' => 'cid.apps.googleusercontent.com',
            'client_secret' => 'GOCSPX-secret',
            'developer_token' => 'dev-token',
        ], $this->admin);

        $html = Livewire::test(ViewIntegration::class, ['record' => $this->integration->fresh()->getRouteKey()])
            ->assertOk()
            ->html();

        $authorizePath = '/integrations/google/'.$this->integration->id.'/authorize';
        $this->assertStringContainsString($authorizePath, $html);

        // Extract the authorize anchor and ensure wire:navigate is not attached to it.
        $matched = preg_match(
            '/<a[^>]+href="[^"]*'.preg_quote($authorizePath, '/').'[^"]*"[^>]*>/i',
            $html,
            $anchor,
        );
        $this->assertSame(1, $matched, 'Authorize Google anchor not found in rendered HTML.');
        $this->assertStringNotContainsString('wire:navigate', $anchor[0]);
        $this->assertStringNotContainsString('wire:click', $anchor[0]);
        // Prefer same-origin relative launch URL so APP_URL host mismatches cannot break the session.
        $this->assertMatchesRegularExpression(
            '/href="'.preg_quote($authorizePath, '/').'"/',
            $anchor[0],
        );
    }

    public function test_action_states_require_configuration_then_authorization(): void
    {
        Livewire::test(ViewIntegration::class, ['record' => $this->integration->getRouteKey()])
            ->assertActionDisabled('authorizeGoogle')
            ->assertActionDisabled('testGoogle')
            ->assertActionDisabled('refreshGoogleResources');

        app(GoogleProviderCredentialService::class)->save($this->integration, [
            'client_id' => 'cid.apps.googleusercontent.com',
            'client_secret' => 'GOCSPX-secret',
            'developer_token' => 'dev-token',
        ], $this->admin);

        Livewire::test(ViewIntegration::class, ['record' => $this->integration->fresh()->getRouteKey()])
            ->assertActionEnabled('authorizeGoogle')
            ->assertActionDisabled('testGoogle')
            ->assertActionDisabled('refreshGoogleResources')
            ->assertSee('Authorize Google');

        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $this->integration->id,
            'encrypted_payload' => [
                'access_token' => 'atok',
                'refresh_token' => 'rtok',
            ],
            'expires_at' => now()->addHour(),
        ]);

        Livewire::test(ViewIntegration::class, ['record' => $this->integration->fresh()->getRouteKey()])
            ->assertActionEnabled('authorizeGoogle')
            ->assertActionEnabled('testGoogle')
            ->assertActionEnabled('refreshGoogleResources')
            ->assertSee('Re-authorize Google');
    }

    public function test_configure_modal_shows_stored_securely_without_plaintext_secret(): void
    {
        app(GoogleProviderCredentialService::class)->save($this->integration, [
            'client_id' => 'visible-client.apps.googleusercontent.com',
            'client_secret' => 'GOCSPX-never-render',
            'developer_token' => 'dev-never-render',
        ], $this->admin);

        $fresh = $this->integration->fresh(['providerCredential']);

        $mounted = Livewire::test(ViewIntegration::class, ['record' => $fresh->getRouteKey()])
            ->assertOk()
            ->assertSee('visible-client.apps.googleusercontent.com')
            ->assertDontSee('GOCSPX-never-render')
            ->assertDontSee('dev-never-render')
            ->mountAction('configureGoogleApplication')
            ->assertActionDataSet([
                'client_id' => 'visible-client.apps.googleusercontent.com',
                'client_secret' => '',
                'developer_token' => '',
            ]);

        $schema = $mounted->instance()->getSchema('mountedActionSchema0');
        $this->assertNotNull($schema);

        $secretField = $schema->getComponent('client_secret');
        $tokenField = $schema->getComponent('developer_token');
        $this->assertNotNull($secretField);
        $this->assertNotNull($tokenField);
        $this->assertSame('•••••••• (stored)', $secretField->getPlaceholder());
        $this->assertSame('•••••••• (stored)', $tokenField->getPlaceholder());

        $secretBelow = (string) ($secretField->getChildSchema(Field::BELOW_CONTENT_SCHEMA_KEY)?->toHtmlString() ?? '');
        $tokenBelow = (string) ($tokenField->getChildSchema(Field::BELOW_CONTENT_SCHEMA_KEY)?->toHtmlString() ?? '');
        $this->assertStringContainsString('Stored securely', $secretBelow);
        $this->assertStringContainsString('leave blank to keep current value', $secretBelow);
        $this->assertStringContainsString('Stored securely', $tokenBelow);
        $this->assertStringNotContainsString('GOCSPX-never-render', $secretBelow.$tokenBelow);

        // Blank secret fields must preserve existing encrypted values.
        Livewire::test(ViewIntegration::class, ['record' => $fresh->getRouteKey()])
            ->callAction('configureGoogleApplication', data: [
                'client_id' => 'visible-client.apps.googleusercontent.com',
                'client_secret' => '',
                'developer_token' => '',
                'clear_client_secret' => false,
                'clear_developer_token' => false,
            ])
            ->assertHasNoActionErrors();

        $resolver = app(GoogleCredentialResolver::class);
        $reloaded = $this->integration->fresh(['providerCredential']);
        $this->assertSame('GOCSPX-never-render', $resolver->clientSecret($reloaded));
        $this->assertSame('dev-never-render', $resolver->developerToken($reloaded));
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
        $response->assertRedirect('/app/login');
    }
}
