<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Integrations\Pages\ViewIntegration;
use App\Filament\App\Resources\Integrations\RelationManagers\ExternalResourcesRelationManager;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\User;
use App\Services\Integrations\OpenAi\OpenAiConnectionService;
use App\Services\Integrations\OpenAi\OpenAiCredentialResolver;
use App\Services\Integrations\OpenAi\OpenAiProviderCredentialService;
use App\Services\Integrations\OpenAi\OpenAiRuntimeConfig;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\TestCase;

class OpenAiCentralIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CoreIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        config([
            'moxdop.openai.api_key' => null,
            'ai.providers.openai.key' => null,
            'moxdop.openai.base_url' => 'https://api.openai.com/v1',
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel('app');

        $this->integration = CoreIntegration::factory()->openai()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
        ]);
    }

    public function test_api_key_encrypted_at_rest_and_hidden_from_array(): void
    {
        app(OpenAiProviderCredentialService::class)->save($this->integration, [
            'api_key' => 'sk-test-secret-key',
        ], $this->admin);

        $credential = CoreIntegrationCredential::query()
            ->where('integration_id', $this->integration->id)
            ->where('credential_type', CoreIntegrationCredential::TYPE_PROVIDER)
            ->firstOrFail();

        $stored = DB::table('core_integration_credentials')->where('id', $credential->id)->value('encrypted_payload');
        $this->assertIsString($stored);
        $this->assertStringNotContainsString('sk-test-secret-key', $stored);
        $this->assertSame('sk-test-secret-key', $credential->encrypted_payload['api_key']);
        $this->assertArrayNotHasKey('encrypted_payload', $credential->toArray());
    }

    public function test_blank_edit_preserves_and_clear_removes(): void
    {
        $service = app(OpenAiProviderCredentialService::class);
        $service->save($this->integration, [
            'api_key' => 'sk-original',
        ], $this->admin);

        $service->save($this->integration->fresh(['providerCredential']), [
            'api_key' => '',
        ], $this->admin);

        $this->assertSame(
            'sk-original',
            app(OpenAiCredentialResolver::class)->apiKey($this->integration->fresh(['providerCredential'])),
        );

        $service->save($this->integration->fresh(['providerCredential']), [
            'api_key' => '',
            'clear_api_key' => true,
        ], $this->admin);

        $this->assertFalse(
            app(OpenAiCredentialResolver::class)->hasDatabaseApiKey($this->integration->fresh(['providerCredential'])),
        );
    }

    public function test_resolver_prefers_database_over_environment(): void
    {
        config([
            'moxdop.openai.api_key' => 'sk-env',
            'ai.providers.openai.key' => 'sk-env',
        ]);

        app(OpenAiProviderCredentialService::class)->save($this->integration, [
            'api_key' => 'sk-db',
        ], $this->admin);

        $resolver = app(OpenAiCredentialResolver::class);
        $fresh = $this->integration->fresh(['providerCredential']);

        $this->assertSame('sk-db', $resolver->apiKey($fresh));
        $this->assertSame(OpenAiCredentialResolver::SOURCE_DATABASE, $resolver->apiKeySource($fresh));
    }

    public function test_env_fallback_when_database_missing(): void
    {
        config([
            'moxdop.openai.api_key' => 'sk-env-only',
            'ai.providers.openai.key' => 'sk-env-only',
        ]);

        $resolver = app(OpenAiCredentialResolver::class);
        $this->assertSame('sk-env-only', $resolver->apiKey($this->integration));
        $this->assertSame(OpenAiCredentialResolver::SOURCE_ENVIRONMENT, $resolver->apiKeySource($this->integration));
    }

    public function test_connection_uses_models_list_not_completions(): void
    {
        app(OpenAiProviderCredentialService::class)->save($this->integration, [
            'api_key' => 'sk-test',
        ], $this->admin);

        Http::fake([
            'api.openai.com/v1/models' => Http::response([
                'data' => [
                    ['id' => 'gpt-4.1-mini'],
                    ['id' => 'gpt-4.1'],
                ],
            ], 200),
            '*' => Http::response(['error' => 'unexpected'], 500),
        ]);

        $result = app(OpenAiConnectionService::class)->testConnection(
            $this->integration->fresh(['providerCredential']),
        );

        $this->assertTrue($result['ok']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/models')
            && $request->method() === 'GET'
            && ! str_contains($request->url(), '/responses')
            && ! str_contains($request->url(), '/chat/completions'));

        $fresh = $this->integration->fresh();
        $this->assertSame('connected', data_get($fresh->config, 'connection_status'));
        $this->assertSame(2, data_get($fresh->config, 'models_visible_count'));
        $this->assertNull(data_get($fresh->config, 'models_raw'));
    }

    public function test_api_key_not_logged_on_connection_failure(): void
    {
        app(OpenAiProviderCredentialService::class)->save($this->integration, [
            'api_key' => 'sk-should-not-log',
        ], $this->admin);

        Http::fake([
            'api.openai.com/v1/models' => Http::response(['error' => ['message' => 'nope']], 401),
        ]);

        Log::spy();

        app(OpenAiConnectionService::class)->testConnection(
            $this->integration->fresh(['providerCredential']),
        );

        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning', function (...$args): bool {
            $payload = json_encode($args);

            return is_string($payload) && str_contains($payload, 'sk-should-not-log');
        });
    }

    public function test_runtime_config_sets_store_false_and_injects_key(): void
    {
        app(OpenAiProviderCredentialService::class)->save($this->integration, [
            'api_key' => 'sk-runtime',
        ], $this->admin);

        config([
            'ai.providers.openai.store' => true,
            'ai.providers.openai.key' => null,
        ]);

        $prepared = app(OpenAiRuntimeConfig::class)->prepare();

        $this->assertTrue($prepared['configured']);
        $this->assertSame('sk-runtime', config('ai.providers.openai.key'));
        $this->assertFalse((bool) config('ai.providers.openai.store'));
        $this->assertSame('gpt-5-mini', app(OpenAiRuntimeConfig::class)->recommendationModel());
    }

    public function test_view_integration_shows_stored_securely_not_plaintext_key(): void
    {
        app(OpenAiProviderCredentialService::class)->save($this->integration, [
            'api_key' => 'sk-ui-secret',
        ], $this->admin);

        Livewire::test(ViewIntegration::class, [
            'record' => $this->integration->id,
        ])
            ->assertOk()
            ->assertSee('Stored securely ✓')
            ->assertDontSee('sk-ui-secret')
            ->assertDontSee('Credentials JSON')
            ->assertDontSee('Authorize Google')
            ->assertDontSee('Refresh resources')
            ->assertDontSee('No external resources discovered yet');
    }

    public function test_external_resources_relation_hidden_for_openai(): void
    {
        $this->assertFalse(
            ExternalResourcesRelationManager::canViewForRecord(
                $this->integration,
                ViewIntegration::class,
            ),
        );
    }

    public function test_non_admin_cannot_save_openai_credentials(): void
    {
        $member = User::factory()->create();
        $member->assignRole(Roles::TEAM_MEMBER);
        $this->actingAs($member);

        $this->expectException(\RuntimeException::class);

        app(OpenAiProviderCredentialService::class)->save($this->integration, [
            'api_key' => 'sk-nope',
        ], $member);
    }
}
