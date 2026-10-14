<?php

namespace App\Livewire\Operator;

use App\Models\AiLiveOperation;
use App\Models\AiTask;
use App\Models\Brand;
use App\Support\Ai\AiOperationLabels;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * "Şu an sistemde" (yakup, 2026-10-14 "sistem ruhu"): what MoxDOP and Claude are doing right now, on the dashboard.
 * Claude's open queue work grouped by operation and brand, today's counters, and a stream of the latest finished work
 * (Claude answers with a short preview of what it wrote, and background jobs). Polled every 4 s while something
 * works or waits, every 20 s otherwise; new rows slide in.
 */
final class LiveFeed extends Component
{
    private const int STREAM = 12;

    public function render(): View
    {
        $working = AiTask::query()->where('status', AiTask::CLAIMED)
            ->selectRaw('operation, brand_id, count(*) as n, min(claimed_at) as since, max(subject) as subject')
            ->groupBy('operation', 'brand_id')->orderByDesc('n')->limit(4)->get();
        $waiting = AiTask::query()->where('status', AiTask::PENDING)->count();
        $since = Carbon::today();
        $doneToday = AiTask::query()->whereIn('status', [AiTask::DONE, AiTask::CONSUMED])->where('completed_at', '>=', $since)->count();
        $failedToday = AiTask::query()->where('status', AiTask::FAILED)->where('completed_at', '>=', $since)->count();
        $jobsToday = AiLiveOperation::query()->whereNull('parent_id')->where('status', AiLiveOperation::DONE)->where('finished_at', '>=', $since)->count();
        $running = AiLiveOperation::query()->whereNull('parent_id')->where('status', AiLiveOperation::RUNNING)->count();

        $stream = $this->finishedWork();
        $brands = Brand::query()->whereIn('id', $working->pluck('brand_id')->concat($stream->pluck('brand_id'))->filter()->unique()->all())->pluck('name', 'id');

        return view('livewire.operator.live-feed', [
            'working' => $working->map(fn (AiTask $row): array => [
                'label' => AiOperationLabels::for($row->operation),
                'brand' => $brands[$row->brand_id] ?? null,
                'count' => (int) $row->getAttribute('n'),
                'subject' => $row->subject,
                'since' => $row->getAttribute('since') ? Carbon::parse($row->getAttribute('since'))->diffForHumans(short: true) : null,
            ]),
            'waiting' => $waiting,
            'running' => $running,
            'doneToday' => $doneToday,
            'failedToday' => $failedToday,
            'jobsToday' => $jobsToday,
            'stream' => $stream->map(fn (array $row): array => $row + ['brand' => $brands[$row['brand_id']] ?? null]),
            'busy' => $working->isNotEmpty() || $waiting > 0 || $running > 0,
        ]);
    }

    /**
     * Latest finished work, newest first: Claude queue answers and background AI jobs.
     *
     * @return Collection<int, array{key: string, at: Carbon, ok: bool, who: string, label: string, brand_id: int|null, subject: string|null, preview: string|null}>
     */
    private function finishedWork(): Collection
    {
        $tasks = AiTask::query()->whereIn('status', [AiTask::DONE, AiTask::CONSUMED, AiTask::FAILED])->whereNotNull('completed_at')
            ->orderByDesc('completed_at')->orderByDesc('id')->limit(self::STREAM)
            ->get(['id', 'operation', 'brand_id', 'subject', 'status', 'output', 'error', 'completed_at'])
            ->map(fn (AiTask $task): array => [
                'key' => 'task-'.$task->id,
                'at' => $task->completed_at,
                'ok' => $task->status !== AiTask::FAILED,
                'who' => 'Claude',
                'label' => AiOperationLabels::for($task->operation),
                'brand_id' => $task->brand_id,
                'subject' => $task->subject,
                'preview' => $task->status === AiTask::FAILED ? Str::limit((string) $task->error, 120) : self::preview($task->output),
            ]);
        $jobs = AiLiveOperation::query()->whereNull('parent_id')->whereIn('status', [AiLiveOperation::DONE, AiLiveOperation::FAILED])->whereNotNull('finished_at')
            ->orderByDesc('finished_at')->orderByDesc('id')->limit(self::STREAM)->get()
            ->map(fn (AiLiveOperation $row): array => [
                'key' => 'live-'.$row->id,
                'at' => $row->finished_at,
                'ok' => $row->status === AiLiveOperation::DONE,
                'who' => 'MoxDOP',
                'label' => (string) $row->label,
                'brand_id' => null,
                'subject' => $row->subject,
                'preview' => $row->status === AiLiveOperation::FAILED ? Str::limit((string) $row->error, 120) : null,
            ]);

        return $tasks->concat($jobs)->sortByDesc(fn (array $row): int => $row['at']->getTimestamp())->take(self::STREAM)->values();
    }

    /** The first readable text Claude wrote in its answer, shortened (the stream shows what was produced, not just that it was). */
    public static function preview(mixed $output): ?string
    {
        if (! is_array($output)) {
            return null;
        }
        $text = null;
        array_walk_recursive($output, function (mixed $value) use (&$text): void {
            if ($text === null && is_string($value) && mb_strlen(trim(strip_tags($value))) >= 12) {
                $text = trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?? '');
            }
        });

        return $text === null ? null : Str::limit($text, 120);
    }
}
