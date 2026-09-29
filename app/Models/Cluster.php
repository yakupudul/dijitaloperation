<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ONE clusters store: queries that satisfy the same user need on the same page type, per SECTOR + SERVICE (shared across
 * brands). Main query, optional representative queries, page type, subtopics to cover, reasoning, approved, locked (manual).
 */
class Cluster extends Model
{
    public const array INTENTS = ['commercial', 'local', 'informational', 'navigational', 'comparison'];

    public const array PAGE_TYPES = ['service', 'guide', 'faq', 'comparison', 'location', 'other'];

    /** @var list<string> */
    protected $fillable = [
        'sector_id',
        'service_id',
        'name',
        'intent',
        'main_query_id',
        'representative_query_ids',
        'page_type',
        'subtopics',
        'reasoning',
        'approved',
        'locked',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'representative_query_ids' => 'array',
            'subtopics' => 'array',
            'approved' => 'boolean',
            'locked' => 'boolean',
        ];
    }

    /** @return BelongsTo<ServiceCategory, $this> */
    public function sector(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'sector_id');
    }

    /** @return BelongsTo<ServiceCatalogItem, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(ServiceCatalogItem::class, 'service_id');
    }

    /** @return BelongsTo<Query, $this> */
    public function mainQuery(): BelongsTo
    {
        return $this->belongsTo(Query::class, 'main_query_id');
    }

    /** @return HasMany<ClusterQuery, $this> */
    public function clusterQueries(): HasMany
    {
        return $this->hasMany(ClusterQuery::class);
    }

    /** @return HasMany<BrandClusterPage, $this> */
    public function brandPages(): HasMany
    {
        return $this->hasMany(BrandClusterPage::class);
    }
}
