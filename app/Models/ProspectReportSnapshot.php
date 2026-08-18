<?php

namespace App\Models;

use App\Enums\ProspectReportProjection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * Immutable Prospect pre-analysis snapshot. Separate from Brand ReportSnapshot.
 */
class ProspectReportSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'prospect_id',
        'prospect_research_run_id',
        'prospect_sales_intelligence_id',
        'projection',
        'locale',
        'title',
        'content_payload',
        'content_checksum',
        'idempotency_key',
        'generated_by',
        'generated_at',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'projection' => ProspectReportProjection::class,
            'content_payload' => 'array',
            'generated_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Prospect, $this>
     */
    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function generatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /**
     * @return HasMany<ProspectReportArtifact, $this>
     */
    public function artifacts(): HasMany
    {
        return $this->hasMany(ProspectReportArtifact::class);
    }

    /**
     * @return HasMany<ProspectReportShareGrant, $this>
     */
    public function shareGrants(): HasMany
    {
        return $this->hasMany(ProspectReportShareGrant::class);
    }

    public function isClientShareable(): bool
    {
        return $this->projection === ProspectReportProjection::ClientShareable;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        if ($this->exists) {
            throw ValidationException::withMessages([
                'prospect_report_snapshot' => 'PROSPECT_REPORT_SNAPSHOT_IMMUTABLE',
            ]);
        }

        return parent::update($attributes, $options);
    }

    public function save(array $options = []): bool
    {
        if ($this->exists && $this->isDirty()) {
            throw ValidationException::withMessages([
                'prospect_report_snapshot' => 'PROSPECT_REPORT_SNAPSHOT_IMMUTABLE',
            ]);
        }

        return parent::save($options);
    }
}
