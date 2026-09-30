<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ONE clusters store: queries that satisfy the same user need on the same page type, per SECTOR + SERVICE (shared across
 * brands). User need, main query, optional representative queries, page type, subtopics to cover, exclusions (topics not
 * to include), reasoning, approved, locked (manual), version (+1 per saved edit).
 */
class Cluster extends Model
{
    public const array INTENTS = ['commercial', 'local', 'informational', 'navigational', 'comparison'];

    public const array PAGE_TYPES = ['service', 'guide', 'faq', 'comparison', 'location', 'other'];

    /** Operator labels (Turkish). */
    public const array INTENT_LABELS = [
        'informational' => 'bilgi', 'commercial' => 'ticari', 'local' => 'yerel', 'comparison' => 'karşılaştırma', 'navigational' => 'marka',
    ];

    public const array PAGE_TYPE_LABELS = [
        'service' => 'hizmet', 'guide' => 'rehber', 'faq' => 'sss', 'comparison' => 'karşılaştırma', 'location' => 'lokasyon', 'other' => 'diğer',
    ];

    /** @var list<string> */
    protected $fillable = [
        'sector_id',
        'service_id',
        'name',
        'intent',
        'user_need',
        'main_query_id',
        'representative_query_ids',
        'page_type',
        'subtopics',
        'exclusions',
        'reasoning',
        'approved',
        'locked',
        'version',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'representative_query_ids' => 'array',
            'subtopics' => 'array',
            'exclusions' => 'array',
            'approved' => 'boolean',
            'locked' => 'boolean',
            'version' => 'integer',
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
