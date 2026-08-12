<?php

namespace MoxDop\MetaAds\Models;

use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Meta entity (account/campaign/adset/ad/creative) discovered via history import.
 * Anchored to CoreIntegration + CoreExternalResource (Meta Ad Account), not a Digital
 * Asset — history is importable before an operator binds the account. Provider
 * external ID is the canonical identity for joins, never a MoxDOP surrogate key.
 */
#[Fillable([
    'core_integration_id',
    'core_external_resource_id',
    'entity_type',
    'provider_external_id',
    'parent_provider_external_id',
    'name',
    'status',
    'objective',
    'optimization_goal',
    'destination_type',
    'creative_provider_id',
    'currency',
    'metadata',
    'first_seen_at',
    'last_seen_at',
])]
class MetaAdsEntity extends Model
{
    public const string TYPE_ACCOUNT = 'account';

    public const string TYPE_CAMPAIGN = 'campaign';

    public const string TYPE_ADSET = 'adset';

    public const string TYPE_AD = 'ad';

    public const string TYPE_CREATIVE = 'creative';

    protected $table = 'meta_ads_entities';

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'metadata' => 'array',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<CoreIntegration, $this>
     */
    public function coreIntegration(): BelongsTo
    {
        return $this->belongsTo(CoreIntegration::class);
    }

    /**
     * @return BelongsTo<CoreExternalResource, $this>
     */
    public function coreExternalResource(): BelongsTo
    {
        return $this->belongsTo(CoreExternalResource::class);
    }
}
