<?php

namespace App\Services\CommandCenter;

use App\Models\Task;
use Illuminate\Support\Collection;

/** Manual work-list tasks that are due today or late. */
final class TaskSource implements CommandCenterSource
{
    public const array CLOSED = ['completed', 'done', 'cancelled', 'canceled', 'closed'];

    public function items(): Collection
    {
        return Task::query()->with('brand')->whereNotIn('status', self::CLOSED)->whereNotNull('due_date')
            ->where('due_date', '<=', now()->toDateString())->orderBy('due_date')->limit(100)->get()
            ->map(fn (Task $task): array => CommandCenter::item('task', $task->id, $task->due_date->lt(now()->startOfDay()) ? 'high' : (in_array($task->priority, ['urgent', 'high'], true) ? 'high' : 'medium'),
                (string) $task->title, [
                    'detail' => $task->due_date->lt(now()->startOfDay()) ? 'Gecikti: '.$task->due_date->format('d.m.Y') : 'Bugün',
                    'brand_id' => $task->brand_id,
                    'brand' => $task->brand?->name,
                    'channel' => 'İş listesi',
                    'url' => route('operator.task', ['taskId' => $task->id]),
                    'actions' => ['done', 'snooze'],
                    'age' => $task->due_date,
                ]));
    }
}
