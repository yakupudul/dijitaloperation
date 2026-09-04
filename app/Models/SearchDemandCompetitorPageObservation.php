<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'run_item_id', 'search_demand_competitor_url_id', 'previous_observation_id',
    'content_source_observation_id', 'requested_url', 'final_url', 'status', 'http_status',
    'content_type', 'response_bytes', 'redirect_count', 'fetch_error', 'raw_html_hash',
    'content_fingerprint', 'content_changed', 'normalized_text', 'title', 'meta_description',
    'h1', 'headings', 'schema_summary', 'internal_links', 'external_links',
    'service_expressions', 'location_expressions', 'normalization_version', 'observed_at',
])]
class SearchDemandCompetitorPageObservation extends Model
{
    protected function casts(): array
    {
        return [
            'http_status' => 'integer',
            'response_bytes' => 'integer',
            'redirect_count' => 'integer',
            'content_changed' => 'boolean',
            'headings' => 'array',
            'schema_summary' => 'array',
            'internal_links' => 'array',
            'external_links' => 'array',
            'service_expressions' => 'array',
            'location_expressions' => 'array',
            'observed_at' => 'immutable_datetime',
        ];
    }

    public function runItem(): BelongsTo
    {
        return $this->belongsTo(SearchDemandCompetitorPageRunItem::class, 'run_item_id');
    }

    public function competitorUrl(): BelongsTo
    {
        return $this->belongsTo(SearchDemandCompetitorUrl::class, 'search_demand_competitor_url_id');
    }

    public function previousObservation(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_observation_id');
    }

    public function contentSource(): BelongsTo
    {
        return $this->belongsTo(self::class, 'content_source_observation_id');
    }

    public function reusedBy(): HasMany
    {
        return $this->hasMany(self::class, 'content_source_observation_id');
    }
}
