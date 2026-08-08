<?php

namespace App\Models;

use Database\Factories\CoreIntegrationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'provider',
    'name',
    'status',
    'config',
    'last_success_at',
    'last_error',
])]
class CoreIntegration extends Model
{
    /** @use HasFactory<CoreIntegrationFactory> */
    use HasFactory;

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_DISABLED = 'disabled';

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'config' => 'array',
        'last_success_at' => 'datetime',
    ];

    /**
     * All encrypted credential rows for this integration (provider + authorization).
     *
     * @return HasMany<CoreIntegrationCredential, $this>
     */
    public function credentials(): HasMany
    {
        return $this->hasMany(CoreIntegrationCredential::class, 'integration_id');
    }

    /**
     * OAuth / authorization tokens only.
     *
     * Kept as `credential()` for backwards-compatible eager loads and call sites.
     *
     * @return HasOne<CoreIntegrationCredential, $this>
     */
    public function credential(): HasOne
    {
        return $this->authorizationCredential();
    }

    /**
     * @return HasOne<CoreIntegrationCredential, $this>
     */
    public function authorizationCredential(): HasOne
    {
        return $this->hasOne(CoreIntegrationCredential::class, 'integration_id')
            ->where('credential_type', CoreIntegrationCredential::TYPE_AUTHORIZATION);
    }

    /**
     * Static provider/application secrets (Client ID/Secret, developer token, API keys).
     *
     * @return HasOne<CoreIntegrationCredential, $this>
     */
    public function providerCredential(): HasOne
    {
        return $this->hasOne(CoreIntegrationCredential::class, 'integration_id')
            ->where('credential_type', CoreIntegrationCredential::TYPE_PROVIDER);
    }

    /**
     * @return HasMany<CoreExternalResource, $this>
     */
    public function externalResources(): HasMany
    {
        return $this->hasMany(CoreExternalResource::class, 'integration_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
