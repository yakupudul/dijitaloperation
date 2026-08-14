<?php

namespace App\Models;

use App\Enums\ApprovalActorKind;
use App\Enums\ApprovalDecision;
use App\Enums\ApprovalKind;
use App\Enums\ApprovalStatus;
use App\Enums\ApprovalSubjectKind;
use App\Support\Tasks\TaskReviewedStateFingerprint;
use Database\Factories\ApprovalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'subject_kind',
    'task_id',
    'customer_id',
    'brand_id',
    'kind',
    'status',
    'decision',
    'requested_by',
    'decided_by_actor_kind',
    'decided_by_user_id',
    'decided_by_customer_contact_id',
    'created_by',
    'notes',
    'reason',
    'waiting_on_client',
    'subject_fingerprint',
    'subject_title_snapshot',
    'requested_at',
    'decided_at',
    'idempotency_key',
])]
class Approval extends Model
{
    /** @use HasFactory<ApprovalFactory> */
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
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    /**
     * @return BelongsTo<CustomerContact, $this>
     */
    public function decidedByCustomerContact(): BelongsTo
    {
        return $this->belongsTo(CustomerContact::class, 'decided_by_customer_contact_id');
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
            'subject_kind' => ApprovalSubjectKind::class,
            'kind' => ApprovalKind::class,
            'status' => ApprovalStatus::class,
            'decision' => ApprovalDecision::class,
            'decided_by_actor_kind' => ApprovalActorKind::class,
            'waiting_on_client' => 'boolean',
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }
}
