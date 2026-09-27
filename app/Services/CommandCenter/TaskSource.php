<?php

namespace App\Services\CommandCenter;

use App\Models\Task;
use App\Support\ServiceScope;
use Illuminate\Support\Collection;

/** Manual work-list tasks that are due today or late. */
final class TaskSource implements CommandCenterSource
{
    public const array CLOSED = ['completed', 'done', 'cancelled', 'canceled', 'closed'];

    public function items(): Collection
    {
        $scope = app(ServiceScope::class);

        return $scope->constrain(Task::query())->where(fn ($q) => $q->whereNull('customer_id')->orWhereIn('customer_id', $scope->customerIdQuery()))
            ->with('brand')->whereNotIn('status', self::CLOSED)->whereNotNull('due_date')
            ->where('due_date', '<=', now()->toDateString())->orderBy('due_date')->limit(100)->get()
            ->map(fn (Task $task): array => CommandCenter::item('task', $task->id, $task->due_date->lt(now()->startOfDay()) ? 'high' : (in_array($task->priority, ['urgent', 'high'], true) ? 'high' : 'medium'),
                (string) $task->title, [
                    'detail' => $task->due_date->lt(now()->startOfDay()) ? 'Gecikti: '.$task->due_date->format('d.m.Y') : 'Bugün',
                    'brand_id' => $task->brand_id,
                    'asset_id' => $task->digital_asset_id,
                    'customer_id' => $task->customer_id,
                    'brand' => $task->brand?->name,
                    'channel' => 'İş listesi',
                    'url' => route('operator.task', ['taskId' => $task->id]),
                    'actions' => ['done', 'snooze'],
                    'age' => $task->due_date,
                ]));
    }
}
