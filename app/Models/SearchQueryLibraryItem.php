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
    use \Illuminate\Database\Eloquent\SoftDeletes;

    /** @param array<string, mixed> $filters */
    public function scopeLibraryFilters(\Illuminate\Database\Eloquent\Builder $query, array $filters): void
    {
        $status = $filters['status'] ?? 'all';
        if ($status === 'deleted') {
            $query->onlyTrashed();
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }
        $sector = (string) ($filters['sector'] ?? '');
        if ($sector !== '') {
            $query->where(fn ($q) => $q->where('sector', $sector)->orWhereHas('sectors', fn ($s) => $s->where('code', $sector)));
        }
        if (! empty($filters['unassigned'])) {
            $query->whereDoesntHave('services', fn ($s) => $s->where('status', 'active')->when($sector !== '', fn ($s) => $s->where('sector', $sector)));
        }
        if (! empty($filters['source'])) {
            $query->whereHas('sourceRecords', fn ($r) => $r->where('source_type', $filters['source']));
        }
        if (! empty($filters['service'])) {
            $query->whereHas('services', fn ($s) => $s->whereKey((int) $filters['service']));
        }
        $text = trim((string) ($filters['search'] ?? ''));
        if ($text !== '') {
            $text = app(\App\Services\IntelligenceCore\Identity\SearchTermNormalizer::class)->normalize($text, 'tr')->foldedText;
            $query->where('folded_text', 'like', '%'.addcslashes($text, '\\%_').'%');
        }
    }

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

    public function sectors(): BelongsToMany
    {
        return $this->belongsToMany(ServiceCategory::class, 'search_query_library_sectors')->withTimestamps();
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
