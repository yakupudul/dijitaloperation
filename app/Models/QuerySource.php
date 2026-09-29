<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Raw query layer: one row per discovered account (external resource) × raw query × month × source (gsc | google_ads | gbp)
 * with the month's metrics. Aggregated per month, never daily.
 */
class QuerySource extends Model
{
    public const array SOURCES = ['gsc', 'google_ads', 'gbp'];

    /** @var list<string> */
    protected $fillable = [
        'external_resource_id',
        'source',
        'raw_query',
        'month',
        'impressions',
        'clicks',
        'position',
        'cost',
        'conversions',
        'query_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'month' => 'immutable_date',
            'impressions' => 'integer',
            'clicks' => 'integer',
            'position' => 'float',
            'cost' => 'float',
            'conversions' => 'float',
        ];
    }

    /** @return BelongsTo<CoreExternalResource, $this> */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(CoreExternalResource::class, 'external_resource_id');
    }

    /** @return BelongsTo<Query, $this> */
    public function query(): BelongsTo
    {
        return $this->belongsTo(Query::class);
    }
}
