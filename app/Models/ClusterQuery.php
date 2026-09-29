<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Membership of one query in one cluster (a query belongs to at most one cluster). is_suggested marks AI-generated queries ("önerilen").
 */
class ClusterQuery extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'cluster_id',
        'query_id',
        'is_suggested',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_suggested' => 'boolean',
        ];
    }

    /** @return BelongsTo<Cluster, $this> */
    public function cluster(): BelongsTo
    {
        return $this->belongsTo(Cluster::class);
    }

    /** @return BelongsTo<Query, $this> */
    public function query(): BelongsTo
    {
        return $this->belongsTo(Query::class);
    }
}
