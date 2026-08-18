<?php

namespace App\Models;

use App\Enums\PlaybookApplicabilityMode;
use Database\Factories\PlaybookRevisionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'playbook_id',
    'revision_number',
    'title',
    'summary',
    'knowledge',
    'cadence',
    'service_applicability_mode',
    'asset_applicability_mode',
    'execution_scope_mode',
    'content_fingerprint',
    'created_by',
    'idempotency_key',
])]
class PlaybookRevision extends Model
{
    /** @use HasFactory<PlaybookRevisionFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Playbook, $this>
     */
    public function playbook(): BelongsTo
    {
        return $this->belongsTo(Playbook::class);
    }

    /**
     * @return HasMany<PlaybookInstruction, $this>
     */
    public function instructions(): HasMany
    {
        return $this->hasMany(PlaybookInstruction::class)->orderBy('position');
    }

    /**
     * @return HasMany<PlaybookReference, $this>
     */
    public function references(): HasMany
    {
        return $this->hasMany(PlaybookReference::class)->orderBy('position');
    }

    /**
     * @return BelongsToMany<ServiceDefinition, $this>
     */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(
            ServiceDefinition::class,
            'playbook_revision_services',
            'playbook_revision_id',
            'service_definition_id'
        )->withTimestamps();
    }

    /**
     * @return HasMany<PlaybookRevisionAssetType, $this>
     */
    public function assetTypes(): HasMany
    {
        return $this->hasMany(PlaybookRevisionAssetType::class);
    }

    /**
     * @return HasMany<PlaybookRevisionExecutionScope, $this>
     */
    public function executionScopes(): HasMany
    {
        return $this->hasMany(PlaybookRevisionExecutionScope::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'knowledge' => 'array',
            'service_applicability_mode' => PlaybookApplicabilityMode::class,
            'asset_applicability_mode' => PlaybookApplicabilityMode::class,
            'execution_scope_mode' => PlaybookApplicabilityMode::class,
            'revision_number' => 'integer',
        ];
    }
}
