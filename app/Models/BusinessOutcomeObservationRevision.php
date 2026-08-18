<?php

namespace App\Models;

use App\Enums\BusinessOutcomeCompleteness;
use App\Enums\BusinessOutcomeSourceKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'observation_id',
    'revision_number',
    'value_numeric',
    'value_count',
    'currency_code',
    'completeness',
    'source_kind',
    'recorded_by',
    'recorded_at',
    'correction_reason',
    'import_batch_id',
    'import_row_number',
    'row_fingerprint',
    'source_note',
    'definition_version_snapshot',
    'semantic_definition_snapshot',
])]
class BusinessOutcomeObservationRevision extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value_numeric' => 'decimal:4',
            'value_count' => 'integer',
            'completeness' => BusinessOutcomeCompleteness::class,
            'source_kind' => BusinessOutcomeSourceKind::class,
            'recorded_at' => 'datetime',
            'revision_number' => 'integer',
            'definition_version_snapshot' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<BusinessOutcomeObservation, $this>
     */
    public function observation(): BelongsTo
    {
        return $this->belongsTo(BusinessOutcomeObservation::class, 'observation_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * @return BelongsTo<BusinessOutcomeImportBatch, $this>
     */
    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(BusinessOutcomeImportBatch::class, 'import_batch_id');
    }
}
