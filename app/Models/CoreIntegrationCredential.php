<?php

namespace App\Models;

use Database\Factories\CoreIntegrationCredentialFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'integration_id',
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
}
