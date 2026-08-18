<?php

namespace App\Models;

use App\Enums\RecurringReviewCadence;
use App\Enums\RecurringReviewScheduleStatus;
use App\Enums\RecurringReviewScopeKind;
use Database\Factories\RecurringReviewScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'customer_id',
    'scope_kind',
    'brand_id',
    'digital_asset_id',
    'playbook_id',
    'cadence',
    'timezone',
    'starts_at',
    'ends_at',
    'status',
    'owner_user_id',
    'default_reviewer_user_id',
    'next_due_at',
    'created_by',
    'idempotency_key',
])]
class RecurringReviewSchedule extends Model
{
    /** @use HasFactory<RecurringReviewScheduleFactory> */
    use HasFactory;

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
     * @return BelongsTo<Playbook, $this>
     */
    public function playbook(): BelongsTo
    {
        return $this->belongsTo(Playbook::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function defaultReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'default_reviewer_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<RecurringReviewCheckDefinition, $this>
     */
    public function checkDefinitions(): HasMany
    {
        return $this->hasMany(RecurringReviewCheckDefinition::class, 'schedule_id');
    }

    /**
     * @return HasMany<RecurringReviewRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(RecurringReviewRun::class, 'schedule_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope_kind' => RecurringReviewScopeKind::class,
            'cadence' => RecurringReviewCadence::class,
            'status' => RecurringReviewScheduleStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'next_due_at' => 'datetime',
        ];
    }
}
