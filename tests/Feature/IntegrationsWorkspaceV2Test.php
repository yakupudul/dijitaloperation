<?php

namespace Tests\Feature;

use App\Models\CoreIntegration;
use App\Models\User;
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
use Livewire\Features\SupportTesting\Testable;
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

    public function test_meta_is_operator_ready_in_presentation_registry(): void
    {
        $this->assertTrue(ProviderRegistry::isValid(ProviderRegistry::META));
        $this->assertTrue(IntegrationPresentationRegistry::isOperatorReady(ProviderRegistry::META));
        $meta = IntegrationPresentationRegistry::for(ProviderRegistry::META);
        $this->assertNotNull($meta);
        $this->assertTrue($meta['supports_resources']);
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
