<?php

namespace Tests\Feature\AiControl;

use App\Ai\Agents\GbpPostFromPageAgent;
use App\Contracts\Ai\TracksAiJob;
use App\Jobs\Queries\PlanQueriesJob;
use App\Livewire\Operator\AiJobsPage;
use App\Livewire\Operator\AiLiveIndicator;
use App\Models\AiLiveOperation;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\Ai\AiCancellation;
use App\Services\Ai\AiLiveOperations;
use App\Services\AiJobs\AiJobTracker;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * AI işleri: queued AI jobs are recorded Sırada → Çalışıyor → Bitti; calls keep their input / output; "Durdur" removes a
 * queued job or stops a running multi-call job between calls ("Durduruldu"); history delete is Admin only; header items
 * open the detail.
 */
final class AiJobsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true, 'name' => 'Yönetici']);
        $this->admin->assignRole(Roles::ADMIN);
        $this->member = User::factory()->create(['is_active' => true, 'name' => 'Ekip üyesi']);
        $this->member->assignRole(Roles::TEAM_MEMBER);
        config(['ai.providers.anthropic.key' => 'configured']);
        FakeMultiCallAiJob::$outputs = [];
        FakeMultiCallAiJob::$handled = 0;
    }

    public function test_queued_job_is_recorded_queued_then_running_then_done_with_its_calls(): void
    {
        config(['queue.default' => 'database']);
        $this->actingAs($this->admin);
        GbpPostFromPageAgent::fake([['text' => 'Birinci', 'action_type' => 'BOOK'], ['text' => 'İkinci', 'action_type' => 'BOOK']]);

        FakeMultiCallAiJob::dispatch(2);

        $job = AiLiveOperation::query()->sole();
        $this->assertSame(AiLiveOperation::KIND_JOB, $job->kind);
        $this->assertSame(AiLiveOperation::QUEUED, $job->status);
        $this->assertSame('Test çok çağrılı iş', $job->label);
        $this->assertSame($this->admin->id, $job->user_id);
        $this->assertSame('database', $job->queue_connection);
        $this->assertNotNull($job->queue_job_id);
        $this->assertNotNull($job->job_uuid);

        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'default'])->assertExitCode(0);

        $job->refresh();
        $this->assertSame(AiLiveOperation::DONE, $job->status);
        $this->assertNotNull($job->finished_at);
        $this->assertSame(['Birinci', 'İkinci'], FakeMultiCallAiJob::$outputs);
        $calls = AiLiveOperation::query()->where('parent_id', $job->id)->orderBy('id')->get();
        $this->assertCount(2, $calls);
        $this->assertSame([AiLiveOperation::DONE, AiLiveOperation::DONE], $calls->pluck('status')->all());
        $this->assertSame('Sayfadan gönderi yaz 1', $calls[0]->input_text);
        $this->assertStringContainsString('"text": "Birinci"', (string) $calls[0]->output_text);
        $this->assertSame($job->job_uuid, $calls[0]->job_uuid);
        $this->assertSame($this->admin->id, $calls[0]->user_id);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_sync_dispatched_tracked_job_is_recorded_running_then_done(): void
    {
        GbpPostFromPageAgent::fake([['text' => 'Tek', 'action_type' => 'BOOK']]);

        FakeMultiCallAiJob::dispatch(1);

        $job = AiLiveOperation::query()->where('kind', AiLiveOperation::KIND_JOB)->sole();
        $this->assertSame(AiLiveOperation::DONE, $job->status);
        $this->assertSame(1, AiLiveOperation::query()->where('parent_id', $job->id)->count());
    }

    public function test_known_ai_job_classes_are_recorded_when_queued(): void
    {
        config(['queue.default' => 'database']);
        $sector = ServiceCategory::query()->create(['code' => 'dis', 'name' => 'Diş sağlığı', 'normalized_key' => 'dis']);
        $this->actingAs($this->admin);

        PlanQueriesJob::dispatch($this->admin->id, 'services', [(int) $sector->id]);

        $row = AiLiveOperation::query()->sole();
        $this->assertSame(AiLiveOperation::QUEUED, $row->status);
        $this->assertSame('AI ile planla · Hizmet keşfet', $row->label);
        $this->assertSame('queries.plan_services', $row->operation);
        $this->assertSame('Diş sağlığı', $row->subject);
        $this->assertSame(route('operator.library.queries.plan', [], false), $row->link);
    }

    public function test_detail_shows_input_output_prompt_version_and_the_job(): void
    {
        $job = $this->row(['kind' => AiLiveOperation::KIND_JOB, 'label' => 'AI ile planla · Hizmet keşfet', 'status' => AiLiveOperation::DONE,
            'link' => '/library/queries/plan', 'job_uuid' => 'uuid-1', 'job_class' => PlanQueriesJob::class, 'cost_usd' => 0.012, 'finished_at' => now()]);
        $call = $this->row(['parent_id' => $job->id, 'status' => AiLiveOperation::DONE, 'provider' => 'anthropic', 'model' => 'model-x',
            'input_text' => "DATA_JSON\n{\"sector\":\"Diş\"}", 'output_text' => '{"services": ["İmplant"]}', 'input_tokens' => 1200, 'output_tokens' => 300,
            'cost_usd' => 0.012, 'duration_ms' => 2500, 'finished_at' => now()]);

        $this->actingAs($this->member);
        Livewire::withQueryParams(['is' => $job->id])->test(AiJobsPage::class)
            ->assertSee('AI ile planla · Hizmet keşfet')
            ->assertSeeHtml('data-ai-job-detail="'.$job->id.'"')
            ->assertSeeHtml('data-ai-job-call="'.$call->id.'"')
            ->assertSeeHtml('href="/library/queries/plan"')
            ->assertDontSeeHtml('data-ai-job-stop')
            ->call('show', $call->id)
            ->assertSeeHtml('data-ai-job-detail="'.$call->id.'"')
            ->assertSee('DATA_JSON')
            ->assertSee('İmplant')
            ->assertSee('anthropic · model-x')
            ->assertSee('1.200')
            ->assertSee('AI ile planla · Hizmet keşfet (#'.$job->id.')');
    }

    public function test_list_filters_by_status_operation_and_user(): void
    {
        $this->row(['label' => 'Çalışan iş', 'user_id' => $this->admin->id]);
        $this->row(['label' => 'Biten iş', 'status' => AiLiveOperation::DONE, 'operation' => 'meta.creatives', 'finished_at' => now()]);
        $this->row(['label' => 'Alt çağrı', 'parent_id' => 999]);

        $this->actingAs($this->member);
        Livewire::test(AiJobsPage::class)->assertSee('Çalışan iş')->assertSee('Biten iş')->assertDontSee('Alt çağrı')
            ->set('status', AiLiveOperation::DONE)->assertDontSee('Çalışan iş')->assertSee('Biten iş')
            ->set('status', '')->set('operation', 'meta.creatives')->assertDontSee('Çalışan iş')->assertSee('Biten iş')
            ->set('operation', '')->set('user', (string) $this->admin->id)->assertSee('Çalışan iş')->assertDontSee('Biten iş');
    }

    public function test_stop_asks_a_running_multi_call_job_to_stop_between_calls(): void
    {
        config(['queue.default' => 'database']);
        $calls = 0;
        GbpPostFromPageAgent::fake(function () use (&$calls): array {
            $calls++;
            if ($calls === 1) {
                // The operator presses "Durdur" (another process) while the first call is in flight.
                $message = app(AiJobTracker::class)->cancel(AiLiveOperation::query()->where('kind', AiLiveOperation::KIND_JOB)->sole(), $this->admin);
                $this->assertStringStartsWith('Durdurma isteği gönderildi', (string) $message);
            }

            return ['text' => 'Sonuç '.$calls, 'action_type' => 'BOOK'];
        });

        FakeMultiCallAiJob::dispatch(3);
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'default'])->assertExitCode(0);

        $this->assertSame(1, $calls, 'No AI call starts after the stop request.');
        $this->assertSame([], FakeMultiCallAiJob::$outputs, 'The in-flight call finishes but its result is discarded.');
        $job = AiLiveOperation::query()->where('kind', AiLiveOperation::KIND_JOB)->sole();
        $this->assertSame(AiLiveOperation::CANCELLED, $job->status);
        $this->assertSame('Durduruldu', $job->statusLabel());
        $this->assertSame($this->admin->id, $job->cancel_requested_by);
        $call = AiLiveOperation::query()->where('parent_id', $job->id)->sole();
        $this->assertSame(AiLiveOperation::CANCELLED, $call->status);
        $this->assertStringContainsString('sonucu kullanılmadı', (string) $call->error);
        $this->assertNotNull($call->output_text);
        $this->assertSame(0, DB::table('jobs')->count(), 'The stopped job completes; it is not retried.');
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_stop_button_on_a_running_job_shows_the_request_was_sent(): void
    {
        $job = $this->row(['kind' => AiLiveOperation::KIND_JOB, 'job_uuid' => 'uuid-3']);
        $this->actingAs($this->admin);

        Livewire::withQueryParams(['is' => $job->id])->test(AiJobsPage::class)
            ->assertSeeHtml('data-ai-job-stop')
            ->call('stop', $job->id)
            ->assertSee('Durdurma isteği gönderildi')
            ->assertSeeHtml('data-ai-job-stop-requested')
            ->assertDontSeeHtml('data-ai-job-stop>')
            ->assertSee('Durduruluyor');
        $this->assertNotNull($job->fresh()->cancel_requested_at);
        $this->assertSame(AiLiveOperation::RUNNING, $job->fresh()->status);
    }

    public function test_stop_removes_a_queued_job_from_a_database_queue(): void
    {
        config(['queue.default' => 'database']);
        $this->actingAs($this->admin);
        FakeMultiCallAiJob::dispatch(1);
        $row = AiLiveOperation::query()->sole();
        $this->assertSame(1, DB::table('jobs')->count());

        Livewire::test(AiJobsPage::class)->call('stop', $row->id)->assertSee('İş kuyruktan kaldırıldı.');

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(AiLiveOperation::CANCELLED, $row->fresh()->status);
    }

    public function test_a_cancelled_queued_job_the_queue_still_holds_is_dropped_unrun(): void
    {
        config(['queue.default' => 'database']);
        FakeMultiCallAiJob::dispatch(1);
        // A queue we could not delete from (e.g. Redis): the row is cancelled, the job stays in the queue.
        AiLiveOperation::query()->update(['status' => AiLiveOperation::CANCELLED, 'queue_connection' => 'redis']);

        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'default'])->assertExitCode(0);

        $this->assertSame(0, FakeMultiCallAiJob::$handled);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(AiLiveOperation::CANCELLED, AiLiveOperation::query()->sole()->status);
    }

    public function test_cancellation_helper_does_nothing_outside_a_tracked_job(): void
    {
        AiCancellation::throwIfRequested();

        $this->assertFalse(AiCancellation::requested());
    }

    public function test_stop_and_delete_are_admin_only(): void
    {
        $running = $this->row(['kind' => AiLiveOperation::KIND_JOB, 'job_uuid' => 'uuid-2']);
        $done = $this->row(['status' => AiLiveOperation::DONE, 'finished_at' => now()]);

        $this->actingAs($this->member);
        $this->get(route('operator.ai-jobs'))->assertOk()->assertSee('AI işleri')->assertDontSee('Seçilenleri sil');
        Livewire::test(AiJobsPage::class)->call('stop', $running->id)->assertForbidden();
        Livewire::test(AiJobsPage::class)->set('selected', [$done->id])->call('deleteSelected')->assertForbidden();
        Livewire::test(AiJobsPage::class)->call('deleteOlder')->assertForbidden();
        $this->assertNull($running->fresh()->cancel_requested_at);
        $this->assertNotNull($done->fresh());
    }

    public function test_admin_deletes_selected_and_older_rows_but_never_open_ones(): void
    {
        $job = $this->row(['kind' => AiLiveOperation::KIND_JOB, 'status' => AiLiveOperation::DONE, 'finished_at' => now()]);
        $child = $this->row(['parent_id' => $job->id, 'status' => AiLiveOperation::DONE, 'finished_at' => now()]);
        $running = $this->row([]);
        $old = $this->row(['status' => AiLiveOperation::FAILED, 'started_at' => now()->subDays(20), 'finished_at' => now()->subDays(20)]);
        $recent = $this->row(['status' => AiLiveOperation::DONE, 'started_at' => now()->subDays(2), 'finished_at' => now()->subDays(2)]);

        $this->actingAs($this->admin);
        Livewire::test(AiJobsPage::class)->set('selected', [$job->id, $running->id])->call('deleteSelected')->assertSee('1 kayıt silindi.');
        $this->assertNull($job->fresh());
        $this->assertNull($child->fresh());
        $this->assertNotNull($running->fresh());

        Livewire::test(AiJobsPage::class)->set('olderThanDays', '10')->call('deleteOlder');
        $this->assertNull($old->fresh());
        $this->assertNotNull($recent->fresh());
        $this->assertNotNull($running->fresh());
    }

    public function test_header_items_open_the_detail_and_link_to_the_page(): void
    {
        $this->actingAs($this->member);
        $running = $this->row(['label' => 'AI ile hizmet öner', 'kind' => AiLiveOperation::KIND_JOB]);
        $queued = $this->row(['label' => 'AI ile kümele', 'kind' => AiLiveOperation::KIND_JOB, 'status' => AiLiveOperation::QUEUED, 'queued_at' => now()]);
        $done = $this->row(['label' => 'Rakip analizi', 'status' => AiLiveOperation::DONE, 'finished_at' => now(), 'duration_ms' => 900]);

        Livewire::test(AiLiveIndicator::class)
            ->assertSee('AI · 1')
            ->assertSee('+1 sırada')
            ->assertSeeHtml('href="'.route('operator.ai-jobs', ['is' => $running->id]).'"')
            ->assertSeeHtml('href="'.route('operator.ai-jobs', ['is' => $queued->id]).'"')
            ->assertSeeHtml('href="'.route('operator.ai-jobs', ['is' => $done->id]).'"')
            ->assertSee('Tümünü gör')
            ->assertSeeHtml('href="'.route('operator.ai-jobs').'"')
            ->assertDontSee('Ayarlar › AI işlemleri');
    }

    public function test_calls_keep_a_capped_copy_of_long_input(): void
    {
        $capped = AiLiveOperations::cap(str_repeat('ş', 50000));

        $this->assertLessThanOrEqual(AiLiveOperations::TEXT_MAX_BYTES, strlen((string) $capped));
        $this->assertTrue(mb_check_encoding((string) $capped, 'UTF-8'));
        $this->assertStringEndsWith('(kısaltıldı: ilk 64 KB)', (string) $capped);
    }

    /** @param  array<string, mixed>  $attributes */
    private function row(array $attributes): AiLiveOperation
    {
        return AiLiveOperation::query()->create([
            'operation' => 'queries.plan_services', 'label' => 'AI ile planla · hizmetler', 'agent' => 'QueryPlanServicesAgent',
            'status' => AiLiveOperation::RUNNING, 'started_at' => now()->subSeconds(3), ...$attributes,
        ]);
    }
}

/** A queued AI job making several calls with the cooperative stop check between them. */
final class FakeMultiCallAiJob implements ShouldQueue, TracksAiJob
{
    use Queueable;

    /** @var list<string> */
    public static array $outputs = [];

    public static int $handled = 0;

    public function __construct(public int $calls) {}

    public function handle(): void
    {
        self::$handled++;
        for ($i = 1; $i <= $this->calls; $i++) {
            AiCancellation::throwIfRequested();
            $response = (new GbpPostFromPageAgent)->prompt('Sayfadan gönderi yaz '.$i, provider: 'anthropic');
            self::$outputs[] = (string) ($response['text'] ?? '');
        }
    }

    public function aiLabel(): string
    {
        return 'Test çok çağrılı iş';
    }

    public function aiOperation(): ?string
    {
        return 'gbp.post_from_page';
    }

    public function aiResultUrl(): ?string
    {
        return null;
    }
}
