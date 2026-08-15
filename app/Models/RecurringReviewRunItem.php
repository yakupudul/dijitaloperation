<?php

namespace App\Models;

use App\Enums\RecurringReviewOutcomeKind;
use App\Enums\RecurringReviewRunItemState;
use Database\Factories\RecurringReviewRunItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'run_id',
    'check_definition_id',
    'position',
    'title_snapshot',
    'description_snapshot',
    'is_required_snapshot',
    'finding_rule_stable_id_snapshot',
    'opportunity_rule_stable_id_snapshot',
    'state',
    'outcome_kind',
    'evidence_id',
    'finding_id',
    'opportunity_id',
    'task_id',
    'note',
    'completed_at',
    'completed_by',
    'outcome_idempotency_key',
])]
class RecurringReviewRunItem extends Model
{
    /** @use HasFactory<RecurringReviewRunItemFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<RecurringReviewRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(RecurringReviewRun::class, 'run_id');
    }

    /**
     * @return BelongsTo<RecurringReviewCheckDefinition, $this>
     */
    public function checkDefinition(): BelongsTo
    {
        return $this->belongsTo(RecurringReviewCheckDefinition::class, 'check_definition_id');
    }

    /**
     * @return BelongsTo<Evidence, $this>
     */
    public function evidence(): BelongsTo
    {
        return $this->belongsTo(Evidence::class);
    }

    /**
     * @return BelongsTo<Finding, $this>
     */
    public function finding(): BelongsTo
    {
        return $this->belongsTo(Finding::class);
    }

    /**
     * @return BelongsTo<Opportunity, $this>
     */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * @return HasMany<RecurringReviewRunItemTaskLink, $this>
     */
    public function taskLinks(): HasMany
    {
        return $this->hasMany(RecurringReviewRunItemTaskLink::class, 'run_item_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_required_snapshot' => 'boolean',
            'state' => RecurringReviewRunItemState::class,
            'outcome_kind' => RecurringReviewOutcomeKind::class,
            'completed_at' => 'datetime',
        ];
    }
}
