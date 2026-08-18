<?php

namespace App\Models;

use App\Enums\RecurringReviewOccurrenceKind;
use App\Enums\RecurringReviewRunStatus;
use App\Enums\RecurringReviewScopeKind;
use Database\Factories\RecurringReviewRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'schedule_id',
    'occurrence_key',
    'occurrence_kind',
    'due_at',
    'playbook_id',
    'playbook_revision_id',
    'customer_id',
    'scope_kind',
    'brand_id',
    'digital_asset_id',
    'service_scope_context_json',
    'reviewer_user_id',
    'status',
    'started_at',
    'completed_at',
    'summary_json',
])]
class RecurringReviewRun extends Model
{
    /** @use HasFactory<RecurringReviewRunFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<RecurringReviewSchedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(RecurringReviewSchedule::class, 'schedule_id');
    }

    /**
     * @return BelongsTo<Playbook, $this>
     */
    public function playbook(): BelongsTo
    {
        return $this->belongsTo(Playbook::class);
    }

    /**
     * @return BelongsTo<PlaybookRevision, $this>
     */
    public function playbookRevision(): BelongsTo
    {
        return $this->belongsTo(PlaybookRevision::class, 'playbook_revision_id');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsTo<DigitalAsset, $this>
     */
    public function digitalAsset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }

    /**
     * @return HasMany<RecurringReviewRunItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(RecurringReviewRunItem::class, 'run_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurrence_kind' => RecurringReviewOccurrenceKind::class,
            'scope_kind' => RecurringReviewScopeKind::class,
            'status' => RecurringReviewRunStatus::class,
            'due_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'service_scope_context_json' => 'array',
            'summary_json' => 'array',
        ];
    }
}
