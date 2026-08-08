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
     * @return HasOne<CoreIntegrationCredential, $this>
     */
    public function credential(): HasOne
    {
        return $this->hasOne(CoreIntegrationCredential::class, 'integration_id');
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
