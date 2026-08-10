<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Integrations\Pages\ListIntegrations;
use App\Filament\App\Resources\Integrations\Pages\ViewIntegration;
use App\Filament\App\Resources\Integrations\RelationManagers\ExternalResourcesRelationManager;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\User;
use App\Services\Integrations\Gemini\GeminiConnectionService;
use App\Services\Integrations\Gemini\GeminiCredentialResolver;
use App\Services\Integrations\Gemini\GeminiProviderCredentialService;
use App\Support\Integrations\Presentation\IntegrationOperatorStatus;
use App\Support\Integrations\Presentation\IntegrationWorkspaceCatalog;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class GeminiCentralIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CoreIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        config([
            'moxdop.gemini.api_key' => null,
            'ai.providers.gemini.key' => null,
            'moxdop.gemini.base_url' => 'https://generativelanguage.googleapis.com/v1beta',
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel('app');

        $this->integration = CoreIntegration::factory()->gemini()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
        ]);
    }

    public function test_provider_appears_in_integrations_hub(): void
    {
        $hub = app(IntegrationWorkspaceCatalog::class)->hub();
        $card = collect($hub['groups'])->flatMap(fn (array $g) => $g['cards'])
            ->firstWhere('provider', ProviderRegistry::GEMINI);

        $this->assertNotNull($card);
        $this->assertSame(IntegrationOperatorStatus::NOT_CONFIGURED, $card['status']);
    }

    public function test_api_key_encrypted_and_env_fallback(): void
    {
        app(GeminiProviderCredentialService::class)->save($this->integration, [
            'api_key' => 'gem-test-secret',
        ], $this->admin);

        $credential = CoreIntegrationCredential::query()
            ->where('integration_id', $this->integration->id)
            ->firstOrFail();
        $stored = DB::table('core_integration_credentials')->where('id', $credential->id)->value('encrypted_payload');
        $this->assertStringNotContainsString('gem-test-secret', (string) $stored);

        config(['moxdop.gemini.api_key' => 'gem-env-only']);
        $this->assertSame(
            GeminiCredentialResolver::SOURCE_ENVIRONMENT,
            app(GeminiCredentialResolver::class)->apiKeySource(
                CoreIntegration::factory()->gemini()->make()
            ),
        );

        // Clear DB key so env wins on the existing integration after remove.
        app(GeminiProviderCredentialService::class)->remove($this->integration->fresh(['providerCredential']), $this->admin);
        $this->assertSame(
            GeminiCredentialResolver::SOURCE_ENVIRONMENT,
            app(GeminiCredentialResolver::class)->apiKeySource($this->integration->fresh(['providerCredential'])),
        );
    }

    public function test_non_generative_models_list_uses_header_not_query_string(): void
    {
        app(GeminiProviderCredentialService::class)->save($this->integration, [
            'api_key' => 'gem-header-key',
        ], $this->admin);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'models' => [['name' => 'models/gemini-3.6-flash']],
            ], 200),
        ]);

        $result = app(GeminiConnectionService::class)->testConnection($this->integration->fresh(['providerCredential']));
        $this->assertTrue($result['ok']);
        $this->assertSame('Connected', $result['message']);

        Http::assertSent(function ($request): bool {
            $url = $request->url();

            return str_contains($url, '/models')
                && ! str_contains($url, 'gem-header-key')
                && ! str_contains($url, 'key=')
                && $request->hasHeader('x-goog-api-key', 'gem-header-key')
                && $request->method() === 'GET';
        });
    }

    public function test_auth_failure_and_secret_absent_from_ui(): void
    {
        app(GeminiProviderCredentialService::class)->save($this->integration, [
            'api_key' => 'gem-bad',
        ], $this->admin);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'denied'], 403),
        ]);

        $result = app(GeminiConnectionService::class)->testConnection($this->integration->fresh(['providerCredential']));
        $this->assertFalse($result['ok']);

        Livewire::test(ViewIntegration::class, ['record' => $this->integration->fresh()->getRouteKey()])
            ->assertOk()
            ->assertSee('Stored securely ✓')
            ->assertSee('separate from Google OAuth')
            ->assertDontSee('gem-bad')
            ->assertDontSee('Authorize Google');

        $this->assertFalse(
            ExternalResourcesRelationManager::canViewForRecord($this->integration->fresh(), ViewIntegration::class),
        );
    }

    public function test_hub_lists_gemini_card(): void
    {
        Livewire::test(ListIntegrations::class)
            ->assertOk()
            ->assertSee('Gemini')
            ->assertSee('Google AI reasoning and multimodal intelligence');
    }
}
