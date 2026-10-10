<?php

namespace Tests\Feature\AiControl;

use App\Jobs\RefreshBrandCandidatesJob;
use App\Livewire\Operator\Settings\AiOperationsPage;
use App\Models\AgencySetting;
use App\Models\AiTask;
use App\Services\Ai\AiAssignments;
use App\Services\Ai\AiBudget;
use App\Services\Ai\AiCredits;
use App\Services\Ai\AiPricing;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Prompts\PromptRegistry;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Site\SiteTestCase;

/**
 * Claude API kredisi (yakup, 2026-10-08): GPT / Claude API / Claude abonelik choice per operation, loaded credit
 * minus recorded cost, the Claude API running scheduled work by itself and the MCP queue taking over when money stops it.
 */
final class AiCreditsAndAssignmentsTest extends SiteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->enableAi();
        config(['moxdop-mcp.token' => 'test-mcp-token', 'moxdop-ai-pricing.automatic_areas' => ['queries']]);
    }

    public function test_claude_5_5_models_are_priced(): void
    {
        $pricing = app(AiPricing::class);
        foreach (['claude-haiku-5-5', 'claude-sonnet-5-5', 'claude-opus-5-5'] as $model) {
            $this->assertNotNull($pricing->price('anthropic', $model), $model.' has no price');
        }
    }

    public function test_remaining_credit_is_loaded_minus_cost_since_the_first_top_up(): void
    {
        $credits = app(AiCredits::class);
        $this->assertFalse($credits->status('anthropic')['tracked']);
        $this->assertFalse($credits->exhausted('anthropic'), 'no credit entered: only budget and ceiling apply');

        $this->usage(3.00, now()->subDays(3)); // before the first top-up: not counted
        $credits->topUp('anthropic', 100, 'İlk yükleme', $this->admin);
        $this->usage(12.50);
        $this->usage(1.00, provider: 'openai');

        $status = $credits->status('anthropic');
        $this->assertSame(100.0, $status['loaded']);
        $this->assertEqualsWithDelta(12.50, $status['spent'], 0.0001);
        $this->assertEqualsWithDelta(87.50, $status['remaining'], 0.0001);
        $this->assertFalse($status['low']);

        $this->usage(80.00);
        $this->assertTrue($credits->status('anthropic')['low']);
        $this->usage(10.00);
        $this->assertTrue($credits->exhausted('anthropic'));
        $this->assertStringContainsString('Claude API kredisi bitti', (string) app(AiBudget::class)->blockReason('anthropic', 'claude-sonnet-5-5', AiRouteKeys::BRAND_SETUP));
    }

    public function test_operations_given_to_the_claude_api_run_by_themselves_and_fall_back_to_the_queue_when_credit_ends(): void
    {
        $this->assertFalse(AiBudget::automaticAllowed('site.cluster_match'));
        $this->pin('site.cluster_match', AiAssignments::HAIKU);
        $this->assertTrue(AiBudget::automaticAllowed('site.cluster_match'), 'Claude API: runs without a click');
        $this->assertTrue(AiBudget::automaticAllowed('site.weekly_refresh'), 'the weekly gate opens with a Claude API site step');

        $queue = app(AiTaskQueue::class);
        $this->assertFalse($queue->delegated('site.cluster_match'), 'credit left: the API runs it');
        app(AiCredits::class)->topUp('anthropic', 5, null, $this->admin);
        $this->usage(5.00);
        $this->assertTrue($queue->delegated('site.cluster_match'), 'credit gone: the subscription queue takes over');

        (AgencySetting::query()->orderBy('id')->first() ?? AgencySetting::query()->create(['agency_name' => 'MoxDOP', 'portal_name' => 'MoxDOP']))
            ->forceFill(['ai_monthly_budget_usd' => 1000, 'ai_daily_auto_budget_usd' => 0])->save();
        app(AiCredits::class)->topUp('anthropic', 50, null, $this->admin);
        $this->assertFalse($queue->delegated('site.cluster_match'), 'credit topped up: back on the API');
    }

    public function test_recommended_plan_puts_bulk_work_on_haiku_writing_on_sonnet_and_keeps_the_query_autopilot(): void
    {
        $registry = app(PromptRegistry::class);
        $triage = $registry->current(AiRouteKeys::QUERIES_TRIAGE)->model;

        $this->artisan('moxdop:ai:assign', ['plan' => 'onerilen'])->assertSuccessful();

        $this->assertSame(AiAssignments::SONNET, $registry->current(AiRouteKeys::SITE_WRITE_ARTICLE)->model);
        $this->assertSame(AiAssignments::HAIKU, $registry->current('site.cluster_match')->model);
        $this->assertSame($triage, $registry->current(AiRouteKeys::QUERIES_TRIAGE)->model);

        $counts = app(AiAssignments::class)->apply(AiAssignments::PLAN_RECOMMENDED, $this->admin);
        $this->assertSame(0, $counts['changed'], 'a second run publishes nothing');

        app(AiAssignments::class)->apply(AiAssignments::PLAN_SUBSCRIPTION, $this->admin);
        $this->assertSame(AiTaskQueue::MODEL, $registry->current(AiRouteKeys::SITE_WRITE_ARTICLE)->model);

        $this->artisan('moxdop:ai:assign', ['plan' => 'yok'])->assertFailed();
    }

    public function test_settings_page_loads_credit_and_applies_a_plan(): void
    {
        Livewire::test(AiOperationsPage::class)
            ->assertSee('AI dağılımı ve kredi')
            ->set('creditProvider', 'anthropic')->set('creditAmount', '100')->call('topUpCredit')->assertHasNoErrors()
            ->assertSee('Claude API kredisine $100.00 eklendi.')
            ->set('creditAmount', '0')->call('topUpCredit')->assertHasErrors('creditAmount')
            ->call('assignPlan', AiAssignments::PLAN_RECOMMENDED)->assertSee('uygulandı');

        $this->assertSame(100.0, app(AiCredits::class)->status('anthropic')['loaded']);
        $this->assertSame(AiAssignments::SONNET, app(PromptRegistry::class)->current(AiRouteKeys::SITE_WRITE_ARTICLE)->model);
    }

    /**
     * Claude API dönemi (yakup, 2026-10-10): until 25 October everything that may move runs on the Claude API, work
     * waiting in the subscription queue starts again there, the limits are raised, and on 25 October every operation
     * goes back to its earlier model.
     */
    public function test_the_claude_api_period_moves_work_raises_limits_and_ends_on_its_day(): void
    {
        Storage::fake('local');
        Queue::fake();
        $registry = app(PromptRegistry::class);
        app(AiAssignments::class)->apply(AiAssignments::PLAN_SUBSCRIPTION, $this->admin);
        $this->pin(AiRouteKeys::BRAND_SETUP, 'openai:gpt-5-mini');
        $triage = $registry->current(AiRouteKeys::QUERIES_TRIAGE)->model;
        $job = serialize(new RefreshBrandCandidatesJob);
        $waiting = AiTask::query()->create(['operation' => 'site.cluster_match', 'resume_key' => hash('sha256', $job), 'sequence' => 1, 'input_hash' => 'h',
            'status' => AiTask::PENDING, 'instructions' => 'x', 'input' => 'DATA_JSON {}', 'output_schema' => [], 'resume' => $job]);
        (AgencySetting::query()->orderBy('id')->first() ?? AgencySetting::query()->create(['agency_name' => 'MoxDOP', 'portal_name' => 'MoxDOP']))
            ->forceFill(['ai_monthly_budget_usd' => 100, 'ai_daily_auto_budget_usd' => 4])->save();

        config(['moxdop-ai-pricing.claude_api_window.until' => now('Europe/Istanbul')->addDays(15)->toDateString()]);
        $this->artisan('moxdop:ai:claude-api-window', ['action' => 'start'])->assertSuccessful();

        $this->assertSame(AiAssignments::HAIKU, $registry->current('site.cluster_match')->model);
        $this->assertSame(AiAssignments::SONNET, $registry->current(AiRouteKeys::SITE_WRITE_ARTICLE)->model);
        $this->assertSame($triage, $registry->current(AiRouteKeys::QUERIES_TRIAGE)->model, 'the query autopilot stays');
        $this->assertSame(AiTask::CONSUMED, $waiting->fresh()->status);
        Queue::assertPushed(RefreshBrandCandidatesJob::class, 1);
        $this->assertSame(8.0, app(AiBudget::class)->dailyBudget());
        $this->assertSame(200.0, app(AiBudget::class)->monthlyBudget());

        config(['moxdop-ai-pricing.claude_api_window.until' => now('Europe/Istanbul')->subDay()->toDateString()]);
        $this->assertSame(4.0, app(AiBudget::class)->dailyBudget(), 'the raised ceiling ends by itself');
        $this->artisan('moxdop:ai:claude-api-window', ['action' => 'end'])->assertSuccessful();
        $this->assertSame(AiTaskQueue::MODEL, $registry->current('site.cluster_match')->model);
        $this->assertSame('openai:gpt-5-mini', $registry->current(AiRouteKeys::BRAND_SETUP)->model);
        $this->artisan('moxdop:ai:claude-api-window', ['action' => 'end'])->assertSuccessful();
    }

    private function pin(string $operation, string $model): void
    {
        $registry = app(PromptRegistry::class);
        $registry->publish($operation, ['template' => (string) $registry->current($operation)->template, 'model' => $model], $this->admin);
    }

    private function usage(float $cost, mixed $at = null, string $provider = 'anthropic'): void
    {
        DB::table('ai_usage_records')->insert([
            'route_key' => 'x', 'agent' => 'A', 'provider' => $provider, 'model' => $provider === 'anthropic' ? 'claude-haiku-5-5' : 'gpt-5-mini',
            'input_tokens' => 1, 'output_tokens' => 1, 'cost_usd' => $cost, 'created_at' => $at ?? now(),
        ]);
    }
}
