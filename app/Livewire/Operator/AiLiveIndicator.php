<?php

namespace App\Livewire\Operator;

use App\Models\AiLiveOperation;
use App\Models\User;
use App\Services\Ai\AiLiveOperations;
use App\Services\Prompts\PromptRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Header "AI · N": visible while AI work runs or waits in the queue, or some finished in the last few minutes; polled
 * every 5 s while something runs, every 30 s otherwise. The dropdown lists running work, queued jobs and the last 10
 * finished; each item opens its detail in AI işleri, "Tümünü gör" opens the page.
 */
final class AiLiveIndicator extends Component
{
    public function render(AiLiveOperations $live): View
    {
        $running = auth()->check() ? $live->running() : new EloquentCollection;
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
     * @param  Collection<int, AiLiveOperation>  $rows
     * @return array<int, string> user id => name
     */
    public static function userNames(Collection $rows): array
    {
        $ids = $rows->pluck('user_id')->filter()->unique()->values()->all();

        return $ids === [] ? [] : User::query()->whereIn('id', $ids)->pluck('name', 'id')->map(fn ($name): string => (string) $name)->all();
    }
}
