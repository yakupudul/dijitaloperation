<?php

namespace App\Models;

use App\Support\Integrations\ProviderRegistry;
use Database\Factories\CoreExternalResourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'integration_id',
    'provider',
    'resource_type',
    'external_id',
    'display_name',
    'parent_external_id',
    'metadata',
    'status',
    'discovered_at',
    'last_seen_at',
])]
class CoreExternalResource extends Model
{
    /** @use HasFactory<CoreExternalResourceFactory> */
    use HasFactory;

    public const string STATUS_AVAILABLE = 'available';

    public const string STATUS_UNAVAILABLE = 'unavailable';

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'metadata' => 'array',
        'discovered_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<CoreIntegration, $this>
     */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(CoreIntegration::class, 'integration_id');
    }

    /**
     * @return HasMany<CoreAssetBinding, $this>
     */
    public function bindings(): HasMany
    {
        return $this->hasMany(CoreAssetBinding::class, 'external_resource_id');
    }

    public function optionLabel(): string
    {
        return sprintf(
            '%s · %s · %s (%s)',
            ProviderRegistry::label($this->provider),
            ProviderRegistry::capabilityLabel($this->resource_type),
            $this->display_name,
            $this->external_id,
        );
    }
}
