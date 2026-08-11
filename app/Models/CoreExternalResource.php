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
        if ($this->provider === ProviderRegistry::META && $this->resource_type === 'meta_ads') {
            return $this->metaAdAccountOptionLabel();
        }

        return sprintf(
            '%s · %s · %s (%s)',
            ProviderRegistry::label($this->provider),
            ProviderRegistry::capabilityLabel($this->resource_type),
            $this->display_name,
            $this->external_id,
        );
    }

    /**
     * Operator-facing Meta Ad Account label with Business container context.
     * Meta Business is provider scope — never treated as Brand.
     */
    public function metaAdAccountOptionLabel(): string
    {
        $meta = is_array($this->metadata) ? $this->metadata : [];
        $account = trim((string) ($this->display_name ?: $this->external_id));
        $business = trim((string) ($meta['business_name'] ?? ''));
        if ($business === '' && filled($this->parent_external_id)) {
            $business = 'Business '.$this->parent_external_id;
        }

        $parts = [];
        if ($business !== '') {
            $parts[] = 'Meta Business: '.$business;
        }
        $parts[] = $account !== '' ? $account : 'Ad Account';
        $parts[] = 'ID '.$this->external_id;

        return implode(' · ', $parts);
    }
}
