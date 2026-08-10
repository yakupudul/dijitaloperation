<?php

namespace Tests\Feature;

use App\Filament\App\Clusters\Settings\Pages\AiControlPlaneSettings;
use App\Models\AiRouteStep;
use App\Models\Brand;
use App\Models\BrandIntelligenceContext;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\Run;
use App\Models\Task;
use App\Models\User;
use App\Services\Ai\AiRouteResolver;
use App\Services\Integrations\Anthropic\AnthropicProviderCredentialService;
use App\Services\Integrations\OpenAi\OpenAiProviderCredentialService;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Livewire\Livewire;
use MoxDop\Website\Ai\Agents\WebsiteRecommendationAgent;
use MoxDop\Website\Ai\WebsiteAiRecommendationConfig;
use MoxDop\Website\Ai\WebsiteAiRecommendationService;
use MoxDop\Website\Ai\WebsiteAiRoutes;
use RuntimeException;
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

        $route = app(AiRouteResolver::class)->resolve(WebsiteAiRoutes::AI_GUIDANCE);

        $this->assertSame(['openai' => 'gpt-5-mini'], $route->providerModels);
        $this->assertFalse($route->usingPersistedSteps);
        $this->assertStringContainsString('website.ai_guidance|openai:gpt-5-mini', $route->signature);
        $this->assertStringNotContainsString('sk-', $route->signature);
    }

    public function test_custom_ordered_route_persists_and_reorders(): void
    {
        $this->configureOpenAi('sk-test-openai');
        $this->configureAnthropic('sk-ant-test');

        $resolver = app(AiRouteResolver::class);
        $resolver->saveSteps(WebsiteAiRoutes::AI_GUIDANCE, [
            ['provider' => 'anthropic', 'model' => 'claude-sonnet-5'],
            ['provider' => 'openai', 'model' => 'gpt-5-mini'],
        ]);

        $this->assertSame(2, AiRouteStep::query()->count());

        $route = $resolver->resolve(WebsiteAiRoutes::AI_GUIDANCE);
        $this->assertTrue($route->usingPersistedSteps);
        $this->assertSame(['anthropic', 'openai'], array_keys($route->providerModels));
        $this->assertSame('claude-sonnet-5', $route->primaryModel());

        $resolver->saveSteps(WebsiteAiRoutes::AI_GUIDANCE, [
            ['provider' => 'openai', 'model' => 'gpt-5-mini'],
            ['provider' => 'anthropic', 'model' => 'claude-sonnet-5'],
        ]);

        $reordered = $resolver->resolve(WebsiteAiRoutes::AI_GUIDANCE);
        $this->assertSame(['openai', 'anthropic'], array_keys($reordered->providerModels));
    }

    public function test_same_provider_cannot_appear_twice(): void
    {
        $this->expectException(ValidationException::class);

        app(AiRouteResolver::class)->saveSteps(WebsiteAiRoutes::AI_GUIDANCE, [
            ['provider' => 'openai', 'model' => 'gpt-5-mini'],
            ['provider' => 'openai', 'model' => 'gpt-5'],
        ]);
    }

    public function test_unconfigured_provider_excluded_from_effective_chain(): void
    {
        $this->configureOpenAi('sk-test-openai');

        app(AiRouteResolver::class)->saveSteps(WebsiteAiRoutes::AI_GUIDANCE, [
            ['provider' => 'openai', 'model' => 'gpt-5-mini'],
            ['provider' => 'gemini', 'model' => 'gemini-3.6-flash'],
            ['provider' => 'anthropic', 'model' => 'claude-sonnet-5'],
        ]);

        $route = app(AiRouteResolver::class)->resolve(WebsiteAiRoutes::AI_GUIDANCE);
        $this->assertSame(['openai' => 'gpt-5-mini'], $route->providerModels);
        $this->assertFalse($route->steps[1]['eligible']);
        $this->assertSame('credential_missing', $route->steps[1]['reason']);
    }

    public function test_changing_route_invalidates_fingerprint_reuse(): void
    {
        $this->configureOpenAi('sk-test-openai');
        [$asset, $finding, $evidence] = $this->seedContext();

        WebsiteRecommendationAgent::fake([
            $this->fakeStructured($finding->id, $evidence->id),
            $this->fakeStructured($finding->id, $evidence->id),
        ])->preventStrayPrompts();

        $service = app(WebsiteAiRecommendationService::class);
        $first = $service->analyze($asset);
        $this->assertFalse($first['reused']);

        $second = $service->analyze($asset->fresh());
        $this->assertTrue($second['reused']);

        $this->configureAnthropic('sk-ant-test');
        app(AiRouteResolver::class)->saveSteps(WebsiteAiRoutes::AI_GUIDANCE, [
            ['provider' => 'anthropic', 'model' => 'claude-sonnet-5'],
        ]);

        $third = $service->analyze($asset->fresh());
        $this->assertFalse($third['reused']);
        $this->assertSame('anthropic', $third['run']->metadata['provider']);
    }

    public function test_rate_limit_triggers_native_failover_to_anthropic(): void
    {
        $this->configureOpenAi('sk-test-openai');
        $this->configureAnthropic('sk-ant-test');
        app(AiRouteResolver::class)->saveSteps(WebsiteAiRoutes::AI_GUIDANCE, [
            ['provider' => 'openai', 'model' => 'gpt-5-mini'],
            ['provider' => 'anthropic', 'model' => 'claude-sonnet-5'],
        ]);

        [$asset, $finding, $evidence] = $this->seedContext();

        WebsiteRecommendationAgent::fake(function ($prompt, $attachments, $provider, $model) use ($finding, $evidence) {
            if ($provider->name() === 'openai') {
                throw RateLimitedException::forProvider('openai');
            }

            return $this->fakeStructured($finding->id, $evidence->id);
        })->preventStrayPrompts();

        $result = app(WebsiteAiRecommendationService::class)->analyze($asset);
        $this->assertFalse($result['reused']);
        $this->assertSame('completed', $result['run']->status);
        $this->assertSame('anthropic', $result['run']->metadata['provider']);
        $this->assertSame('claude-sonnet-5', $result['run']->metadata['model']);
        $this->assertTrue($result['run']->metadata['fallback_occurred']);
        $this->assertSame(0, Task::query()->count());
        $this->assertSame(1, Finding::query()->count());
    }

    public function test_provider_overloaded_and_insufficient_credits_failover(): void
    {
        $this->configureOpenAi('sk-test-openai');
        $this->configureAnthropic('sk-ant-test');
        app(AiRouteResolver::class)->saveSteps(WebsiteAiRoutes::AI_GUIDANCE, [
            ['provider' => 'openai', 'model' => 'gpt-5-mini'],
            ['provider' => 'anthropic', 'model' => 'claude-sonnet-5'],
        ]);

        [$asset, $finding, $evidence] = $this->seedContext();

        WebsiteRecommendationAgent::fake(function ($prompt, $attachments, $provider, $model) use ($finding, $evidence) {
            if ($provider->name() === 'openai') {
                throw ProviderOverloadedException::forProvider('openai');
            }

            return $this->fakeStructured($finding->id, $evidence->id);
        })->preventStrayPrompts();
        $overloaded = app(WebsiteAiRecommendationService::class)->analyze($asset);
        $this->assertSame('anthropic', $overloaded['run']->metadata['provider']);

        Evidence::query()->where('type', WebsiteAiRecommendationConfig::EVIDENCE_TYPE_AI_INSIGHT)->delete();

        WebsiteRecommendationAgent::fake(function ($prompt, $attachments, $provider, $model) use ($finding, $evidence) {
            if ($provider->name() === 'openai') {
                throw InsufficientCreditsException::forProvider('openai');
            }

            return $this->fakeStructured($finding->id, $evidence->id);
        })->preventStrayPrompts();
        $credits = app(WebsiteAiRecommendationService::class)->analyze($asset->fresh());
        $this->assertSame('anthropic', $credits['run']->metadata['provider']);
    }

    public function test_validation_error_does_not_failover(): void
    {
        $this->configureOpenAi('sk-test-openai');
        $this->configureAnthropic('sk-ant-test');
        app(AiRouteResolver::class)->saveSteps(WebsiteAiRoutes::AI_GUIDANCE, [
            ['provider' => 'openai', 'model' => 'gpt-5-mini'],
            ['provider' => 'anthropic', 'model' => 'claude-sonnet-5'],
        ]);

        [$asset] = $this->seedContext();

        WebsiteRecommendationAgent::fake([
            fn (): never => throw new RuntimeException('bad request / validation'),
            $this->fakeStructured(1, 1),
        ])->preventStrayPrompts();

        $result = app(WebsiteAiRecommendationService::class)->analyze($asset);
        $this->assertSame('failed', $result['run']->status);
        $this->assertSame('RuntimeException', $result['run']->metadata['error_class']);
    }

    public function test_grounding_failure_does_not_invoke_another_provider(): void
    {
        $this->configureOpenAi('sk-test-openai');
        $this->configureAnthropic('sk-ant-test');
        app(AiRouteResolver::class)->saveSteps(WebsiteAiRoutes::AI_GUIDANCE, [
            ['provider' => 'openai', 'model' => 'gpt-5-mini'],
            ['provider' => 'anthropic', 'model' => 'claude-sonnet-5'],
        ]);

        [$asset, $finding, $evidence] = $this->seedContext();

        $bad = $this->fakeStructured($finding->id, $evidence->id);
        $bad['finding_interpretations'][0]['finding_id'] = 999999;

        WebsiteRecommendationAgent::fake([
            $bad,
            $this->fakeStructured($finding->id, $evidence->id),
        ])->preventStrayPrompts();

        $result = app(WebsiteAiRecommendationService::class)->analyze($asset);
        $this->assertSame('failed', $result['run']->status);
        $this->assertSame('openai', $result['run']->metadata['provider']);
    }

    public function test_control_plane_page_loads_for_admin(): void
    {
        Livewire::test(AiControlPlaneSettings::class)
            ->assertOk()
            ->assertSee('AI Control Plane')
            ->assertSee('Website AI Guidance')
            ->assertSee('Manage integrations');
    }

    public function test_no_eligible_providers_throws_clear_error(): void
    {
        [$asset] = $this->seedContext();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No eligible AI providers');

        app(WebsiteAiRecommendationService::class)->analyze($asset);
    }

    /**
     * @return array{0: DigitalAsset, 1: Finding, 2: Evidence}
     */
    private function seedContext(): array
    {
        $brand = Brand::factory()->create(['name' => 'Demo Clinic']);
        BrandIntelligenceContext::factory()->create([
            'brand_id' => $brand->id,
            'business_summary' => 'Aesthetic clinic focused on non-surgical treatments.',
            'important_constraints' => 'Patient before/after advertising cannot be used',
        ]);

        $asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'website',
            'primary_url' => 'https://example.com',
            'domain' => 'example.com',
        ]);

        $diagnosisRun = Run::factory()->create([
            'digital_asset_id' => $asset->id,
            'module_id' => 'website-diagnosis',
            'status' => 'completed',
        ]);

        $evidence = Evidence::factory()->sitemap()->create([
            'run_id' => $diagnosisRun->id,
            'digital_asset_id' => $asset->id,
            'source_module' => 'website-diagnosis',
            'payload' => [
                'host' => 'example.com',
                'present' => false,
                'status_code' => 404,
            ],
        ]);

        $finding = Finding::factory()->create([
            'digital_asset_id' => $asset->id,
            'source_module' => 'website-diagnosis',
            'fingerprint' => 'sitemap-xml-availability|host=example.com|'.uniqid(),
            'category' => 'indexability',
            'severity' => 'high',
            'title' => 'Sitemap missing or unreadable',
            'summary' => 'No readable sitemap.xml was found.',
            'status' => 'open',
            'last_run_id' => $diagnosisRun->id,
        ]);

        Recommendation::factory()->create([
            'finding_id' => $finding->id,
            'digital_asset_id' => $asset->id,
            'source_module' => 'website-diagnosis',
            'title' => 'Add sitemap.xml',
            'status' => 'open',
        ]);

        return [$asset, $finding, $evidence];
    }

    /**
     * @return array<string, mixed>
     */
    private function fakeStructured(int $findingId, int $evidenceId): array
    {
        return [
            'executive_summary' => 'Organic discovery is limited by a missing sitemap.',
            'overall_priority' => 'high',
            'context_observations' => ['Brand constraint forbids before/after advertising.'],
            'finding_interpretations' => [[
                'finding_id' => $findingId,
                'evidence_ids' => [$evidenceId],
                'explanation' => 'Sitemap endpoint returned 404.',
                'business_relevance' => 'Slower organic discovery may reduce consult demand.',
                'likely_contributors' => ['Missing sitemap.xml response'],
                'uncertainty' => 'medium',
                'suggested_priority' => 'high',
                'recommendation_draft' => [
                    'title' => 'Publish sitemap.xml',
                    'action' => 'Publish a valid sitemap at /sitemap.xml',
                    'rationale' => 'Improves crawl discovery',
                    'effort' => 'low',
                ],
                'dependencies' => [],
                'success_signal' => 'Sitemap returns 200',
                'failure_signal' => 'Still 404',
                'watch_metrics' => ['impressions'],
            ]],
            'prompt_version' => WebsiteAiRecommendationConfig::PROMPT_VERSION,
        ];
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
