<?php

namespace App\Models;

use Database\Factories\RunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Run is either asset-scoped (`digital_asset_id`) or Integration-scoped
 * (`core_integration_id`) — e.g. a pre-binding "Meta history import" Run that is not
 * yet tied to a Digital Asset. Exactly one of the two must be present.
 *
 * This invariant is not a DB constraint (SQLite/MySQL both lack a portable
 * CHECK-with-FK story without new packages) — callers that create a Run must set one
 * of the two columns. Use `isAssetScoped()` / `isIntegrationScoped()` to branch safely.
 */
#[Fillable([
    'digital_asset_id',
    'core_connection_id',
    'core_asset_binding_id',
    'core_integration_id',
    'module_id',
    'status',
    'started_at',
    'finished_at',
    'metadata',
])]
class Run extends Model
{
    /** @use HasFactory<RunFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<DigitalAsset, $this>
     */
    public function digitalAsset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class);
    }

    /**
     * @return BelongsTo<CoreConnection, $this>
     */
    public function coreConnection(): BelongsTo
    {
        return $this->belongsTo(CoreConnection::class);
    }

    /**
     * Integration-scoped provenance for pre-binding operations (e.g. Meta history import).
     *
     * @return BelongsTo<CoreIntegration, $this>
     */
    public function coreIntegration(): BelongsTo
    {
        return $this->belongsTo(CoreIntegration::class);
    }

    public function isAssetScoped(): bool
    {
        return $this->digital_asset_id !== null;
    }

    public function isIntegrationScoped(): bool
    {
        return $this->core_integration_id !== null;
    }

    /**
     * Agency provider collection provenance (Integration → External Resource → Binding).
     *
     * @return BelongsTo<CoreAssetBinding, $this>
     */
    public function coreAssetBinding(): BelongsTo
    {
        return $this->belongsTo(CoreAssetBinding::class);
    }

    /**
     * @return HasMany<Evidence, $this>
     */
    public function evidence(): HasMany
    {
        return $this->hasMany(Evidence::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
