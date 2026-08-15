<?php

namespace App\Models;

use Database\Factories\RecurringReviewCheckDefinitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'schedule_id',
    'position',
    'title',
    'description',
    'is_required',
    'is_active',
    'finding_rule_stable_id',
    'opportunity_rule_stable_id',
])]
class RecurringReviewCheckDefinition extends Model
{
    /** @use HasFactory<RecurringReviewCheckDefinitionFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<RecurringReviewSchedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(RecurringReviewSchedule::class, 'schedule_id');
    }

    /**
     * @return HasMany<RecurringReviewRunItem, $this>
     */
    public function runItems(): HasMany
    {
        return $this->hasMany(RecurringReviewRunItem::class, 'check_definition_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
