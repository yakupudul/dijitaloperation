<?php

namespace Tests\Feature;

use App\Models\AiRouteStep;
use App\Models\CoreIntegration;
use App\Models\User;
use App\Services\Ai\AiRouteResolver;
use App\Services\Integrations\Anthropic\AnthropicProviderCredentialService;
use App\Services\Integrations\OpenAi\OpenAiProviderCredentialService;
use App\Support\Ai\AiRouteKeys;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AiProviderRoutingFailoverTest extends TestCase
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
            'moxdop.anthropic.api_key' => null,
            'moxdop.gemini.api_key' => null,
            'ai.providers.openai.key' => null,
            'ai.providers.anthropic.key' => null,
            'ai.providers.gemini.key' => null,
            'ai.providers.openai.store' => false,
        ]);
    }

    public function test_default_website_route_resolves_to_openai_gpt5_mini(): void
    {
        $this->configureOpenAi('sk-test-openai');

        $route = app(AiRouteResolver::class)->resolve(AiRouteKeys::WEBSITE_DISCOVERY_CONTEXT);

        $this->assertSame(['openai' => 'gpt-5-mini'], $route->providerModels);
        $this->assertFalse($route->usingPersistedSteps);
        $this->assertStringContainsString('website.discovery_context|openai:gpt-5-mini', $route->signature);
        $this->assertStringNotContainsString('sk-', $route->signature);
    }

    public function test_custom_ordered_route_persists_and_reorders(): void
    {
        $this->configureOpenAi('sk-test-openai');
        $this->configureAnthropic('sk-ant-test');

        $resolver = app(AiRouteResolver::class);
        $resolver->saveSteps(AiRouteKeys::WEBSITE_DISCOVERY_CONTEXT, [
            ['provider' => 'anthropic', 'model' => 'claude-sonnet-5'],
            ['provider' => 'openai', 'model' => 'gpt-5-mini'],
        ]);

        $this->assertSame(2, AiRouteStep::query()->count());

        $route = $resolver->resolve(AiRouteKeys::WEBSITE_DISCOVERY_CONTEXT);
        $this->assertTrue($route->usingPersistedSteps);
        $this->assertSame(['anthropic', 'openai'], array_keys($route->providerModels));
        $this->assertSame('claude-sonnet-5', $route->primaryModel());

        $resolver->saveSteps(AiRouteKeys::WEBSITE_DISCOVERY_CONTEXT, [
            ['provider' => 'openai', 'model' => 'gpt-5-mini'],
            ['provider' => 'anthropic', 'model' => 'claude-sonnet-5'],
        ]);

        $reordered = $resolver->resolve(AiRouteKeys::WEBSITE_DISCOVERY_CONTEXT);
        $this->assertSame(['openai', 'anthropic'], array_keys($reordered->providerModels));
    }

    public function test_same_provider_cannot_appear_twice(): void
    {
        $this->expectException(ValidationException::class);

        app(AiRouteResolver::class)->saveSteps(AiRouteKeys::WEBSITE_DISCOVERY_CONTEXT, [
            ['provider' => 'openai', 'model' => 'gpt-5-mini'],
            ['provider' => 'openai', 'model' => 'gpt-5'],
        ]);
    }

    public function test_unconfigured_provider_excluded_from_effective_chain(): void
    {
        $this->configureOpenAi('sk-test-openai');

        app(AiRouteResolver::class)->saveSteps(AiRouteKeys::WEBSITE_DISCOVERY_CONTEXT, [
            ['provider' => 'openai', 'model' => 'gpt-5-mini'],
            ['provider' => 'gemini', 'model' => 'gemini-3.6-flash'],
            ['provider' => 'anthropic', 'model' => 'claude-sonnet-5'],
        ]);

        $route = app(AiRouteResolver::class)->resolve(AiRouteKeys::WEBSITE_DISCOVERY_CONTEXT);
        $this->assertSame(['openai' => 'gpt-5-mini'], $route->providerModels);
        $this->assertFalse($route->steps[1]['eligible']);
        $this->assertSame('credential_missing', $route->steps[1]['reason']);
    }

    private function configureOpenAi(string $apiKey): void
    {
        $integration = CoreIntegration::factory()->openai()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => ['connection_status' => 'connected'],
        ]);
        app(OpenAiProviderCredentialService::class)->save($integration, [
            'api_key' => $apiKey,
        ], $this->admin);
    }

    private function configureAnthropic(string $apiKey): void
    {
        $integration = CoreIntegration::factory()->anthropic()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => ['connection_status' => 'connected'],
        ]);
        app(AnthropicProviderCredentialService::class)->save($integration, [
            'api_key' => $apiKey,
        ], $this->admin);
    }
}
