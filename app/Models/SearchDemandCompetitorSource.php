<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'search_demand_competitor_id', 'digital_asset_id', 'source_type', 'provider',
    'source_record_type', 'source_record_id', 'source_fingerprint', 'evidence_payload',
    'observed_at', 'created_by',
])]
class SearchDemandCompetitorSource extends Model
{
    protected function casts(): array
    {
        return ['evidence_payload' => 'array', 'observed_at' => 'immutable_datetime'];
    }

    public function competitor(): BelongsTo
    {
        return $this->belongsTo(SearchDemandCompetitor::class, 'search_demand_competitor_id');
    }

    public function digitalAsset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class);
    }
}
