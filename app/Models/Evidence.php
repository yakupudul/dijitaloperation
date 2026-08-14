<?php

namespace App\Models;

use Database\Factories\EvidenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'run_id',
    'digital_asset_id',
    'source_module',
    'type',
    'definition_id',
    'evidence_fingerprint',
    'is_canonical',
    'eligibility_status',
    'collection_run_id',
    'brand_goal_id',
    'brand_offering_id',
    'is_derived',
    'generated_by_ai',
    'request_fingerprint',
    'title',
    'payload',
    'observed_at',
    'fresh_until',
])]
class Evidence extends Model
{
    /** @use HasFactory<EvidenceFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Run, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    /**
     * @return BelongsTo<DigitalAsset, $this>
     */
    public function digitalAsset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class);
    }

    /**
     * @return BelongsTo<BrandGoal, $this>
     */
    public function brandGoal(): BelongsTo
    {
        return $this->belongsTo(BrandGoal::class);
    }

    /**
     * @return BelongsTo<BrandOffering, $this>
     */
    public function brandOffering(): BelongsTo
    {
        return $this->belongsTo(BrandOffering::class);
    }

    public function isCanonical(): bool
    {
        return (bool) $this->is_canonical
            && filled($this->definition_id)
            && filled($this->evidence_fingerprint);
    }

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'payload' => 'array',
        'observed_at' => 'datetime',
        'fresh_until' => 'datetime',
        'is_canonical' => 'boolean',
        'is_derived' => 'boolean',
        'generated_by_ai' => 'boolean',
    ];
}
