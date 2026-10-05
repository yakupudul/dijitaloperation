<?php

namespace App\Livewire\Operator;

use App\Models\AiLiveOperation;
use App\Models\User;
use App\Services\Ai\AiLiveOperations;
use App\Services\Prompts\PromptRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Header "AI · N": visible while AI work runs or waits in the queue, or some finished in the last few minutes; polled
 * every 5 s while something runs, every 30 s otherwise, and at once after an operator action (`ai-live-refresh`). Work
 * that ends pops up as a notice ("AI işi bitti: …"). The dropdown lists running work, queued jobs and the last 10
 * finished; each item opens its detail in AI işleri, "Tümünü gör" opens the page.
 */
final class AiLiveIndicator extends Component
{
    /** Running / queued work ids seen in the previous render, comma separated (to announce what finished since). A plain
     * string: an array property here was diffed by the browser into an invalid "$" update on some polls. */
    public string $watching = '';

    /** An operator click that may have started AI work: refresh now instead of waiting for the next poll. */
    #[On('ai-live-refresh')]
    public function refreshNow(): void {}

    public function render(AiLiveOperations $live): View
    {
        $running = auth()->check() ? $live->running() : new EloquentCollection;
        $this->announceFinished($live, $running);
        $visible = $running->isNotEmpty() || (auth()->check() && $live->hasRecent());
        $queued = $visible ? $live->queued(10) : new EloquentCollection;
        $finished = $visible ? $live->finishedRecently(10) : new EloquentCollection;

        return view('livewire.operator.ai-live-indicator', [
            'running' => $running,
            'queued' => $queued,
            'finished' => $finished,
            'visible' => $visible,
            'users' => self::userNames($running->concat($queued)->concat($finished)),
            'canOpenSettings' => PromptRegistry::canEdit(auth()->user()),
        ]);
    }

    /**
     * The operator's own work that was running at the previous render and has ended now pops up as a notice (bitti / hata); background work (autopilot) stays quiet.
     *
     * @param  EloquentCollection<int, AiLiveOperation>  $running  this render's running work (read once per render)
     */
    private function announceFinished(AiLiveOperations $live, EloquentCollection $running): void
    {
        if (! auth()->check()) {
            return;
        }
        $open = $running->concat($live->queued(20))->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
        $ended = array_values(array_diff(array_map('intval', array_filter(explode(',', $this->watching))), $open));
        if ($ended !== []) {
            foreach (AiLiveOperation::query()->whereIn('id', $ended)->where('user_id', auth()->id())->whereIn('status', [AiLiveOperation::DONE, AiLiveOperation::FAILED])->limit(3)->get() as $row) {
                $this->dispatch('operator-notice', message: ($row->status === AiLiveOperation::DONE ? 'AI işi bitti: ' : 'AI işi hata verdi: ').$row->label,
                    tone: $row->status === AiLiveOperation::DONE ? 'success' : 'error');
            }
        }
        $this->watching = implode(',', $open);
    }

    /**
     * @param  Collection<int, AiLiveOperation>  $rows
     * @return array<int, string> user id => name
     */
    public static function userNames(Collection $rows): array
    {
        $ids = $rows->pluck('user_id')->filter()->unique()->values()->all();

        return $ids === [] ? [] : User::query()->whereIn('id', $ids)->pluck('name', 'id')->map(fn ($name): string => (string) $name)->all();
    }
}
