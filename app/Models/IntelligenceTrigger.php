<?php

namespace App\Models;

use App\Enums\Intelligence\IntelligenceTriggerSource;
use App\Enums\Intelligence\IntelligenceTriggerStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Durable intelligence trigger (Prompt 63). Not business truth.
 */
class IntelligenceTrigger extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'customer_id',
        'brand_id',
        'digital_asset_id',
        'source_kind',
        'source_identity',
        'source_revision_fingerprint',
        'trigger_key',
        'reason',
        'status',
        'changed_evidence_refs',
        'metadata',
        'created_by',
        'created_at',
        'planned_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_kind' => IntelligenceTriggerSource::class,
            'status' => IntelligenceTriggerStatus::class,
            'changed_evidence_refs' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'planned_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function digitalAsset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class);
    }

    public function plans(): HasMany
    {
        return $this->hasMany(IntelligenceExecutionPlan::class);
    }
}
