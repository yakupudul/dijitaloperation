<?php

namespace Tests\Feature\AiControl;

use App\Livewire\Operator\LiveFeed;
use App\Models\AiLiveOperation;
use App\Models\AiTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Şu an sistemde" (yakup, 2026-10-14 "sistem ruhu"): the dashboard shows Claude's open queue work, today's counters
 * and the latest finished work with a preview of what Claude wrote.
 */
final class LiveFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_shows_what_claude_is_doing_and_what_it_just_wrote(): void
    {
        $this->task(AiTask::CLAIMED, ['claimed_at' => now()->subMinute(), 'subject' => 'Ankara implant sorguları']);
        $this->task(AiTask::PENDING);
        $this->task(AiTask::DONE, ['completed_at' => now(), 'output' => ['title' => 'İmplant sonrası ilk hafta nelere dikkat edilir', 'n' => 3]]);
        $this->task(AiTask::FAILED, ['completed_at' => now()->subMinutes(2), 'error' => 'Girdide küme sorgusu yok.']);
        AiLiveOperation::query()->create(['label' => 'Site taraması', 'agent' => 'job', 'invocation_id' => 'inv-1', 'status' => AiLiveOperation::DONE, 'kind' => AiLiveOperation::KIND_JOB,
            'started_at' => now()->subMinutes(3), 'finished_at' => now()->subMinute()]);

        Livewire::test(LiveFeed::class)
            ->assertSee('Şu an sistemde')
            ->assertSee('çalışıyor')
            ->assertSee('Ankara implant sorguları')
            ->assertSee('“İmplant sonrası ilk hafta nelere dikkat edilir”')
            ->assertSee('Girdide küme sorgusu yok.')
            ->assertSee('Site taraması')
            ->assertSeeHtml('wire:poll.4s.visible');
    }

    public function test_quiet_system_polls_slowly(): void
    {
        Livewire::test(LiveFeed::class)->assertSee('sakin, bekleyen iş yok')->assertSee('Henüz biten iş yok.')->assertSeeHtml('wire:poll.20s.visible');
    }

    public function test_preview_takes_the_first_readable_text_of_an_answer(): void
    {
        $this->assertSame('Kısa metin yeterince uzun', LiveFeed::preview(['id' => 5, 'items' => [['label' => 'ok'], ['text' => '<p>Kısa metin   yeterince uzun</p>']]]));
        $this->assertNull(LiveFeed::preview(['n' => 1]));
        $this->assertNull(LiveFeed::preview(null));
    }

    /** @param  array<string, mixed>  $extra */
    private function task(string $status, array $extra = []): AiTask
    {
        static $n = 0;
        $n++;

        return AiTask::query()->create(['operation' => 'queries.triage', 'resume_key' => hash('sha256', 'r'.$n), 'sequence' => 1, 'input_hash' => 'h'.$n,
            'status' => $status, 'instructions' => 'x', 'input' => 'DATA_JSON {}', 'output_schema' => [], 'resume' => 'N;'] + $extra);
    }
}
