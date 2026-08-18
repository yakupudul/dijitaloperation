<?php

namespace Tests\Feature;

use App\Livewire\Demo\Integrations\GoogleIntegrationPage;
use App\Livewire\Demo\Integrations\IntegrationsIndex;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\User;
use App\Services\Integrations\Google\GoogleCredentialResolver;
use App\Services\Integrations\Google\GoogleProviderCredentialService;
use App\Services\Integrations\OperatorIntegrationsHubQuery;
use App\Support\Integrations\Google\GoogleAuthStatus;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class OperatorGoogleIntegrationConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private const string SECRET = 'ggl-test-client-secret-002b';

    private const string TOKEN = 'ggl-test-dev-token-002b';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        config([
            'moxdop.google.client_id' => null,
            'moxdop.google.client_secret' => null,
            'moxdop.google.developer_token' => null,
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);

        Http::fake();
    }

    public function test_admin_sees_write_only_configuration_fields(): void
    {
        Livewire::test(GoogleIntegrationPage::class, ['tab' => 'configuration'])
            ->assertOk()
            ->assertSee('Google OAuth Client ID')
            ->assertSee('Google OAuth Client Secret')
            ->assertSee('Google Ads Developer Token')
            ->assertSee('Save credentials')
            ->assertSee('Test configuration')
            ->assertDontSee('Prompt 14')
            ->assertDontSee('productionization');
    }

    public function test_save_uses_canonical_service_and_never_renders_secret(): void
    {
        $component = Livewire::test(GoogleIntegrationPage::class, ['tab' => 'configuration']);
        $component->update(
            [['method' => 'saveGoogleConfiguration', 'params' => [], 'path' => '']],
            [
                'googleClientId' => 'test-google-client-id',
                'googleClientSecret' => self::SECRET,
                'googleDeveloperToken' => self::TOKEN,
            ],
        );
        $component
            ->assertHasNoErrors()
            ->assertSee('Google application credentials saved.')
            ->assertSet('googleClientSecret', '')
            ->assertSet('googleDeveloperToken', '');

        $html = $component->html();
        $this->assertStringNotContainsString(self::SECRET, $html);
        $this->assertStringNotContainsString(self::TOKEN, $html);
        $this->assertStringNotContainsString(self::SECRET, (string) json_encode($component->effects));
        $this->assertStringNotContainsString(self::SECRET, (string) json_encode($component->snapshot));
        $this->assertStringNotContainsString(self::TOKEN, (string) json_encode($component->effects));
        $this->assertStringNotContainsString(self::TOKEN, (string) json_encode($component->snapshot));

        $integration = CoreIntegration::query()->where('provider', ProviderRegistry::GOOGLE)->firstOrFail();
        $resolver = app(GoogleCredentialResolver::class);
        $this->assertTrue($resolver->isAppConfigured($integration->fresh(['providerCredential'])));
        $this->assertSame(self::SECRET, $resolver->clientSecret($integration->fresh(['providerCredential'])));

        $stored = DB::table('core_integration_credentials')
            ->where('integration_id', $integration->id)
            ->where('credential_type', CoreIntegrationCredential::TYPE_PROVIDER)
            ->value('encrypted_payload');
        $this->assertIsString($stored);
        $this->assertStringNotContainsString(self::SECRET, $stored);
        $this->assertStringNotContainsString(self::TOKEN, $stored);

        $this->assertSame('Configured', GoogleAuthStatus::applicationConfigurationLabel($integration->fresh(['providerCredential'])));
        $this->assertSame('Not authorized', GoogleAuthStatus::label(GoogleAuthStatus::for($integration->fresh(['authorizationCredential', 'providerCredential']))));

        Http::assertNothingSent();
    }

    public function test_blank_secret_does_not_erase_stored_value(): void
    {
        $integration = CoreIntegration::factory()->google()->create();
        app(GoogleProviderCredentialService::class)->save($integration, [
            'client_id' => 'keep-client',
            'client_secret' => self::SECRET,
            'developer_token' => self::TOKEN,
        ], $this->admin);

        Livewire::test(GoogleIntegrationPage::class, ['tab' => 'configuration'])
            ->update(
                [['method' => 'saveGoogleConfiguration', 'params' => [], 'path' => '']],
                [
                    'googleClientId' => 'keep-client',
                    'googleClientSecret' => '',
                    'googleDeveloperToken' => '',
                ],
            )
            ->assertHasNoErrors();

        $fresh = $integration->fresh(['providerCredential']);
        $this->assertSame(self::SECRET, app(GoogleCredentialResolver::class)->clientSecret($fresh));
        $this->assertSame(self::TOKEN, app(GoogleCredentialResolver::class)->developerToken($fresh));
    }

    public function test_unauthorized_user_cannot_save_or_see_fields(): void
    {
        $member = User::factory()->create();
        $member->assignRole(Roles::TEAM_MEMBER);
        $this->actingAs($member);

        Livewire::test(GoogleIntegrationPage::class, ['tab' => 'configuration'])
            ->assertOk()
            ->assertDontSee('Google OAuth Client Secret')
            ->assertDontSee('Save credentials')
            ->call('saveGoogleConfiguration')
            ->assertForbidden();
    }

    public function test_authorization_is_blocked_until_application_credentials_exist(): void
    {
        Livewire::test(GoogleIntegrationPage::class)
            ->call('bootstrapAndConnect')
            ->assertSee('Configure Google application first.')
            ->assertNoRedirect();

        Http::assertNothingSent();
    }

    public function test_authorization_uses_existing_oauth_route_after_configuration(): void
    {
        $integration = CoreIntegration::factory()->google()->create();
        app(GoogleProviderCredentialService::class)->save($integration, [
            'client_id' => 'oauth-client',
            'client_secret' => self::SECRET,
        ], $this->admin);

        Livewire::test(GoogleIntegrationPage::class)
            ->call('bootstrapAndConnect')
            ->assertRedirectContains('accounts.google.com');
    }

    public function test_test_configuration_is_truthful_without_oauth(): void
    {
        $integration = CoreIntegration::factory()->google()->create();
        app(GoogleProviderCredentialService::class)->save($integration, [
            'client_id' => 'oauth-client',
            'client_secret' => self::SECRET,
        ], $this->admin);

        Livewire::test(GoogleIntegrationPage::class, ['tab' => 'configuration'])
            ->call('testGoogleConfiguration')
            ->assertSee('Application credentials are configured. Authorization is still required.');

        Http::assertNothingSent();
    }

    public function test_hub_distinguishes_discovery_not_run_from_zero_resources(): void
    {
        Livewire::test(IntegrationsIndex::class)
            ->assertOk()
            ->assertSee('Not discovered yet')
            ->assertSee('Configure');

        $card = collect(app(OperatorIntegrationsHubQuery::class)->groups())
            ->flatMap(fn (array $group) => $group['providers'])
            ->firstWhere('id', ProviderRegistry::GOOGLE);

        $this->assertTrue($card['discovery_not_run'] ?? false);
        $this->assertSame('Configure', $card['manage_label']);
        Http::assertNothingSent();
    }
}
