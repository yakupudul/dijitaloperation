<?php

namespace Tests\Feature;

use App\Livewire\Demo\Integrations\AiProviderIntegrationPage;
use App\Livewire\Demo\Integrations\IntegrationsIndex;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\User;
use App\Services\Integrations\Anthropic\AnthropicCredentialResolver;
use App\Services\Integrations\Gemini\GeminiCredentialResolver;
use App\Services\Integrations\OpenAi\OpenAiCredentialResolver;
use App\Services\Integrations\OperatorIntegrationsHubQuery;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class OperatorAiProviderIntegrationConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private const string OPENAI_KEY = 'sk-test-openai-key-002b';

    private const string ANTHROPIC_KEY = 'sk-ant-test-key-002b';

    private const string GEMINI_KEY = 'gemini-test-key-002b';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        config([
            'moxdop.openai.api_key' => null,
            'ai.providers.openai.key' => null,
            'moxdop.anthropic.api_key' => null,
            'moxdop.gemini.api_key' => null,
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
    }

    public function test_hub_lists_openai_anthropic_and_gemini_with_configure_routes(): void
    {
        Livewire::test(IntegrationsIndex::class)
            ->assertOk()
            ->assertSee('OpenAI')
            ->assertSee('Anthropic')
            ->assertSee('Gemini')
            ->assertSee('Configure')
            ->assertDontSee('AI settings');

        $cards = collect(app(OperatorIntegrationsHubQuery::class)->groups())
            ->flatMap(fn (array $group) => $group['providers'])
            ->keyBy('id');

        foreach ([ProviderRegistry::OPENAI, ProviderRegistry::ANTHROPIC, ProviderRegistry::GEMINI] as $provider) {
            $this->assertSame('demo.integrations.ai', $cards[$provider]['route']);
            $this->assertSame('Configure', $cards[$provider]['manage_label']);
            $this->assertSame('Not configured', $cards[$provider]['state_label']);
            $this->assertNull($cards[$provider]['resources_discovered']);
        }
    }

    public function test_openai_key_is_write_only_and_canonical(): void
    {
        $this->assertProviderKeyRoundTrip(ProviderRegistry::OPENAI, self::OPENAI_KEY, OpenAiCredentialResolver::class);
    }

    public function test_anthropic_key_is_write_only_and_canonical(): void
    {
        $this->assertProviderKeyRoundTrip(ProviderRegistry::ANTHROPIC, self::ANTHROPIC_KEY, AnthropicCredentialResolver::class);
    }

    public function test_gemini_key_is_write_only_and_canonical(): void
    {
        $this->assertProviderKeyRoundTrip(ProviderRegistry::GEMINI, self::GEMINI_KEY, GeminiCredentialResolver::class);
    }

    public function test_unauthorized_user_cannot_save_openai_key(): void
    {
        $member = User::factory()->create();
        $member->assignRole(Roles::TEAM_MEMBER);
        $this->actingAs($member);

        Livewire::test(AiProviderIntegrationPage::class, ['provider' => ProviderRegistry::OPENAI])
            ->assertDontSee('API Key')
            ->call('saveConfiguration')
            ->assertForbidden();
    }

    public function test_test_configuration_does_not_call_live_api_until_invoked_with_fake(): void
    {
        Http::fake([
            'api.openai.com/v1/models' => Http::response(['data' => [['id' => 'gpt-5-mini']]], 200),
        ]);

        Livewire::test(AiProviderIntegrationPage::class, ['provider' => ProviderRegistry::OPENAI])
            ->update(
                [['method' => 'saveConfiguration', 'params' => [], 'path' => '']],
                ['apiKey' => self::OPENAI_KEY],
            );

        Http::assertNothingSent();

        Livewire::test(AiProviderIntegrationPage::class, ['provider' => ProviderRegistry::OPENAI])
            ->call('testConfiguration')
            ->assertSee('OpenAI authentication succeeded.');

        Http::assertSentCount(1);
    }

    /**
     * @param  class-string  $resolverClass
     */
    private function assertProviderKeyRoundTrip(string $provider, string $secret, string $resolverClass): void
    {
        Http::fake();

        $component = Livewire::test(AiProviderIntegrationPage::class, ['provider' => $provider]);
        $component
            ->assertSee('API Key')
            ->update(
                [['method' => 'saveConfiguration', 'params' => [], 'path' => '']],
                ['apiKey' => $secret],
            )
            ->assertHasNoErrors()
            ->assertSet('apiKey', '');

        $this->assertStringNotContainsString($secret, $component->html());

        $integration = CoreIntegration::query()->where('provider', $provider)->firstOrFail();
        $this->assertTrue(app($resolverClass)->isConfigured($integration->fresh(['providerCredential'])));
        $this->assertSame($secret, app($resolverClass)->apiKey($integration->fresh(['providerCredential'])));

        $stored = DB::table('core_integration_credentials')
            ->where('integration_id', $integration->id)
            ->where('credential_type', CoreIntegrationCredential::TYPE_PROVIDER)
            ->value('encrypted_payload');
        $this->assertStringNotContainsString($secret, (string) $stored);

        Http::assertNothingSent();
    }
}
