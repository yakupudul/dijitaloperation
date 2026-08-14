<?php

namespace App\Models;

use App\Enums\QaReviewResult;
use App\Enums\QaReviewStatus;
use App\Support\Tasks\TaskReviewedStateFingerprint;
use Database\Factories\QaReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'task_id',
    'customer_id',
    'brand_id',
    'status',
    'result',
    'reviewer_id',
    'requested_by',
    'created_by',
    'notes',
    'subject_fingerprint',
    'subject_title_snapshot',
    'requested_at',
    'started_at',
    'completed_at',
    'idempotency_key',
])]
class QaReview extends Model
{
    /** @use HasFactory<QaReviewFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
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
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isCurrentForTask(Task $task): bool
    {
        return $this->subject_fingerprint === TaskReviewedStateFingerprint::for($task);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => QaReviewStatus::class,
            'result' => QaReviewResult::class,
            'requested_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
