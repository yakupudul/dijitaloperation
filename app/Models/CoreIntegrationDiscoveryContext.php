<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Control-plane discovery-context selection (NOT CoreAssetBinding).
 * Purpose discovery_context = use this ExternalResource as Ad Account discovery scope.
 */
class CoreIntegrationDiscoveryContext extends Model
{
    public const string PURPOSE_DISCOVERY_CONTEXT = 'discovery_context';

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'integration_id',
        'external_resource_id',
        'purpose',
        'status',
        'selected_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'selected_at' => 'datetime',
        ];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(CoreIntegration::class, 'integration_id');
    }

    public function externalResource(): BelongsTo
    {
        return $this->belongsTo(CoreExternalResource::class, 'external_resource_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
