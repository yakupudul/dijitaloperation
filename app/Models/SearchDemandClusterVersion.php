<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'search_demand_cluster_id',
    'version',
    'change_type',
    'snapshot',
    'change_metadata',
    'created_by',
    'created_at',
])]
class SearchDemandClusterVersion extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'snapshot' => 'array',
            'change_metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(SearchDemandCluster::class, 'search_demand_cluster_id');
    }
}
