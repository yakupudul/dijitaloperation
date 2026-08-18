<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class ProspectReportArtifact extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'prospect_report_snapshot_id',
        'artifact_type',
        'renderer_version',
        'disk',
        'path',
        'checksum',
        'byte_size',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ProspectReportSnapshot, $this>
     */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(ProspectReportSnapshot::class, 'prospect_report_snapshot_id');
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        if ($this->exists) {
            throw ValidationException::withMessages([
                'prospect_report_artifact' => 'PROSPECT_REPORT_ARTIFACT_IMMUTABLE',
            ]);
        }

        return parent::update($attributes, $options);
    }
}
