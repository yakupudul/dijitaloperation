<?php

namespace Tests\Feature;

use App\Livewire\Demo\Integrations\MetaIntegrationPage;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\User;
use App\Services\Integrations\Meta\MetaCredentialResolver;
use App\Services\Integrations\Meta\MetaProviderCredentialService;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class OperatorMetaIntegrationConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private const string SECRET = 'meta-test-app-secret-002b';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        config([
            'moxdop.meta.app_id' => null,
            'moxdop.meta.app_secret' => null,
            'moxdop.meta.access_token' => null,
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);

        Http::fake();
    }

    public function test_app_configuration_tab_exists_on_operator_surface(): void
    {
        Livewire::test(MetaIntegrationPage::class, ['tab' => 'configuration'])
            ->assertOk()
            ->assertSee('Meta App ID')
            ->assertSee('Meta App Secret')
            ->assertSee('Save credentials')
            ->assertDontSee('Prompt 22')
            ->assertDontSee('Prompt 24')
            ->assertDontSee('Milestone status')
            ->assertDontSee('productionization');
    }

    public function test_save_uses_canonical_service_and_secret_is_write_only(): void
    {
        $component = Livewire::test(MetaIntegrationPage::class, ['tab' => 'configuration']);
        $component->update(
            [['method' => 'saveMetaConfiguration', 'params' => [], 'path' => '']],
            [
                'metaAppId' => '1234567890',
                'metaAppSecret' => self::SECRET,
            ],
        );
        $component
            ->assertHasNoErrors()
            ->assertSee('Meta application credentials saved.')
            ->assertSet('metaAppSecret', '');

        $this->assertStringNotContainsString(self::SECRET, $component->html());

        $integration = CoreIntegration::query()->where('provider', ProviderRegistry::META)->firstOrFail();
        $fresh = $integration->fresh(['providerCredential']);
        $resolver = app(MetaCredentialResolver::class);
        $this->assertTrue($resolver->isApplicationConfigured($fresh));
        $this->assertSame(self::SECRET, $resolver->appSecret($fresh));
        $this->assertArrayNotHasKey('app_secret', is_array($fresh->config) ? $fresh->config : []);

        $stored = DB::table('core_integration_credentials')
            ->where('integration_id', $integration->id)
            ->where('credential_type', CoreIntegrationCredential::TYPE_PROVIDER)
            ->value('encrypted_payload');
        $this->assertIsString($stored);
        $this->assertStringNotContainsString(self::SECRET, $stored);

        Http::assertNothingSent();
    }

    public function test_oauth_is_blocked_until_application_is_configured(): void
    {
        Livewire::test(MetaIntegrationPage::class)
            ->call('bootstrapAndConnect')
            ->assertSee('Configure Meta application first.')
            ->assertNoRedirect();

        Http::assertNothingSent();
    }

    public function test_oauth_uses_canonical_route_after_configuration(): void
    {
        $integration = CoreIntegration::factory()->meta()->create();
        app(MetaProviderCredentialService::class)->save($integration, [
            'app_id' => '1234567890',
            'app_secret' => self::SECRET,
        ], $this->admin);

        Livewire::test(MetaIntegrationPage::class)
            ->call('bootstrapAndConnect')
            ->assertRedirect();
    }

    public function test_unauthorized_user_cannot_save(): void
    {
        $member = User::factory()->create();
        $member->assignRole(Roles::TEAM_MEMBER);
        $this->actingAs($member);

        Livewire::test(MetaIntegrationPage::class, ['tab' => 'configuration'])
            ->assertDontSee('Meta App Secret')
            ->call('saveMetaConfiguration')
            ->assertForbidden();
    }

    public function test_blank_secret_keeps_stored_app_secret(): void
    {
        $integration = CoreIntegration::factory()->meta()->create();
        app(MetaProviderCredentialService::class)->save($integration, [
            'app_id' => '1234567890',
            'app_secret' => self::SECRET,
        ], $this->admin);

        Livewire::test(MetaIntegrationPage::class, ['tab' => 'configuration'])
            ->update(
                [['method' => 'saveMetaConfiguration', 'params' => [], 'path' => '']],
                [
                    'metaAppId' => '1234567890',
                    'metaAppSecret' => '',
                ],
            )
            ->assertHasNoErrors();

        $this->assertSame(
            self::SECRET,
            app(MetaCredentialResolver::class)->appSecret($integration->fresh(['providerCredential'])),
        );
    }
}
