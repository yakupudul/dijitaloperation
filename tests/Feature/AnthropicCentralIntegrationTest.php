<?php

namespace Tests\Feature;

use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\User;
use App\Services\Integrations\Anthropic\AnthropicConnectionService;
use App\Services\Integrations\Anthropic\AnthropicCredentialResolver;
use App\Services\Integrations\Anthropic\AnthropicProviderCredentialService;
use App\Support\Integrations\Presentation\IntegrationOperatorStatus;
use App\Support\Integrations\Presentation\IntegrationWorkspaceCatalog;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnthropicCentralIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CoreIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        config([
            'moxdop.anthropic.api_key' => null,
            'ai.providers.anthropic.key' => null,
            'moxdop.anthropic.base_url' => 'https://api.anthropic.com/v1',
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel('app');

        $this->integration = CoreIntegration::factory()->anthropic()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
        ]);
    }

    public function test_provider_appears_in_integrations_hub(): void
    {
        $hub = app(IntegrationWorkspaceCatalog::class)->hub();
        $card = collect($hub['groups'])->flatMap(fn (array $g) => $g['cards'])
            ->firstWhere('provider', ProviderRegistry::ANTHROPIC);

        $this->assertNotNull($card);
        $this->assertSame(IntegrationOperatorStatus::NOT_CONFIGURED, $card['status']);
        $this->assertContains($card['action'], ['setup', 'manage']);
    }

    public function test_api_key_encrypted_and_hidden(): void
    {
        app(AnthropicProviderCredentialService::class)->save($this->integration, [
            'api_key' => 'sk-ant-test-secret',
        ], $this->admin);

        $credential = CoreIntegrationCredential::query()
            ->where('integration_id', $this->integration->id)
            ->firstOrFail();

        $stored = DB::table('core_integration_credentials')->where('id', $credential->id)->value('encrypted_payload');
        $this->assertStringNotContainsString('sk-ant-test-secret', (string) $stored);
        $this->assertSame('sk-ant-test-secret', $credential->encrypted_payload['api_key']);
        $this->assertArrayNotHasKey('encrypted_payload', $credential->toArray());
    }

    public function test_env_fallback_and_non_generative_test_connection(): void
    {
        config(['moxdop.anthropic.api_key' => 'sk-ant-env']);

        $this->assertTrue(app(AnthropicCredentialResolver::class)->isConfigured($this->integration));
        $this->assertSame(
            AnthropicCredentialResolver::SOURCE_ENVIRONMENT,
            app(AnthropicCredentialResolver::class)->apiKeySource($this->integration),
        );

        Http::fake([
            'api.anthropic.com/v1/models' => Http::response(['data' => [['id' => 'claude-sonnet-5']]], 200),
        ]);

        $result = app(AnthropicConnectionService::class)->testConnection($this->integration->fresh());
        $this->assertTrue($result['ok']);
        $this->assertSame('Connected', $result['message']);
        $this->assertSame('connected', data_get($this->integration->fresh()->config, 'connection_status'));

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.anthropic.com/v1/models'
                && $request->hasHeader('x-api-key', 'sk-ant-env')
                && $request->hasHeader('anthropic-version')
                && $request->method() === 'GET';
        });
    }
}
