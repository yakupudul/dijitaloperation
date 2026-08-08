<?php

namespace App\Models;

use Database\Factories\CoreIntegrationCredentialFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'integration_id',
    'credential_type',
    'encrypted_payload',
    'expires_at',
    'refreshed_at',
])]
#[Hidden([
    'encrypted_payload',
])]
class CoreIntegrationCredential extends Model
{
    /** @use HasFactory<CoreIntegrationCredentialFactory> */
    use HasFactory;

    public const string TYPE_PROVIDER = 'provider';

    public const string TYPE_AUTHORIZATION = 'authorization';

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'encrypted_payload' => 'encrypted:array',
        'expires_at' => 'datetime',
        'refreshed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<CoreIntegration, $this>
     */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(CoreIntegration::class, 'integration_id');
    }

    public function isProvider(): bool
    {
        return $this->credential_type === self::TYPE_PROVIDER;
    }

    public function isAuthorization(): bool
    {
        return $this->credential_type === self::TYPE_AUTHORIZATION;
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeProvider(Builder $query): Builder
    {
        return $query->where('credential_type', self::TYPE_PROVIDER);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeAuthorization(Builder $query): Builder
    {
        return $query->where('credential_type', self::TYPE_AUTHORIZATION);
    }
}
