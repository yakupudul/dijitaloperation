<?php

namespace Tests\Feature\AiControl;

use App\Livewire\Operator\Settings\AiOperationsPage;
use App\Models\AgencySetting;
use App\Models\User;
use App\Services\Ai\AiBudget;
use App\Services\Ai\OpenAiCostAudit;
use App\Services\Ai\OpenAiFreeQuota;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * OpenAI ücretsiz paylaşım kotası: tokens inside the day's free quota are not counted as spend; the hourly Kota denetimi
 * compares OpenAI's real costs with the estimate and turns the quota accounting off when OpenAI billed more.
 */
final class OpenAiFreeQuotaTest extends TestCase
{
    use RefreshDatabase;

    private AgencySetting $setting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-02 06:00:00', 'UTC'));
        $this->setting = AgencySetting::query()->orderBy('id')->first() ?? AgencySetting::query()->create(['agency_name' => 'MoxDOP', 'portal_name' => 'MoxDOP']);
        $this->setting->forceFill(['ai_openai_free_quota' => true])->save();
    }

    private function openAiCall(string $model, int $tokens, float $cost, ?float $list = null, ?string $at = null, bool $shared = true): void
    {
        DB::table('ai_live_operations')->insert(['kind' => 'call', 'operation' => 'queries.cluster', 'label' => 'Kümele', 'agent' => 'QueryClusterAgent',
            'status' => 'done', 'provider' => 'openai', 'model' => $model, 'input_tokens' => $tokens, 'output_tokens' => 0, 'cost_usd' => $cost,
            'list_cost_usd' => $shared ? ($list ?? $cost) : null, 'started_at' => $at ?? now(), 'finished_at' => $at ?? now()]);
    }

    public function test_tokens_inside_the_days_free_quota_are_not_counted_and_the_rest_is_billed(): void
    {
        $quota = app(OpenAiFreeQuota::class);
        $this->openAiCall('gpt-5-mini', 2_000_000, 0.0, 0.8);
        $this->openAiCall('gpt-5-mini', 900_000, 0.4, 0.4, '2026-10-01 22:00:00'); // yesterday (UTC): another quota day
        $this->openAiCall('gpt-5-mini', 5_000_000, 2.0, null, null, shared: false); // before the sharing was on: never used the quota

        // 90 % of 2.5 M = 2.25 M counted; 250 k of these 500 k are free.
        $this->assertSame([0.5, 250_000], $quota->bill('openai', 'gpt-5-mini-2025-08-07', 500_000, 1.0));
        $this->assertSame([0.0, 100_000], $quota->bill('openai', 'gpt-5', 100_000, 1.0), 'the large models have their own 250 k');
        $this->assertSame([1.0, 0], $quota->bill('anthropic', 'claude-haiku-4-5', 100_000, 1.0));
        $this->assertSame([1.0, 0], $quota->bill('openai', 'gpt-5-pro', 100_000, 1.0), 'a model outside the quota');

        $this->setting->forceFill(['ai_openai_free_quota' => false])->save();
        $this->assertSame([1.0, 0], $quota->bill('openai', 'gpt-5-mini', 500_000, 1.0), 'off: list price');
    }

    public function test_the_free_quota_is_used_first_and_the_paid_ceiling_only_after_it(): void
    {
        $this->setting->forceFill(['ai_daily_auto_budget_usd' => 1])->save();
        $this->openAiCall('gpt-5-mini', 1_000_000, 3.0, 3.0, null, shared: false); // paid earlier today: the ceiling is spent
        $budget = app(AiBudget::class);
        $this->assertTrue($budget->dailyExhausted());

        $this->assertNull($budget->blockReason('openai', 'gpt-5-mini', 'queries.triage'), 'free tokens left: runs although $1 is spent');
        $this->assertNotNull($budget->blockReason('anthropic', 'claude-haiku-4-5', 'queries.triage'), 'no free quota there: the ceiling holds');

        $this->openAiCall('gpt-5-mini', 2_300_000, 0.0, 0.9); // the day's free tokens are used up
        $this->assertStringContainsString('Günlük AI tavanı doldu', (string) $budget->blockReason('openai', 'gpt-5-mini', 'queries.triage'));
        $this->assertNull($budget->blockReason('openai', 'gpt-5', 'queries.triage'), 'the large models have their own quota');

        $this->setting->forceFill(['ai_openai_free_quota' => false])->save();
        $this->assertNotNull($budget->blockReason('openai', 'gpt-5', 'queries.triage'));
    }

    public function test_the_audit_turns_the_quota_off_when_openai_billed_more_than_the_estimate_and_its_cost_floors_the_ceiling(): void
    {
        $this->openAiCall('gpt-5-mini', 2_000_000, 0.10, 3.20, '2026-10-01 12:00:00');
        $this->setting->forceFill(['ai_openai_admin_key' => 'sk-admin-test', 'ai_daily_auto_budget_usd' => 1])->save();
        Http::fake([OpenAiCostAudit::ENDPOINT.'*' => Http::response(['data' => [
            ['start_time' => CarbonImmutable::parse('2026-10-01', 'UTC')->getTimestamp(), 'results' => [['amount' => ['value' => 3.0, 'currency' => 'usd']]]],
            ['start_time' => CarbonImmutable::parse('2026-10-02', 'UTC')->getTimestamp(), 'results' => [['amount' => ['value' => 1.4, 'currency' => 'usd']]]],
        ]])]);

        $audit = app(OpenAiCostAudit::class)->run();

        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer sk-admin-test') && str_contains($request->url(), 'bucket_width=1d'));
        $this->assertSame('mismatch', $audit['status']);
        $this->assertStringContainsString('ücretsiz kota uygulanmıyor', $audit['message']);
        $this->assertFalse(app(OpenAiFreeQuota::class)->enabled(), 'turned off: the list price counts again');
        $this->assertSame([3.0, 0.1, 3.2], [$audit['days'][0]['actual'], $audit['days'][0]['estimated'], $audit['days'][0]['list']]);
        $this->assertGreaterThanOrEqual(1.4, app(AiBudget::class)->dailySpend(), 'OpenAI\'s real cost of today is a floor');
        $this->assertTrue(app(AiBudget::class)->dailyExhausted());
    }

    public function test_the_audit_keeps_the_quota_when_openai_agrees_and_asks_for_a_key_without_one(): void
    {
        $this->assertSame('no_key', app(OpenAiCostAudit::class)->run()['status']);

        $this->openAiCall('gpt-5-mini', 2_000_000, 0.10, 3.20, '2026-10-01 12:00:00');
        $this->setting->forceFill(['ai_openai_admin_key' => 'sk-admin-test'])->save();
        Http::fake([OpenAiCostAudit::ENDPOINT.'*' => Http::response(['data' => [
            ['start_time' => CarbonImmutable::parse('2026-10-01', 'UTC')->getTimestamp(), 'results' => [['amount' => ['value' => 0.11, 'currency' => 'usd']]]],
        ]])]);

        $audit = app(OpenAiCostAudit::class)->run();
        $this->assertSame('ok', $audit['status']);
        $this->assertStringContainsString('ücretsiz kota uygulanıyor', $audit['message']);
        $this->assertTrue(app(OpenAiFreeQuota::class)->enabled());
    }

    public function test_the_admin_turns_the_quota_on_with_a_key_and_sees_todays_quota(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->setting->forceFill(['ai_openai_free_quota' => false])->save();
        $this->openAiCall('gpt-5-mini', 1_000_000, 0.0, 0.4);

        Livewire::actingAs($admin)->test(AiOperationsPage::class)
            ->set('openAiFreeQuota', true)->set('openAiAdminKey', 'sk-admin-new')->call('saveBudget')
            ->assertSeeHtml('data-openai-quota')->assertSee('Küçük modeller 1.000k / 2.250k')->assertSee('kayıtlı');

        $this->setting->refresh();
        $this->assertSame([true, 'sk-admin-new'], [$this->setting->ai_openai_free_quota, $this->setting->ai_openai_admin_key]);
        $this->assertNotSame('sk-admin-new', DB::table('agency_settings')->where('id', $this->setting->id)->value('ai_openai_admin_key'), 'stored encrypted');
    }
}
