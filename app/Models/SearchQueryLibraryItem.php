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
    'search_intent',
    'user_problem',
    'decision_stage',
    'serp_intent_group',
    'content_target_cluster',
    'location_scope',
    'location_value',
    'is_branded',
    'status',
    'notes',
    'normalization_version',
    'classification_source',
    'classification_confidence',
    'classification_version',
    'classified_at',
    'classified_by',
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
            'classification_confidence' => 'integer',
            'classified_at' => 'immutable_datetime',
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

    public function aiCandidates(): HasMany
    {
        return $this->hasMany(SearchDemandAiCandidate::class, 'source_search_query_library_item_id');
    }

    public function brandPortfolioItems(): HasMany
    {
        return $this->hasMany(BrandQueryPortfolioItem::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
