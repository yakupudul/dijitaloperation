<?php

namespace Tests\Feature\AiControl;

use App\Ai\Agents\GbpPostFromPageAgent;
use App\Livewire\Operator\AiLiveIndicator;
use App\Livewire\Operator\Settings\AiOperationsPage;
use App\Models\AiLiveOperation;
use App\Models\User;
use App\Services\Ai\AiLiveOperations;
use App\Services\Retention\DataRetentionService;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Canlı AI işlemleri: every agent call opens a live row and closes it done / failed; the header indicator and the
 * settings page show running calls and recent history.
 */
final class AiLiveOperationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true, 'name' => 'Yönetici']);
        $this->admin->assignRole(Roles::ADMIN);
        config(['ai.providers.anthropic.key' => 'configured']);
    }

    public function test_agent_call_is_recorded_running_then_done(): void
    {
        $this->actingAs($this->admin);
        $seenWhileRunning = null;
        GbpPostFromPageAgent::fake(function () use (&$seenWhileRunning): array {
            $seenWhileRunning = AiLiveOperation::query()->where('status', AiLiveOperation::RUNNING)->first()?->only(['operation', 'label', 'user_id', 'subject']);

            return ['text' => 'Metin', 'action_type' => 'BOOK'];
        });

        (new GbpPostFromPageAgent)->prompt('Kapak sayfasından gönderi yaz', provider: 'anthropic');

        $this->assertSame([
            'operation' => 'gbp.post_from_page', 'label' => 'Sayfadan gönderi yaz', 'user_id' => $this->admin->id, 'subject' => 'Kapak sayfasından gönderi yaz',
        ], $seenWhileRunning);
        $row = AiLiveOperation::query()->sole();
        $this->assertSame(AiLiveOperation::DONE, $row->status);
        $this->assertNotNull($row->finished_at);
        $this->assertNotNull($row->duration_ms);
        $this->assertNull($row->error);
    }

    public function test_data_payload_prompts_carry_no_subject(): void
    {
        GbpPostFromPageAgent::fake([['text' => 'Metin', 'action_type' => 'BOOK']]);

        (new GbpPostFromPageAgent)->prompt("CONTEXT_JSON\n{\"brand\":\"x\"}", provider: 'anthropic');

        $row = AiLiveOperation::query()->sole();
        $this->assertNull($row->subject);
        $this->assertNull($row->user_id);
    }

    public function test_call_that_throws_is_closed_as_failed_when_the_process_ends(): void
    {
        GbpPostFromPageAgent::fake(fn () => throw new RuntimeException('sağlayıcı kapalı'));

        try {
            (new GbpPostFromPageAgent)->prompt('Gönderi yaz', provider: 'anthropic');
            $this->fail('The fake call should throw.');
        } catch (RuntimeException) {
            // The caller swallowed the error: the row is still open until the request / job ends.
        }
        $this->assertSame(AiLiveOperation::RUNNING, AiLiveOperation::query()->sole()->status);

        app(AiLiveOperations::class)->closeOpen();

        $row = AiLiveOperation::query()->sole();
        $this->assertSame(AiLiveOperation::FAILED, $row->status);
        $this->assertSame('AI yanıtı alınamadı.', $row->error);
        $this->assertNotNull($row->finished_at);
    }

    public function test_queued_job_failure_closes_the_row_with_the_exception_message(): void
    {
        GbpPostFromPageAgent::fake(fn () => throw new RuntimeException('Kota doldu'));

        try {
            dispatch(function (): void {
                (new GbpPostFromPageAgent)->prompt('Gönderi yaz', provider: 'anthropic');
            });
        } catch (RuntimeException) {
            // The sync queue rethrows after firing JobExceptionOccurred.
        }

        $row = AiLiveOperation::query()->sole();
        $this->assertSame(AiLiveOperation::FAILED, $row->status);
        $this->assertSame('Kota doldu', $row->error);
    }

    public function test_rows_left_running_by_a_dead_process_are_swept(): void
    {
        $stale = $this->liveRow(['started_at' => now()->subHours(2)]);
        $fresh = $this->liveRow(['started_at' => now()->subMinute()]);

        $running = app(AiLiveOperations::class)->running();

        $this->assertSame([$fresh->id], $running->pluck('id')->all());
        $this->assertSame(AiLiveOperation::FAILED, $stale->fresh()->status);
    }

    public function test_header_indicator_lists_running_and_recent_calls(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(AiLiveIndicator::class)->assertDontSee('AI ·');

        $this->liveRow(['status' => AiLiveOperation::DONE, 'started_at' => now()->subHours(2), 'finished_at' => now()->subHours(2), 'duration_ms' => 1200]);
        Livewire::test(AiLiveIndicator::class)->assertDontSee('AI ·')->assertSeeHtml('wire:poll.30s');

        $this->liveRow(['label' => 'AI ile planla · hizmetler', 'user_id' => $this->admin->id, 'subject' => 'Diş sağlığı']);
        $this->liveRow(['status' => AiLiveOperation::FAILED, 'label' => 'Rakip analizi', 'error' => 'Sağlayıcı yanıtı: HTTP 529', 'finished_at' => now(), 'duration_ms' => 4200]);

        Livewire::test(AiLiveIndicator::class)
            ->assertSee('AI · 1')
            ->assertSee('AI ile planla · hizmetler')
            ->assertSee('Yönetici')
            ->assertSee('Diş sağlığı')
            ->assertSee('Rakip analizi')
            ->assertSee('Başarısız · 4,2 sn')
            ->assertSee('Sağlayıcı yanıtı: HTTP 529')
            ->assertSee('Tümünü gör')
            ->assertSee('Ayarlar › AI işlemleri')
            ->assertSeeHtml('wire:poll.5s');
    }

    public function test_indicator_stays_visible_briefly_after_the_last_call_finished(): void
    {
        $this->actingAs($this->admin);
        $this->liveRow(['status' => AiLiveOperation::DONE, 'label' => 'Sorgu kümeleme', 'finished_at' => now()->subMinute(), 'duration_ms' => 800]);

        Livewire::test(AiLiveIndicator::class)->assertSee('AI · 0')->assertSee('Sorgu kümeleme')->assertSee('Bitti · 0,8 sn')->assertSeeHtml('wire:poll.30s');
    }

    public function test_the_operators_own_ai_work_that_ends_pops_up_and_background_work_stays_quiet(): void
    {
        $this->actingAs($this->admin);
        $mine = $this->liveRow(['label' => 'SEO analizi', 'user_id' => $this->admin->id]);
        $background = $this->liveRow(['label' => 'Sorgu otomatik pilotu', 'user_id' => null]);
        $page = Livewire::test(AiLiveIndicator::class)->assertSee('AI · 2')->assertNotDispatched('operator-notice');

        $mine->forceFill(['status' => AiLiveOperation::DONE, 'finished_at' => now(), 'duration_ms' => 900])->save();
        $background->forceFill(['status' => AiLiveOperation::DONE, 'finished_at' => now(), 'duration_ms' => 900])->save();
        $page->dispatch('ai-live-refresh')
            ->assertDispatched('operator-notice', message: 'AI işi bitti: SEO analizi', tone: 'success')
            ->assertNotDispatched('operator-notice', message: 'AI işi bitti: Sorgu otomatik pilotu');
    }

    public function test_settings_page_and_layout_show_the_live_section(): void
    {
        $this->liveRow(['label' => 'Meta kreatif önerisi', 'operation' => 'meta.creatives']);

        $this->actingAs($this->admin)->get(route('operator.settings.ai-operations'))
            ->assertOk()
            ->assertSee('Canlı')
            ->assertSee('Meta kreatif önerisi')
            ->assertSeeHtml('data-ai-live-running')
            ->assertSeeHtml('data-ai-live-indicator')
            ->assertSeeHtml('data-ai-prompt-info-modal');
    }

    public function test_the_ai_operations_page_shows_where_each_running_job_is_and_what_runs_next(): void
    {
        $job = $this->liveRow(['kind' => AiLiveOperation::KIND_JOB, 'label' => 'Kümeleri içerikle eşleştir', 'subject' => 'Panorama Ankara · panorama.com.tr',
            'link' => '/assets/5?tab=sorgular', 'user_id' => null]);
        $this->liveRow(['parent_id' => $job->id, 'label' => 'Küme ↔ içerik eşleştirme', 'status' => AiLiveOperation::DONE, 'finished_at' => now(), 'cost_usd' => 0.02, 'model' => 'claude-x']);
        $this->liveRow(['parent_id' => $job->id, 'label' => 'Küme eksikleri', 'model' => 'claude-x', 'cost_usd' => null]);
        $queued = $this->liveRow(['kind' => AiLiveOperation::KIND_JOB, 'label' => 'SEO analizi', 'status' => AiLiveOperation::QUEUED, 'queued_at' => now(), 'user_id' => $this->admin->id]);
        $this->liveRow(['label' => 'Marka bakım ajanı', 'status' => AiLiveOperation::FAILED, 'finished_at' => now(), 'error' => 'Sağlayıcı yanıtı: HTTP 529']);

        Livewire::actingAs($this->admin)->test(AiOperationsPage::class)
            ->assertSeeHtml('data-ai-summary')->assertSeeHtml('wire:poll.5s')->assertSeeHtml('data-ai-costs')->assertSeeHtml('data-ai-auto-budget')
            ->assertSee('Kümeleri içerikle eşleştir')->assertSee('Panorama Ankara › panorama.com.tr')
            ->assertSeeHtml('data-ai-step')->assertSee('Küme eksikleri')->assertSee('1 çağrı bitti')->assertSee('Başlatan: Otomatik')
            ->assertSeeHtml('data-ai-queued')->assertSee('SEO analizi')
            ->assertSeeHtml('data-ai-finished')->assertSee('Sağlayıcı yanıtı: HTTP 529')->assertSee('1 hata')
            ->assertSeeHtml('data-ai-schedule')->assertSee('Site akışı + marka dosyası')->assertSee('Şef: denetim + haftalık plan')
            ->call('stop', $queued->id);

        $this->assertSame(AiLiveOperation::CANCELLED, $queued->fresh()->status);
    }

    public function test_live_rows_are_kept_thirty_days_by_default(): void
    {
        $old = $this->liveRow(['status' => AiLiveOperation::DONE, 'started_at' => now()->subDays(31), 'finished_at' => now()->subDays(31)]);
        $kept = $this->liveRow(['status' => AiLiveOperation::DONE, 'started_at' => now()->subDays(20), 'finished_at' => now()->subDays(20)]);

        $result = app(DataRetentionService::class)->purgeTelemetry();

        $this->assertSame(1, $result['ai_live_operations']);
        $this->assertNull($old->fresh());
        $this->assertNotNull($kept->fresh());
    }

    /** @param  array<string, mixed>  $attributes */
    private function liveRow(array $attributes = []): AiLiveOperation
    {
        return AiLiveOperation::query()->create([
            'operation' => 'queries.plan_services', 'label' => 'AI ile planla · hizmetler', 'agent' => 'QueryPlanServicesAgent',
            'status' => AiLiveOperation::RUNNING, 'started_at' => now()->subSeconds(3), ...$attributes,
        ]);
    }
}
