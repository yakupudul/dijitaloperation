<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One expert of a brand (doctor, specialist). Articles go to WordPress under the default expert's WordPress user
 * (`wp_author`), so the site's SEO plugin prints their author Person schema and profile.
 */
class BrandExpert extends Model
{
    protected $fillable = ['brand_id', 'name', 'title', 'wp_author', 'profile_url', 'is_default', 'source'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** The expert articles of the brand are published under: the default one, else the only one. */
    public static function authorOf(int $brandId): ?self
    {
        $experts = self::query()->where('brand_id', $brandId)->orderByDesc('is_default')->orderBy('id')->get();

        return $experts->firstWhere('is_default', true) ?? ($experts->count() === 1 ? $experts->first() : null);
    }
}
