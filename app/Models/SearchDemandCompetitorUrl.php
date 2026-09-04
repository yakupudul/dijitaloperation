<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'search_demand_competitor_id', 'url', 'normalized_url_hash', 'domain', 'source_type',
    'first_observed_at', 'last_observed_at',
])]
class SearchDemandCompetitorUrl extends Model
{
    protected function casts(): array
    {
        return [
            'first_observed_at' => 'immutable_datetime',
            'last_observed_at' => 'immutable_datetime',
        ];
    }

    public function competitor(): BelongsTo
    {
        return $this->belongsTo(SearchDemandCompetitor::class, 'search_demand_competitor_id');
    }
}
