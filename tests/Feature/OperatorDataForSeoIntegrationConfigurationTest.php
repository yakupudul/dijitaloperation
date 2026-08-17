<?php

namespace Tests\Feature;

use App\Livewire\Demo\Integrations\DataForSeoIntegrationPage;
use App\Livewire\Demo\Integrations\IntegrationsIndex;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\User;
use App\Services\Integrations\DataForSeo\DataForSeoCredentialResolver;
use App\Services\Integrations\OperatorIntegrationsHubQuery;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class OperatorDataForSeoIntegrationConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private const string PASSWORD = 'dfs-test-password-002b';

    private User $admin;

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
    }

    public function test_hub_review_self_link_is_replaced_with_configure_workspace(): void
    {
        $card = collect(app(OperatorIntegrationsHubQuery::class)->groups())
            ->flatMap(fn (array $group) => $group['providers'])
            ->firstWhere('id', ProviderRegistry::DATAFORSEO);

        $this->assertSame('operator.integrations.dataforseo', $card['route']);
        $this->assertSame('Configure', $card['manage_label']);
        $this->assertSame('Not configured', $card['state_label']);

        Livewire::test(IntegrationsIndex::class)
            ->assertOk()
            ->assertSee('DataForSEO')
            ->assertDontSeeHtml('>Review<');

        $this->get(route('operator.integrations.dataforseo'))
            ->assertOk()
            ->assertSee('API Login')
            ->assertSee('API Password');
    }

    public function test_password_is_write_only_and_canonical_service_is_used(): void
    {
        Http::fake();

        $component = Livewire::test(DataForSeoIntegrationPage::class);
        $component->update(
            [['method' => 'saveConfiguration', 'params' => [], 'path' => '']],
            [
                'login' => 'dfs-login',
                'password' => self::PASSWORD,
            ],
        );
        $component
            ->assertHasNoErrors()
            ->assertSee('DataForSEO credentials saved.')
            ->assertSet('password', '');

        $this->assertStringNotContainsString(self::PASSWORD, $component->html());

        $integration = CoreIntegration::query()->where('provider', ProviderRegistry::DATAFORSEO)->firstOrFail();
        $this->assertTrue(app(DataForSeoCredentialResolver::class)->isConfigured($integration->fresh(['providerCredential'])));
        $this->assertSame(self::PASSWORD, app(DataForSeoCredentialResolver::class)->password($integration->fresh(['providerCredential'])));

        $stored = DB::table('core_integration_credentials')
            ->where('integration_id', $integration->id)
            ->where('credential_type', CoreIntegrationCredential::TYPE_PROVIDER)
            ->value('encrypted_payload');
        $this->assertStringNotContainsString(self::PASSWORD, (string) $stored);
        Http::assertNothingSent();
    }

    public function test_test_configuration_uses_safe_user_data_endpoint_only(): void
    {
        Http::fake([
            'https://api.dataforseo.com/v3/appendix/user_data' => Http::response([
                'version' => '0.1.20260101',
                'status_code' => 20000,
                'status_message' => 'Ok.',
                'cost' => 0,
                'tasks_count' => 1,
                'tasks_error' => 0,
                'tasks' => [[
                    'id' => 'user-data-1',
                    'status_code' => 20000,
                    'status_message' => 'Ok.',
                    'cost' => 0,
                    'result_count' => 1,
                    'path' => ['v3', 'appendix', 'user_data'],
                    'result' => [[
                        'login' => 'dfs-login',
                        'timezone' => 'UTC',
                        'money' => ['balance' => 0],
                    ]],
                ]],
            ], 200),
        ]);

        Livewire::test(DataForSeoIntegrationPage::class)
            ->update(
                [['method' => 'saveConfiguration', 'params' => [], 'path' => '']],
                [
                    'login' => 'dfs-login',
                    'password' => self::PASSWORD,
                ],
            )
            ->call('testConfiguration')
            ->assertSee('Connected as dfs-login');

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/v3/appendix/user_data'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'ranked_keywords')
            || str_contains($request->url(), 'serp')
            || str_contains($request->url(), 'keywords_for_site')
            || str_contains($request->url(), 'competitors'));
    }

    public function test_unauthorized_user_cannot_modify_credentials(): void
    {
        $member = User::factory()->create();
        $member->assignRole(Roles::TEAM_MEMBER);
        $this->actingAs($member);

        Livewire::test(DataForSeoIntegrationPage::class)
            ->assertDontSee('API Password')
            ->call('saveConfiguration')
            ->assertForbidden();
    }
}
