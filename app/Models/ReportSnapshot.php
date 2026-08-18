<?php

namespace App\Models;

use App\Enums\ReportSnapshotSchemaVersion;
use App\Enums\ReportType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/**
 * Immutable historical Report Snapshot (Prompt 59).
 * Not live Client Value Story. Not delivery. Not PDF.
 */
class ReportSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'customer_id',
        'brand_id',
        'report_type',
        'period_start',
        'period_end',
        'comparison_period_start',
        'comparison_period_end',
        'title_snapshot',
        'customer_name_snapshot',
        'brand_name_snapshot',
        'locale',
        'reporting_timezone',
        'snapshot_schema_version',
        'content_payload',
        'source_manifest_payload',
        'source_manifest_fingerprint',
        'content_checksum',
        'generated_by',
        'generated_at',
        'supersedes_snapshot_id',
        'idempotency_key',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'report_type' => ReportType::class,
            'snapshot_schema_version' => ReportSnapshotSchemaVersion::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'comparison_period_start' => 'date',
            'comparison_period_end' => 'date',
            'content_payload' => 'array',
            'source_manifest_payload' => 'array',
            'generated_at' => 'datetime',
            'created_at' => 'datetime',
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
    public function generatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /**
     * @return BelongsTo<ReportSnapshot, $this>
     */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_snapshot_id');
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        if ($this->exists) {
            throw ValidationException::withMessages([
                'report_snapshot' => 'REPORT_SNAPSHOT_IMMUTABLE',
            ]);
        }

        return parent::update($attributes, $options);
    }

    public function save(array $options = []): bool
    {
        if ($this->exists && $this->isDirty()) {
            $immutable = [
                'customer_id', 'brand_id', 'report_type', 'period_start', 'period_end',
                'comparison_period_start', 'comparison_period_end', 'title_snapshot',
                'customer_name_snapshot', 'brand_name_snapshot', 'locale', 'reporting_timezone',
                'snapshot_schema_version', 'content_payload', 'source_manifest_payload',
                'source_manifest_fingerprint', 'content_checksum', 'generated_by',
                'generated_at', 'supersedes_snapshot_id', 'idempotency_key', 'created_at',
            ];
            foreach ($immutable as $field) {
                if ($this->isDirty($field)) {
                    throw ValidationException::withMessages([
                        'report_snapshot' => 'REPORT_SNAPSHOT_IMMUTABLE',
                    ]);
                }
            }
        }

        return parent::save($options);
    }
}
