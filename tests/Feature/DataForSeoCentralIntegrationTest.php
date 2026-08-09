<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Integrations\IntegrationResource;
use App\Filament\App\Resources\Integrations\Pages\ViewIntegration;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\User;
use App\Services\Integrations\DataForSeo\DataForSeoAccountService;
use App\Services\Integrations\DataForSeo\DataForSeoApiClient;
use App\Services\Integrations\DataForSeo\DataForSeoCredentialResolver;
use App\Services\Integrations\DataForSeo\DataForSeoEndpointAllowlist;
use App\Services\Integrations\DataForSeo\DataForSeoException;
use App\Services\Integrations\DataForSeo\DataForSeoProviderCredentialService;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\TestCase;

class DataForSeoCentralIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CoreIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        config([
            'moxdop.dataforseo.login' => null,
            'moxdop.dataforseo.password' => null,
            'moxdop.dataforseo.base_url' => 'https://api.dataforseo.com',
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel('app');

        $this->integration = CoreIntegration::factory()->dataforseo()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
        ]);
    }

    public function test_provider_password_encrypted_at_rest(): void
    {
        app(DataForSeoProviderCredentialService::class)->save($this->integration, [
            'login' => 'agency@example.com',
            'password' => 'dfs-secret-password',
        ], $this->admin);

        $credential = CoreIntegrationCredential::query()
            ->where('integration_id', $this->integration->id)
            ->where('credential_type', CoreIntegrationCredential::TYPE_PROVIDER)
            ->firstOrFail();

        $stored = DB::table('core_integration_credentials')->where('id', $credential->id)->value('encrypted_payload');
        $this->assertIsString($stored);
        $this->assertStringNotContainsString('dfs-secret-password', $stored);
        $this->assertSame('dfs-secret-password', $credential->encrypted_payload['password']);
        $this->assertArrayNotHasKey('encrypted_payload', $credential->toArray());
    }

    public function test_blank_password_preserves_existing_and_clear_removes_it(): void
    {
        $service = app(DataForSeoProviderCredentialService::class);
        $service->save($this->integration, [
            'login' => 'agency@example.com',
            'password' => 'original-secret',
        ], $this->admin);

        $service->save($this->integration->fresh(['providerCredential']), [
            'login' => 'agency@example.com',
            'password' => '',
        ], $this->admin);

        $this->assertSame(
            'original-secret',
            app(DataForSeoCredentialResolver::class)->password($this->integration->fresh(['providerCredential'])),
        );

        $service->save($this->integration->fresh(['providerCredential']), [
            'login' => 'agency@example.com',
            'password' => '',
            'clear_password' => true,
        ], $this->admin);

        $this->assertFalse(
            app(DataForSeoCredentialResolver::class)->hasDatabasePassword($this->integration->fresh(['providerCredential'])),
        );
    }

    public function test_resolver_prefers_database_over_environment(): void
    {
        config([
            'moxdop.dataforseo.login' => 'env-login',
            'moxdop.dataforseo.password' => 'env-password',
        ]);

        app(DataForSeoProviderCredentialService::class)->save($this->integration, [
            'login' => 'db-login',
            'password' => 'db-password',
        ], $this->admin);

        $resolver = app(DataForSeoCredentialResolver::class);
        $fresh = $this->integration->fresh(['providerCredential']);

        $this->assertSame('db-login', $resolver->login($fresh));
        $this->assertSame('db-password', $resolver->password($fresh));
        $this->assertSame(DataForSeoCredentialResolver::SOURCE_DATABASE, $resolver->loginSource($fresh));
    }

    public function test_resolver_falls_back_to_environment(): void
    {
        config([
            'moxdop.dataforseo.login' => 'env-login',
            'moxdop.dataforseo.password' => 'env-password',
        ]);

        $resolver = app(DataForSeoCredentialResolver::class);

        $this->assertSame('env-login', $resolver->login($this->integration));
        $this->assertSame('env-password', $resolver->password($this->integration));
        $this->assertSame(DataForSeoCredentialResolver::SOURCE_ENVIRONMENT, $resolver->passwordSource($this->integration));
        $this->assertTrue($resolver->isConfigured($this->integration));
    }

    public function test_view_workspace_hides_password_and_shows_stored_securely(): void
    {
        app(DataForSeoProviderCredentialService::class)->save($this->integration, [
            'login' => 'agency@example.com',
            'password' => 'dfs-secret-password',
        ], $this->admin);

        Livewire::test(ViewIntegration::class, ['record' => $this->integration->id])
            ->assertSuccessful()
            ->assertSee('agency@example.com')
            ->assertSee('Stored securely ✓')
            ->assertDontSee('dfs-secret-password')
            ->assertDontSee('Credentials JSON')
            ->assertSee('Test connection')
            ->assertSee('Configure')
            ->assertSee('Remove provider configuration');
    }

    public function test_generic_persist_credentials_skipped_for_dataforseo(): void
    {
        IntegrationResource::persistCredentials($this->integration, [
            'credentials_json' => json_encode([
                'login' => 'should-not-persist',
                'password' => 'should-not-persist-secret',
            ]),
        ]);

        $this->assertNull($this->integration->fresh()->providerCredential);
    }

    public function test_test_connection_success_updates_health_without_raw_dump(): void
    {
        app(DataForSeoProviderCredentialService::class)->save($this->integration, [
            'login' => 'agency@example.com',
            'password' => 'dfs-secret-password',
        ], $this->admin);

        Http::fake([
            'https://api.dataforseo.com/v3/appendix/user_data' => Http::response($this->userDataFixture(), 200),
        ]);

        $result = app(DataForSeoAccountService::class)->testConnection(
            $this->integration->fresh(['providerCredential']),
        );

        $this->assertTrue($result['ok']);
        $fresh = $this->integration->fresh();
        $this->assertSame('connected', data_get($fresh->config, 'connection_status'));
        $this->assertSame('agency@example.com', data_get($fresh->config, 'account_login'));
        $this->assertSame('Europe/London', data_get($fresh->config, 'timezone'));
        $this->assertSame(12.5, data_get($fresh->config, 'balance'));
        $this->assertNotNull($fresh->last_success_at);
        $this->assertNull($fresh->last_error);
        $this->assertArrayNotHasKey('user_data_raw', $fresh->config ?? []);
        $this->assertArrayNotHasKey('rates', $fresh->config ?? []);
        $this->assertStringNotContainsString('dfs-secret-password', json_encode($fresh->config));
    }

    public function test_test_connection_http_401(): void
    {
        app(DataForSeoProviderCredentialService::class)->save($this->integration, [
            'login' => 'agency@example.com',
            'password' => 'bad-password',
        ], $this->admin);

        Http::fake([
            'https://api.dataforseo.com/v3/appendix/user_data' => Http::response(['status_code' => 40100], 401),
        ]);

        $result = app(DataForSeoAccountService::class)->testConnection(
            $this->integration->fresh(['providerCredential']),
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('DataForSEO credentials were rejected.', $result['message']);
        $this->assertSame('issue', data_get($this->integration->fresh()->config, 'connection_status'));
    }

    public function test_test_connection_http_402_billing(): void
    {
        app(DataForSeoProviderCredentialService::class)->save($this->integration, [
            'login' => 'agency@example.com',
            'password' => 'dfs-secret-password',
        ], $this->admin);

        Http::fake([
            'https://api.dataforseo.com/v3/appendix/user_data' => Http::response(['message' => 'Payment Required'], 402),
        ]);

        $result = app(DataForSeoAccountService::class)->testConnection(
            $this->integration->fresh(['providerCredential']),
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('DataForSEO account has a billing/balance issue.', $result['message']);
    }

    public function test_test_connection_http_500(): void
    {
        app(DataForSeoProviderCredentialService::class)->save($this->integration, [
            'login' => 'agency@example.com',
            'password' => 'dfs-secret-password',
        ], $this->admin);

        Http::fake([
            'https://api.dataforseo.com/v3/appendix/user_data' => Http::sequence()
                ->push(['error' => 'boom'], 500)
                ->push(['error' => 'boom'], 500),
        ]);

        $result = app(DataForSeoAccountService::class)->testConnection(
            $this->integration->fresh(['providerCredential']),
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('DataForSEO is temporarily unavailable.', $result['message']);
    }

    public function test_http_200_with_internal_provider_failure_is_failure(): void
    {
        app(DataForSeoProviderCredentialService::class)->save($this->integration, [
            'login' => 'agency@example.com',
            'password' => 'dfs-secret-password',
        ], $this->admin);

        Http::fake([
            'https://api.dataforseo.com/v3/appendix/user_data' => Http::response([
                'version' => '0.1.20260101',
                'status_code' => 40100,
                'status_message' => 'You are not authorized to access this resource.',
                'time' => '0.01 sec.',
                'cost' => 0,
                'tasks_count' => 1,
                'tasks_error' => 1,
                'tasks' => [],
            ], 200),
        ]);

        $result = app(DataForSeoAccountService::class)->testConnection(
            $this->integration->fresh(['providerCredential']),
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('DataForSEO credentials were rejected.', $result['message']);
    }

    public function test_malformed_json_is_failure(): void
    {
        app(DataForSeoProviderCredentialService::class)->save($this->integration, [
            'login' => 'agency@example.com',
            'password' => 'dfs-secret-password',
        ], $this->admin);

        Http::fake([
            'https://api.dataforseo.com/v3/appendix/user_data' => Http::response('not-json', 200, [
                'Content-Type' => 'text/plain',
            ]),
        ]);

        $result = app(DataForSeoAccountService::class)->testConnection(
            $this->integration->fresh(['providerCredential']),
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('DataForSEO returned a malformed response.', $result['message']);
    }

    public function test_password_never_appears_in_logs_or_exceptions(): void
    {
        app(DataForSeoProviderCredentialService::class)->save($this->integration, [
            'login' => 'agency@example.com',
            'password' => 'dfs-secret-password',
        ], $this->admin);

        Log::spy();

        Http::fake([
            'https://api.dataforseo.com/v3/appendix/user_data' => Http::response(['status_code' => 40100], 401),
        ]);

        try {
            app(DataForSeoApiClient::class)->getUserData($this->integration->fresh(['providerCredential']));
            $this->fail('Expected DataForSeoException');
        } catch (DataForSeoException $exception) {
            $this->assertStringNotContainsString('dfs-secret-password', $exception->getMessage());
            $this->assertStringNotContainsString('Authorization', $exception->getMessage());
        }

        Log::shouldNotHaveReceived('error', function ($message, $context = []): bool {
            $encoded = json_encode([$message, $context]);

            return is_string($encoded) && str_contains($encoded, 'dfs-secret-password');
        });
    }

    public function test_endpoint_allowlist_rejects_unknown_paths(): void
    {
        $this->expectException(DataForSeoException::class);
        DataForSeoEndpointAllowlist::assertAllowed('serp/google/organic/live/advanced');
    }

    public function test_remove_provider_configuration_clears_credentials(): void
    {
        app(DataForSeoProviderCredentialService::class)->save($this->integration, [
            'login' => 'agency@example.com',
            'password' => 'dfs-secret-password',
        ], $this->admin);

        app(DataForSeoProviderCredentialService::class)->remove(
            $this->integration->fresh(['providerCredential']),
            $this->admin,
        );

        $this->assertNull($this->integration->fresh()->providerCredential);
        $this->assertFalse(app(DataForSeoCredentialResolver::class)->isConfigured($this->integration->fresh()));
    }

    public function test_livewire_configure_action_does_not_leak_password(): void
    {
        app(DataForSeoProviderCredentialService::class)->save($this->integration, [
            'login' => 'agency@example.com',
            'password' => 'dfs-secret-password',
        ], $this->admin);

        $component = Livewire::test(ViewIntegration::class, ['record' => $this->integration->id])
            ->callAction('configureDataForSeo', data: [
                'login' => 'agency@example.com',
                'password' => '',
                'clear_password' => false,
            ])
            ->assertHasNoActionErrors();

        $snapshot = json_encode($component->instance());
        $this->assertIsString($snapshot);
        $this->assertStringNotContainsString('dfs-secret-password', $snapshot);
        $this->assertSame(
            'dfs-secret-password',
            app(DataForSeoCredentialResolver::class)->password($this->integration->fresh(['providerCredential'])),
        );
    }

    public function test_provider_registry_keeps_single_dataforseo_identity(): void
    {
        $this->assertTrue(ProviderRegistry::isValid(ProviderRegistry::DATAFORSEO));
        $this->assertSame(['seo_data'], ProviderRegistry::capabilities(ProviderRegistry::DATAFORSEO));
        $this->assertSame('DataForSEO', ProviderRegistry::label(ProviderRegistry::DATAFORSEO));
    }

    /**
     * @return array<string, mixed>
     */
    private function userDataFixture(): array
    {
        return [
            'version' => '0.1.20260101',
            'status_code' => 20000,
            'status_message' => 'Ok.',
            'time' => '0.0123 sec.',
            'cost' => 0,
            'tasks_count' => 1,
            'tasks_error' => 0,
            'tasks' => [
                [
                    'id' => '00000000-0000-0000-0000-000000000001',
                    'status_code' => 20000,
                    'status_message' => 'Ok.',
                    'time' => '0.001 sec.',
                    'cost' => 0,
                    'result_count' => 1,
                    'path' => ['v3', 'appendix', 'user_data'],
                    'data' => ['api' => 'appendix', 'function' => 'user_data'],
                    'result' => [
                        [
                            'login' => 'agency@example.com',
                            'timezone' => 'Europe/London',
                            'money' => [
                                'total' => 100,
                                'balance' => 12.5,
                            ],
                            'rates' => [
                                'limits' => ['day' => [], 'minute' => []],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
