<?php

namespace App\Models;

use App\Enums\ReportDeliveryFailureCategory;
use App\Enums\ReportDeliveryMode;
use App\Enums\ReportDeliveryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One email (or channel) delivery of a report to a recipient (Prompt 60).
 */
class ReportDelivery extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'report_snapshot_id',
        'recipient_email_snapshot',
        'recipient_name_snapshot',
        'delivery_mode',
        'share_grant_id',
        'artifact_id',
        'locale',
        'subject_template_version',
        'email_template_version',
        'status',
        'schedule_occurrence_id',
        'idempotency_key',
        'created_by',
        'sent_at',
        'failed_at',
        'failure_category',
        'failure_message',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'delivery_mode' => ReportDeliveryMode::class,
            'status' => ReportDeliveryStatus::class,
            'failure_category' => ReportDeliveryFailureCategory::class,
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ReportSnapshot, $this>
     */
    public function reportSnapshot(): BelongsTo
    {
        return $this->belongsTo(ReportSnapshot::class);
    }

    /**
     * @return BelongsTo<ReportShareGrant, $this>
     */
    public function shareGrant(): BelongsTo
    {
        return $this->belongsTo(ReportShareGrant::class, 'share_grant_id');
    }

    /**
     * @return BelongsTo<ReportArtifact, $this>
     */
    public function artifact(): BelongsTo
    {
        return $this->belongsTo(ReportArtifact::class, 'artifact_id');
    }

    /**
     * @return BelongsTo<ReportDeliveryOccurrence, $this>
     */
    public function scheduleOccurrence(): BelongsTo
    {
        return $this->belongsTo(ReportDeliveryOccurrence::class, 'schedule_occurrence_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<ReportDeliveryAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(ReportDeliveryAttempt::class, 'delivery_id');
    }
}
