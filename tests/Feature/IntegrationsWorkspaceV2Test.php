<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Integrations\IntegrationResource;
use App\Filament\App\Resources\Integrations\Pages\ListIntegrations;
use App\Filament\App\Resources\Integrations\Pages\ViewIntegration;
use App\Filament\App\Resources\Integrations\RelationManagers\ExternalResourcesRelationManager;
use App\Models\CoreIntegration;
use App\Models\User;
use App\Services\Integrations\Google\GoogleProviderCredentialService;
use App\Services\Integrations\OpenAi\OpenAiCredentialResolver;
use App\Services\Integrations\OpenAi\OpenAiProviderCredentialService;
use App\Support\Integrations\Presentation\IntegrationHealthPresenter;
use App\Support\Integrations\Presentation\IntegrationOperatorStatus;
use App\Support\Integrations\Presentation\IntegrationPresentationRegistry;
use App\Support\Integrations\Presentation\IntegrationWorkspaceCatalog;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class IntegrationsWorkspaceV2Test extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel('app');

        config([
            'moxdop.openai.api_key' => null,
            'ai.providers.openai.key' => null,
            'moxdop.openai.recommendation_model' => 'gpt-5-mini',
            'moxdop.google.client_id' => null,
            'moxdop.google.client_secret' => null,
            'moxdop.google.ads_developer_token' => null,
        ]);
    }

    public function test_hub_shows_operator_ready_provider_cards_without_generic_table(): void
    {
        Http::fake();

        Livewire::test(ListIntegrations::class)
            ->assertOk()
            ->assertSee('Connect and manage the services MoxDOP uses')
            ->assertSee('Data & platforms')
            ->assertSee('AI providers')
            ->assertSee('Google')
            ->assertSee('DataForSEO')
            ->assertSee('OpenAI')
            ->assertSee('Analytics, search and advertising data')
            ->assertSee('External SEO and keyword intelligence')
            ->assertSee('AI reasoning and recommendation intelligence')
            ->assertSee('Anthropic')
            ->assertSee('Gemini')
            ->assertSee('Set up')
            ->assertDontSee('Add integration')
            ->assertDontSee('Authorized')
            ->assertDontSeeHtml('fi-ta-table')
            ->assertDontSee('Meta');

        Http::assertNothingSent();
    }

    public function test_unconfigured_provider_appears_and_setup_bootstraps_record(): void
    {
        $this->assertSame(0, CoreIntegration::query()->where('provider', ProviderRegistry::GOOGLE)->count());

        Livewire::test(ListIntegrations::class)
            ->call('setupProvider', ProviderRegistry::GOOGLE)
            ->assertRedirect();

        $integration = CoreIntegration::query()->where('provider', ProviderRegistry::GOOGLE)->firstOrFail();
        $this->assertSame('Google', $integration->name);
        $this->assertSame(CoreIntegration::STATUS_ACTIVE, $integration->status);

        Livewire::test(ListIntegrations::class)
            ->assertSee('Manage')
            ->assertDontSeeHtml('wire:click="setupProvider(\'google\')"');
    }

    public function test_configured_openai_shows_manage_and_connected_semantics(): void
    {
        $integration = CoreIntegration::factory()->openai()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => [
                'connection_status' => 'connected',
                'last_tested_at' => now()->toIso8601String(),
            ],
            'last_success_at' => now(),
            'last_error' => null,
        ]);
        app(OpenAiProviderCredentialService::class)->save($integration, [
            'api_key' => 'sk-hub-secret',
        ], $this->admin);

        $hub = app(IntegrationWorkspaceCatalog::class)->hub();
        $openai = collect($hub['groups'])->flatMap(fn (array $g) => $g['cards'])
            ->firstWhere('provider', ProviderRegistry::OPENAI);

        $this->assertSame(IntegrationOperatorStatus::CONNECTED, $openai['status']);
        $this->assertSame('manage', $openai['action']);
        $this->assertStringContainsString('Available for AI routes', implode(' ', $openai['summary_lines']));

        Livewire::test(ListIntegrations::class)
            ->assertSee('Connected')
            ->assertSee('Manage')
            ->assertDontSee('sk-hub-secret')
            ->assertDontSee('Key ID');
    }

    public function test_failed_openai_auth_maps_to_needs_attention(): void
    {
        $integration = CoreIntegration::factory()->openai()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => [
                'connection_status' => 'issue',
                'last_tested_at' => now()->toIso8601String(),
            ],
            'last_error' => 'Unauthorized',
        ]);
        app(OpenAiProviderCredentialService::class)->save($integration, [
            'api_key' => 'sk-bad',
        ], $this->admin);

        $status = app(IntegrationHealthPresenter::class)->status(
            $integration->fresh(['providerCredential']),
            ProviderRegistry::OPENAI,
        );

        $this->assertSame(IntegrationOperatorStatus::NEEDS_ATTENTION, $status);
    }

    public function test_configured_but_untested_maps_to_configured(): void
    {
        $integration = CoreIntegration::factory()->openai()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => [],
            'last_error' => null,
        ]);
        app(OpenAiProviderCredentialService::class)->save($integration, [
            'api_key' => 'sk-untested',
        ], $this->admin);

        $status = app(IntegrationHealthPresenter::class)->status(
            $integration->fresh(['providerCredential']),
            ProviderRegistry::OPENAI,
        );

        $this->assertSame(IntegrationOperatorStatus::CONFIGURED, $status);
    }

    public function test_index_render_performs_zero_provider_http_calls(): void
    {
        CoreIntegration::factory()->openai()->create();
        CoreIntegration::factory()->dataforseo()->create();
        CoreIntegration::factory()->google()->create();

        Http::fake();

        Livewire::test(ListIntegrations::class)->assertOk();
        app(IntegrationWorkspaceCatalog::class)->hub();

        Http::assertNothingSent();
    }

    public function test_openai_configure_enables_test_connection_without_manual_refresh(): void
    {
        $integration = CoreIntegration::factory()->openai()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
        ]);

        $component = Livewire::test(ViewIntegration::class, [
            'record' => $integration->getRouteKey(),
        ]);

        $this->assertTrue($this->headerAction($component, 'testOpenAi')->isDisabled());

        $component
            ->callAction('configureOpenAi', data: [
                'api_key' => 'sk-immediate',
                'clear_api_key' => false,
            ])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertFalse($this->headerAction($component, 'testOpenAi')->isDisabled());
        $this->assertTrue(
            app(OpenAiCredentialResolver::class)
                ->isConfigured($integration->fresh(['providerCredential'])),
        );
    }

    public function test_google_configure_enables_authorize_without_manual_refresh(): void
    {
        $integration = CoreIntegration::factory()->google()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
        ]);

        $component = Livewire::test(ViewIntegration::class, [
            'record' => $integration->getRouteKey(),
        ]);

        $this->assertTrue($this->headerAction($component, 'authorizeGoogle')->isDisabled());

        $component
            ->callAction('configureGoogleApplication', data: [
                'client_id' => 'ui-client-id',
                'client_secret' => 'ui-client-secret',
                'developer_token' => 'ui-dev-token',
                'clear_client_secret' => false,
                'clear_developer_token' => false,
            ])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertFalse($this->headerAction($component, 'authorizeGoogle')->isDisabled());
    }

    public function test_openai_workspace_has_no_external_resources_or_key_id(): void
    {
        $integration = CoreIntegration::factory()->openai()->create();
        app(OpenAiProviderCredentialService::class)->save($integration, [
            'api_key' => 'sk-view-secret',
        ], $this->admin);

        $this->assertFalse(
            ExternalResourcesRelationManager::canViewForRecord($integration, ViewIntegration::class),
        );

        Livewire::test(ViewIntegration::class, ['record' => $integration->getRouteKey()])
            ->assertOk()
            ->assertSee('API Key')
            ->assertSee('Stored securely ✓')
            ->assertSee('AI Control Plane')
            ->assertSee('available to MoxDOP AI routes')
            ->assertDontSee('Current recommendation model')
            ->assertDontSee('sk-view-secret')
            ->assertDontSee('Key ID')
            ->assertDontSee('Client ID')
            ->assertDontSee('Client Secret')
            ->assertDontSee('Authorize Google')
            ->assertDontSee('Google resources')
            ->assertDontSee('Credentials JSON')
            ->assertSee('Danger zone');
    }

    public function test_dataforseo_workspace_has_no_external_resources_or_oauth(): void
    {
        $integration = CoreIntegration::factory()->dataforseo()->create([
            'config' => [
                'connection_status' => 'connected',
                'account_login' => 'dfs-login@example.com',
                'balance' => 12.5,
            ],
        ]);

        $this->assertFalse(
            ExternalResourcesRelationManager::canViewForRecord($integration, ViewIntegration::class),
        );

        Livewire::test(ViewIntegration::class, ['record' => $integration->getRouteKey()])
            ->assertOk()
            ->assertSee('API Login')
            ->assertSee('API Password')
            ->assertDontSee('Authorize Google')
            ->assertDontSee('Refresh resources')
            ->assertDontSee('Google resources')
            ->assertDontSee('OAuth');
    }

    public function test_google_workspace_keeps_resources_and_oauth_actions(): void
    {
        $integration = CoreIntegration::factory()->google()->create();
        app(GoogleProviderCredentialService::class)->save($integration, [
            'client_id' => 'visible-client',
            'client_secret' => 'secret-never-show',
            'developer_token' => 'dev-never-show',
        ], $this->admin);

        $this->assertTrue(
            ExternalResourcesRelationManager::canViewForRecord($integration, ViewIntegration::class),
        );

        Livewire::test(ViewIntegration::class, ['record' => $integration->getRouteKey()])
            ->assertOk()
            ->assertSee('Available services')
            ->assertSee('Authorize Google')
            ->assertSee('Test connection')
            ->assertSee('Refresh resources')
            ->assertSee('Danger zone')
            ->assertSee('visible-client')
            ->assertDontSee('secret-never-show')
            ->assertDontSee('dev-never-show');
    }

    public function test_meta_is_not_operator_ready_in_presentation_registry(): void
    {
        $this->assertTrue(ProviderRegistry::isValid(ProviderRegistry::META));
        $this->assertFalse(IntegrationPresentationRegistry::isOperatorReady(ProviderRegistry::META));
        $this->assertFalse(IntegrationResource::canCreate());
    }

    public function test_card_view_models_never_contain_secrets(): void
    {
        $integration = CoreIntegration::factory()->openai()->create();
        app(OpenAiProviderCredentialService::class)->save($integration, [
            'api_key' => 'sk-should-not-leak',
        ], $this->admin);

        $payload = json_encode(app(IntegrationWorkspaceCatalog::class)->hub());
        $this->assertIsString($payload);
        $this->assertStringNotContainsString('sk-should-not-leak', $payload);
        $this->assertStringNotContainsString('encrypted_payload', $payload);
    }

    /**
     * @param  Testable  $component
     */
    private function headerAction(mixed $component, string $name): Action
    {
        $actions = $component->instance()->getCachedHeaderActions();
        foreach ($actions as $action) {
            if ($action instanceof ActionGroup) {
                foreach ($action->getActions() as $grouped) {
                    if ($grouped->getName() === $name) {
                        return $grouped;
                    }
                }

                continue;
            }

            if ($action->getName() === $name) {
                return $action;
            }
        }

        $this->fail('Header action not found: '.$name);
    }
}
