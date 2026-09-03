<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'search_demand_serp_snapshot_id', 'rank_group', 'rank_absolute', 'url', 'domain',
    'title', 'description', 'is_brand_domain', 'observed_payload',
])]
class SearchDemandSerpResult extends Model
{
    protected function casts(): array
    {
        return ['rank_group' => 'integer', 'rank_absolute' => 'integer', 'is_brand_domain' => 'boolean', 'observed_payload' => 'array'];
    }

    public function snapshot(): BelongsTo { return $this->belongsTo(SearchDemandSerpSnapshot::class, 'search_demand_serp_snapshot_id'); }
}
