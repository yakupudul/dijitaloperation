<?php

namespace App\Models;

use App\Enums\TaskScopeKind;
use App\Enums\TaskSourceKind;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'recommendation_id',
    'client_request_id',
    'recurring_review_run_item_id',
    'client_request_task_idempotency_key',
    'source_kind',
    'idempotency_key',
    'customer_id',
    'brand_id',
    'digital_asset_id',
    'scope_kind',
    'title',
    'action',
    'rationale',
    'priority',
    'snapshot_json',
    'assignee_id',
    'due_date',
    'status',
    'completed_at',
    'completed_by_id',
    'completion_note',
    'outcome_review_after_at',
    'outcome_status',
    'outcome_checked_at',
    'outcome_run_id',
    'outcome_json',
])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Recommendation, $this>
     */
    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(Recommendation::class);
    }

    /**
     * @return BelongsTo<ClientRequest, $this>
     */
    public function clientRequest(): BelongsTo
    {
        return $this->belongsTo(ClientRequest::class);
    }

    /**
     * @return BelongsTo<RecurringReviewRunItem, $this>
     */
    public function recurringReviewRunItem(): BelongsTo
    {
        return $this->belongsTo(RecurringReviewRunItem::class, 'recurring_review_run_item_id');
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
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_id');
    }

    /**
     * @return BelongsTo<Run, $this>
     */
    public function outcomeRun(): BelongsTo
    {
        return $this->belongsTo(Run::class, 'outcome_run_id');
    }

    public function searchDemandChangeTracking(): HasOne
    {
        return $this->hasOne(SearchDemandChangeTracking::class);
    }

    /**
     * @return HasMany<QaReview, $this>
     */
    public function qaReviews(): HasMany
    {
        return $this->hasMany(QaReview::class);
    }

    /**
     * @return HasMany<Approval, $this>
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope_kind' => TaskScopeKind::class,
            'source_kind' => TaskSourceKind::class,
            'snapshot_json' => 'array',
            'outcome_json' => 'array',
            'due_date' => 'date',
            'completed_at' => 'datetime',
            'outcome_review_after_at' => 'datetime',
            'outcome_checked_at' => 'datetime',
        ];
    }
}
