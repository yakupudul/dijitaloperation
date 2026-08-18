<?php

namespace App\Models\DataPool;

use App\Enums\DataPool\MaterializationStatus;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionRun;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DatasetMaterialization extends Model
{
    protected $table = 'dataset_materializations';

    protected $fillable = [
        'dataset_id',
        'digital_asset_id',
        'external_resource_id',
        'provider_or_source',
        'contract_version',
        'coverage_start_date',
        'coverage_end_date',
        'last_successful_collection_run_id',
        'last_successful_dataset_run_id',
        'last_collected_at',
        'last_source_data_at',
        'row_count_approx',
        'row_count_semantics',
        'status',
        'partial',
        'freshness_metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => MaterializationStatus::class,
            'coverage_start_date' => 'date',
            'coverage_end_date' => 'date',
            'last_collected_at' => 'datetime',
            'last_source_data_at' => 'datetime',
            'partial' => 'boolean',
            'freshness_metadata' => 'array',
            'row_count_approx' => 'integer',
            'contract_version' => 'integer',
        ];
    }

    public function lastSuccessfulCollectionRun(): BelongsTo
    {
        return $this->belongsTo(CollectionRun::class, 'last_successful_collection_run_id');
    }

    public function lastSuccessfulDatasetRun(): BelongsTo
    {
        return $this->belongsTo(CollectionDatasetRun::class, 'last_successful_dataset_run_id');
    }
}
