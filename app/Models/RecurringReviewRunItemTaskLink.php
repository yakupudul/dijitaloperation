<?php

namespace App\Models;

use App\Enums\RecurringReviewTaskLinkKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'run_item_id',
    'task_id',
    'relation_kind',
])]
class RecurringReviewRunItemTaskLink extends Model
{
    /**
     * @return BelongsTo<RecurringReviewRunItem, $this>
     */
    public function runItem(): BelongsTo
    {
        return $this->belongsTo(RecurringReviewRunItem::class, 'run_item_id');
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'relation_kind' => RecurringReviewTaskLinkKind::class,
        ];
    }
}
