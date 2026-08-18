<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'brand_experience_revision_id',
    'brand_goal_id',
    'goal_label_snapshot',
])]
class BrandExperienceGoal extends Model
{
    /**
     * @return BelongsTo<BrandExperienceRevision, $this>
     */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(BrandExperienceRevision::class, 'brand_experience_revision_id');
    }

    /**
     * @return BelongsTo<BrandGoal, $this>
     */
    public function brandGoal(): BelongsTo
    {
        return $this->belongsTo(BrandGoal::class);
    }
}
