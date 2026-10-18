<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A brand's epikriz head: the one-page summary every AI step reads, how full it is and when its layers were built. */
class BrandEpicrisis extends Model
{
    /** @var list<string> */
    protected $fillable = ['brand_id', 'summary', 'summary_hash', 'fill_percent', 'counts', 'facts_basis', 'synthesis_basis', 'synthesis_keys', 'built_at', 'facts_read_at',
        'synthesized_at', 'lessons_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['counts' => 'array', 'synthesis_keys' => 'array', 'fill_percent' => 'integer', 'built_at' => 'immutable_datetime', 'facts_read_at' => 'immutable_datetime',
            'synthesized_at' => 'immutable_datetime', 'lessons_at' => 'immutable_datetime'];
    }

    public static function for(Brand|int $brand): self
    {
        return self::query()->firstOrNew(['brand_id' => $brand instanceof Brand ? (int) $brand->id : $brand]);
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
