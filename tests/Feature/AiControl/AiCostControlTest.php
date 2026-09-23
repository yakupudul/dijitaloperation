<?php

namespace Tests\Feature\AiControl;

use App\Ai\Agents\SeoTaskContentPlannerAgent;
use App\Livewire\Demo\Integrations\AiProviderIntegrationPage;
use App\Livewire\Demo\Settings\AiControlPlanePage;
use App\Models\AgencySetting;
use App\Models\CoreIntegration;
use App\Models\User;
use App\Services\Ai\AiBudget;
use App\Services\Ai\AiPricing;
use App\Services\Ai\AiRouteResolver;
use App\Support\Ai\AiProviderCatalog;
use App\Support\Ai\AiRouteKeys;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Faz 1a: Groq / OpenRouter providers, per-call usage + cost, monthly budget and the client-data rule.
 */
final class AiCostControlTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        config([
            'moxdop.openai.api_key' => null, 'ai.providers.openai.key' => null,
            'moxdop.anthropic.api_key' => null, 'ai.providers.anthropic.key' => null,
            'moxdop.gemini.api_key' => null, 'ai.providers.gemini.key' => null,
            'ai.providers.groq.key' => null, 'ai.providers.openrouter.key' => null,
        ]);
        Http::preventStrayRequests();
    }

    public function test_pricing_computes_cost_and_recognises_free_models(): void
    {
        $pricing = app(AiPricing::class);
        // Sonnet 5: $2 / $10 per million tokens.
        $this->assertEqualsWithDelta(0.07, $pricing->cost('anthropic', 'claude-sonnet-5', 20_000, 3_000), 0.000001);
        $this->assertEqualsWithDelta(0.0035, $pricing->cost('anthropic', 'claude-haiku-4-5-20251001', 1_000, 500), 0.000001);
        $this->assertTrue($pricing->isFree('openrouter', 'meta-llama/llama-3.3-70b-instruct:free'));
        $this->assertTrue($pricing->isFree('groq', 'llama-3.3-70b-versatile'));
        $this->assertNull($pricing->cost('openai', 'gpt-5-mini', 1_000, 1_000), 'unknown price stays unknown, never zero');
    }

    public function test_every_agent_call_is_recorded_with_route_and_cost(): void
    {
        config(['moxdop.anthropic.api_key' => 'sk-ant-test']);
        CoreIntegration::factory()->anthropic()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        SeoTaskContentPlannerAgent::fake([['items' => [], 'prompt_version' => 'x']]);

        $route = app(AiRouteResolver::class)->resolve(AiRouteKeys::SEO_TASKS_CONTENT_PLANNER);
        $this->assertSame('anthropic', $route->primaryProvider());
        (new SeoTaskContentPlannerAgent)->prompt('CONTEXT_JSON {}', provider: $route->providerModels);

        $row = DB::table('ai_usage_records')->first();
        $this->assertNotNull($row);
        $this->assertSame(AiRouteKeys::SEO_TASKS_CONTENT_PLANNER, $row->route_key);
        $this->assertSame('SeoTaskContentPlannerAgent', $row->agent);
    }

    public function test_budget_exhaustion_skips_paid_models_but_keeps_free_ones(): void
    {
        config(['moxdop.anthropic.api_key' => 'sk-ant-test', 'ai.providers.groq.key' => 'gsk-test']);
        AgencySetting::query()->create(['agency_name' => 'MoxDOP', 'portal_name' => 'MoxDOP'])->forceFill(['ai_monthly_budget_usd' => 5])->save();
        DB::table('ai_usage_records')->insert([
            'route_key' => 'x', 'agent' => 'A', 'provider' => 'anthropic', 'model' => 'claude-sonnet-5',
            'input_tokens' => 1, 'output_tokens' => 1, 'cost_usd' => 5.10, 'created_at' => now(),
        ]);
        $this->assertTrue(app(AiBudget::class)->isExhausted());

        // Client-data route: only paid providers allowed → nothing can run, plans fall back to rules.
        $analysis = app(AiRouteResolver::class)->resolve(AiRouteKeys::SEO_TASKS_CONTENT_PLANNER);
        $this->assertTrue($analysis->isEmpty());
        $this->assertSame('budget_exhausted', $analysis->steps[0]['reason']);

        // Public-data route: the free Groq step still runs.
        $public = app(AiRouteResolver::class)->resolve(AiRouteKeys::SEARCH_DEMAND_LIBRARIAN);
        $this->assertSame(['groq' => 'llama-3.3-70b-versatile'], $public->providerModels);
    }

    public function test_free_tier_providers_are_blocked_for_client_data_routes(): void
    {
        config(['ai.providers.groq.key' => 'gsk-test']);

        try {
            app(AiRouteResolver::class)->saveSteps(AiRouteKeys::SEO_TASKS_SITE_UNDERSTANDING, [['provider' => AiProviderCatalog::GROQ, 'model' => 'llama-3.3-70b-versatile']]);
            $this->fail('client-data route accepted a free-tier provider');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('müşteri verisi', implode(' ', $exception->errors()['steps']));
        }

        app(AiRouteResolver::class)->saveSteps(AiRouteKeys::SALES_INTENT_CLASSIFICATION, [['provider' => AiProviderCatalog::GROQ, 'model' => 'llama-3.3-70b-versatile']]);
        $this->assertSame(['groq' => 'llama-3.3-70b-versatile'], app(AiRouteResolver::class)->resolve(AiRouteKeys::SALES_INTENT_CLASSIFICATION)->providerModels);
    }

    public function test_groq_key_can_be_saved_and_tested_from_the_integration_page(): void
    {
        Http::fake(['api.groq.com/*' => Http::response(['data' => [['id' => 'llama-3.3-70b-versatile']]], 200)]);

        Livewire::test(AiProviderIntegrationPage::class, ['provider' => 'groq'])
            ->update([['method' => 'saveConfiguration', 'params' => [], 'path' => '']], ['apiKey' => 'gsk-live-test'])
            ->assertHasNoErrors()
            ->call('testConfiguration');

        $integration = CoreIntegration::query()->where('provider', 'groq')->firstOrFail();
        $this->assertSame('connected', $integration->config['connection_status'] ?? null);
        $this->assertSame('gsk-live-test', $integration->providerCredential->encrypted_payload['api_key']);
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer gsk-live-test'));

        $public = app(AiRouteResolver::class)->resolve(AiRouteKeys::SEARCH_DEMAND_LIBRARIAN);
        $this->assertSame('groq', $public->primaryProvider());
    }

    public function test_control_plane_shows_spend_and_saves_budget(): void
    {
        DB::table('ai_usage_records')->insert([
            'route_key' => AiRouteKeys::SEO_TASKS_CONTENT_PLANNER, 'agent' => 'SeoTaskContentPlannerAgent', 'provider' => 'anthropic',
            'model' => 'claude-sonnet-5', 'input_tokens' => 20000, 'output_tokens' => 3000, 'cost_usd' => 0.07, 'created_at' => now(),
        ]);

        Livewire::test(AiControlPlanePage::class)
            ->assertSee('Bu ayki AI harcaması')
            ->assertSee('$0.07')
            ->assertSee('Herkese açık veri')
            ->set('monthlyBudget', '40')
            ->call('saveBudget');

        $this->assertSame(40.0, app(AiBudget::class)->monthlyBudget());
    }
}
