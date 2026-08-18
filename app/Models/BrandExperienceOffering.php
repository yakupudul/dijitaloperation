<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'brand_experience_revision_id',
    'brand_offering_id',
    'offering_label_snapshot',
])]
class BrandExperienceOffering extends Model
{
    /**
     * @return BelongsTo<BrandExperienceRevision, $this>
     */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(BrandExperienceRevision::class, 'brand_experience_revision_id');
    }

    /**
     * @return BelongsTo<BrandOffering, $this>
     */
    public function brandOffering(): BelongsTo
    {
        return $this->belongsTo(BrandOffering::class);
    }
}
