<?php

namespace App\Models;

use App\Enums\ReportArtifactType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/**
 * Immutable PDF (or other) artifact bound to a Report Snapshot (Prompt 60).
 */
class ReportArtifact extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'report_snapshot_id',
        'artifact_type',
        'snapshot_schema_version',
        'renderer_version',
        'content_checksum',
        'file_checksum',
        'storage_disk',
        'storage_path',
        'mime_type',
        'byte_size',
        'generated_by',
        'generated_at',
        'idempotency_key',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'artifact_type' => ReportArtifactType::class,
            'byte_size' => 'integer',
            'generated_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ReportSnapshot, $this>
     */
    public function reportSnapshot(): BelongsTo
    {
        return $this->belongsTo(ReportSnapshot::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function generatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        if ($this->exists) {
            throw ValidationException::withMessages([
                'report_artifact' => 'REPORT_ARTIFACT_IMMUTABLE',
            ]);
        }

        return parent::update($attributes, $options);
    }
}
