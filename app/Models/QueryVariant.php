<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One raw provider query of one source account, with its window metrics and the core query it maps to. */
class QueryVariant extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'had_location' => 'boolean', 'had_own_brand' => 'boolean', 'had_competitor_brand' => 'boolean', 'had_product_brand' => 'boolean',
            'removed' => 'array', 'cost' => 'float', 'conversions' => 'float',
            'first_seen_on' => 'date', 'last_seen_on' => 'date', 'ingested_at' => 'immutable_datetime',
        ];
    }

    public function core(): BelongsTo
    {
        return $this->belongsTo(SearchQueryLibraryItem::class, 'search_query_library_item_id');
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(CoreExternalResource::class, 'external_resource_id');
    }
}
