<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Authenticated share session after successful verification (Prompt 60).
 */
class ReportShareSession extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'share_grant_id',
        'session_token_hash',
        'expires_at',
        'revoked_at',
        'last_seen_at',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ReportShareGrant, $this>
     */
    public function shareGrant(): BelongsTo
    {
        return $this->belongsTo(ReportShareGrant::class, 'share_grant_id');
    }

    public function isActive(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        return $this->expires_at !== null && $this->expires_at->isFuture();
    }
}
