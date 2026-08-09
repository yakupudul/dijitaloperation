<?php

namespace App\Models;

use Database\Factories\BrandIntelligenceContextFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'brand_id',
    'business_summary',
    'business_model',
    'products_services',
    'priority_offerings',
    'target_audiences',
    'target_markets',
    'business_goals',
    'conversion_goals',
    'positioning',
    'differentiators',
    'known_competitors',
    'important_constraints',
    'source',
    'updated_by',
])]
class BrandIntelligenceContext extends Model
{
    /** @use HasFactory<BrandIntelligenceContextFactory> */
    use HasFactory;

    public const string SOURCE_OPERATOR = 'operator';

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
    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'products_services' => 'array',
            'priority_offerings' => 'array',
            'target_audiences' => 'array',
            'target_markets' => 'array',
            'business_goals' => 'array',
            'conversion_goals' => 'array',
            'differentiators' => 'array',
            'known_competitors' => 'array',
        ];
    }
}
