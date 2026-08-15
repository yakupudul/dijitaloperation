<?php

namespace App\Services\RecurringReviews;

use App\Enums\TaskSourceKind;
use App\Models\RecurringReviewRunItem;
use App\Models\Task;
use App\Support\Tasks\TaskStatus;
use Illuminate\Database\Eloquent\Builder;

/**
 * Correlates open Tasks that originated from the same recurring review check identity.
 * Correlation is structural (schedule + check + scope FKs) — never title matching.
 */
final class ResolveReviewTaskDisposition
{
    public function findOpenReviewOriginTask(RecurringReviewRunItem $item): ?Task
    {
        $item->loadMissing('run');
        $run = $item->run;
        if ($run === null) {
            return null;
        }

        /** @var Builder<Task> $query */
        $query = Task::query()
            ->select('tasks.*')
            ->where('tasks.source_kind', TaskSourceKind::RecurringReviewCheck->value)
            ->whereIn('tasks.status', TaskStatus::active())
            ->where('tasks.customer_id', $run->customer_id)
            ->where('tasks.brand_id', $run->brand_id)
            ->where('tasks.digital_asset_id', $run->digital_asset_id)
            ->join('recurring_review_run_items as rri', 'rri.id', '=', 'tasks.recurring_review_run_item_id')
            ->join('recurring_review_runs as rr', 'rr.id', '=', 'rri.run_id')
            ->where('rr.schedule_id', $run->schedule_id)
            ->where('rri.check_definition_id', $item->check_definition_id)
            ->orderByDesc('tasks.id');

        $task = $query->first();

        return $task instanceof Task ? $task : null;
    }
}
