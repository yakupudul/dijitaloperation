<?php

namespace App\Panel\Models;

use App\Models\Brand;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The agency's word to its brands: to everyone, to one sector or to one brand. Holds no brand data. */
class PanelAnnouncement extends Model
{
    protected $fillable = ['title', 'body', 'link_url', 'brand_id', 'sector_id', 'created_by', 'published_at'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** What one brand may read: sent to everyone, to its sector or to it alone. */
    public function scopeForBrand(Builder $query, Brand $brand): Builder
    {
        return $query->whereNotNull('published_at')->where(function (Builder $q) use ($brand): void {
            $q->where(fn (Builder $all) => $all->whereNull('brand_id')->whereNull('sector_id'))->orWhere('brand_id', $brand->id);
            if ($brand->sector_id) {
                $q->orWhere(fn (Builder $sector) => $sector->whereNull('brand_id')->where('sector_id', $brand->sector_id));
            }
        });
    }
}
