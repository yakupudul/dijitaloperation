<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Security-significant mutations without secret values (Prompt 64).
 */
class SecurityAuditEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'kind',
        'actor_user_id',
        'customer_id',
        'brand_id',
        'integration_id',
        'provider',
        'reason',
        'metadata',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
