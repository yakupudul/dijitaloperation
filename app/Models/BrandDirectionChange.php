<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One seen change of a brand's direction (Marka rotası): the snapshot after it, the services added / removed, its size
 * (baseline | minor | major) and what the realignment did (`result`). applied_at null = the realignment still runs.
 */
class BrandDirectionChange extends Model
{
    public const string BASELINE = 'baseline';

    public const string MINOR = 'minor';

    public const string MAJOR = 'major';

    public const array MAGNITUDE_LABELS = [self::BASELINE => 'Başlangıç', self::MINOR => 'Küçük değişiklik', self::MAJOR => 'Rota değişti'];

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'snapshot' => 'array', 'added' => 'array', 'removed' => 'array', 'result' => 'array',
            'applied_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public static function latestFor(int $brandId): ?self
    {
        return static::query()->where('brand_id', $brandId)->orderByDesc('id')->first();
    }
}
