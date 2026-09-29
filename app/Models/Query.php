<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Normalized query layer: ONE row per normalized query text with its sector and service assignment (rule | ai | manual | none),
 * totals across sources and first / last seen. Manual assignments are locked: AI never overwrites them.
 */
class Query extends Model
{
    public const array ASSIGNMENTS = ['rule', 'ai', 'manual', 'none'];

    /** @var list<string> */
    protected $fillable = [
        'text',
        'text_hash',
        'sector_id',
        'service_id',
        'assignment',
        'locked',
        'is_suggested',
        'impressions',
        'clicks',
        'volume',
        'first_seen_on',
        'last_seen_on',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'locked' => 'boolean',
            'is_suggested' => 'boolean',
            'impressions' => 'integer',
            'clicks' => 'integer',
            'volume' => 'integer',
            'first_seen_on' => 'immutable_date',
            'last_seen_on' => 'immutable_date',
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

    /** @return HasMany<QuerySource, $this> */
    public function sources(): HasMany
    {
        return $this->hasMany(QuerySource::class);
    }

    /** @return HasMany<BrandQuery, $this> */
    public function brandQueries(): HasMany
    {
        return $this->hasMany(BrandQuery::class);
    }
}
