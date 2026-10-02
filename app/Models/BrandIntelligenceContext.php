<?php

namespace App\Models;

use Database\Factories\BrandIntelligenceContextFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

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

    public const string SOURCE_PUBLIC_DISCOVERY = 'public_discovery';

    public const string SOURCE_PUBLIC_DISCOVERY_EDITED = 'public_discovery_edited';

    /**
     * @var list<string>
     */
    public const array LEGACY_IDENTITY_FIELDS = [
        'business_goals',
        'conversion_goals',
        'priority_offerings',
    ];

    private static bool $allowLegacyIdentityProjection = false;

    /**
     * @param  callable(): mixed  $callback
     */
    public static function withLegacyIdentityProjection(callable $callback): mixed
    {
        $previous = self::$allowLegacyIdentityProjection;
        self::$allowLegacyIdentityProjection = true;

        try {
            return $callback();
        } finally {
            self::$allowLegacyIdentityProjection = $previous;
        }
    }

    protected static function booted(): void
    {
        static::saving(function (BrandIntelligenceContext $model): void {
            if (self::$allowLegacyIdentityProjection) {
                return;
            }

            // Initial insert may still carry legacy arrays for migration/factory seed.
            // Subsequent updates must go through the canonical write/projection path.
            if (! $model->exists) {
                return;
            }

            foreach (self::LEGACY_IDENTITY_FIELDS as $field) {
                if ($model->isDirty($field)) {
                    throw ValidationException::withMessages([
                        $field => 'Legacy identity field is a compatibility projection. Use BrandIntelligenceContextWriteService / Goal / Offering services.',
                    ]);
                }
            }
        });
    }

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
