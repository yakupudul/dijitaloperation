<?php

namespace App\Models;

use App\Enums\BusinessOutcomeImportBatchStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'customer_id',
    'brand_id',
    'imported_by',
    'status',
    'file_checksum',
    'original_filename',
    'row_count',
    'valid_count',
    'error_count',
    'committed_count',
    'validation_errors',
    'validated_at',
    'committed_at',
    'idempotency_key',
])]
class BusinessOutcomeImportBatch extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BusinessOutcomeImportBatchStatus::class,
            'validation_errors' => 'array',
            'validated_at' => 'datetime',
            'committed_at' => 'datetime',
            'row_count' => 'integer',
            'valid_count' => 'integer',
            'error_count' => 'integer',
            'committed_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    /**
     * @return HasMany<BusinessOutcomeObservationRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(BusinessOutcomeObservationRevision::class, 'import_batch_id');
    }
}
