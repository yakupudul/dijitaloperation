<?php

namespace App\Models;

use App\Enums\ReportDeliveryFailureCategory;
use App\Enums\ReportDeliveryOccurrenceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One scheduled run occurrence for a delivery schedule (Prompt 60).
 */
class ReportDeliveryOccurrence extends Model
{
    protected $fillable = [
        'schedule_id',
        'scheduled_for',
        'period_start',
        'period_end',
        'report_snapshot_id',
        'artifact_id',
        'status',
        'occurrence_key',
        'claimed_at',
        'completed_at',
        'failure_category',
        'failure_message',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'period_start' => 'date',
            'period_end' => 'date',
            'status' => ReportDeliveryOccurrenceStatus::class,
            'claimed_at' => 'datetime',
            'completed_at' => 'datetime',
            'failure_category' => ReportDeliveryFailureCategory::class,
        ];
    }

    /**
     * @return BelongsTo<ReportDeliverySchedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ReportDeliverySchedule::class, 'schedule_id');
    }

    /**
     * @return BelongsTo<ReportSnapshot, $this>
     */
    public function reportSnapshot(): BelongsTo
    {
        return $this->belongsTo(ReportSnapshot::class);
    }

    /**
     * @return BelongsTo<ReportArtifact, $this>
     */
    public function artifact(): BelongsTo
    {
        return $this->belongsTo(ReportArtifact::class, 'artifact_id');
    }

    /**
     * @return HasMany<ReportDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(ReportDelivery::class, 'schedule_occurrence_id');
    }
}
