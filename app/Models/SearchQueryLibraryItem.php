<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'uuid',
    'identity_hash',
    'canonical_text',
    'folded_text',
    'language_code',
    'locale',
    'market_code',
    'sector',
    'demand_family',
    'location_scope',
    'location_value',
    'is_branded',
    'status',
    'notes',
    'normalization_version',
    'first_seen_at',
    'last_seen_at',
    'created_by',
    'updated_by',
])]
class SearchQueryLibraryItem extends Model
{
    protected function casts(): array
    {
        return [
            'is_branded' => 'boolean',
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
        ];
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(
            ServiceCatalogItem::class,
            'search_query_library_item_service',
        )->withPivot(['is_primary', 'provenance'])->withTimestamps();
    }

    public function sourceRecords(): HasMany
    {
        return $this->hasMany(SearchQueryLibrarySourceRecord::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
