<?php

namespace App\Models\DataPool;

use App\Enums\DataPool\IntegrityCheckStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataIntegrityCheckResult extends Model
{
    protected $table = 'data_integrity_check_results';

    protected $fillable = [
        'audit_run_id',
        'provider_or_source',
        'digital_asset_id',
        'external_resource_id',
        'dataset_id',
        'check_id',
        'category',
        'severity',
        'status',
        'expected',
        'observed',
        'difference',
        'tolerance',
        'message',
        'evidence',
        'blocks_migration',
    ];

    protected function casts(): array
    {
        return [
            'status' => IntegrityCheckStatus::class,
            'expected' => 'array',
            'observed' => 'array',
            'difference' => 'array',
            'tolerance' => 'array',
            'evidence' => 'array',
            'blocks_migration' => 'boolean',
        ];
    }

    public function auditRun(): BelongsTo
    {
        return $this->belongsTo(DataIntegrityAuditRun::class, 'audit_run_id');
    }
}
