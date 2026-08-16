<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Restricted contributor lineage for audit / deletion / recompute.
 * Never expose via SectorMemoryReadService or Agent Memory.
 */
#[Fillable([
    'revision_id',
    'brand_experience_id',
    'brand_experience_revision_id',
    'brand_id',
    'customer_id',
    'contribution_fingerprint',
    'effective_weight',
])]
class SectorLearningLineageEntry extends Model
{
    /**
     * @return BelongsTo<SectorLearningRevision, $this>
     */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(SectorLearningRevision::class, 'revision_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_weight' => 'float',
        ];
    }
}
