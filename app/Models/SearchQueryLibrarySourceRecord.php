<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'search_query_library_item_id',
    'search_query_library_import_id',
    'service_catalog_item_id',
    'source_fingerprint',
    'source_type',
    'source_reference',
    'row_number',
    'observed_text',
    'country_code',
    'city_name',
    'district_name',
    'period_start',
    'period_end',
    'impressions',
    'clicks',
    'conversions',
    'cost',
    'search_volume',
    'cpc',
    'competition',
    'raw_payload',
    'observed_at',
])]
class SearchQueryLibrarySourceRecord extends Model
{
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'raw_payload' => 'array',
            'observed_at' => 'immutable_datetime',
        ];
    }

    public function queryItem(): BelongsTo
    {
        return $this->belongsTo(SearchQueryLibraryItem::class, 'search_query_library_item_id');
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(SearchQueryLibraryImport::class, 'search_query_library_import_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(ServiceCatalogItem::class, 'service_catalog_item_id');
    }
}
