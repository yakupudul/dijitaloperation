<?php

namespace App\Models;

use App\Enums\GoalApplicabilityMode;
use App\Enums\GoalKind;
use App\Enums\GoalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class BrandGoal extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'brand_id',
        'kind',
        'label',
        'normalized_key',
        'note',
        'conversion_type',
        'status',
        'applicability_mode',
        'sort_order',
    ];

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsToMany<BrandOffering, $this>
     */
    public function offerings(): BelongsToMany
    {
        return $this->belongsToMany(
            BrandOffering::class,
            'brand_goal_offering',
            'brand_goal_id',
            'brand_offering_id'
        )->withTimestamps();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => GoalKind::class,
            'status' => GoalStatus::class,
            'applicability_mode' => GoalApplicabilityMode::class,
            'sort_order' => 'integer',
        ];
    }
}
