<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoogleOAuthAuthorizationAttempt extends Model
{
    protected $table = 'google_oauth_authorization_attempts';

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_CONSUMED = 'consumed';

    public const string STATUS_EXPIRED = 'expired';

    public const string STATUS_FAILED = 'failed';

    public const string STATUS_DENIED = 'denied';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'integration_id',
        'requested_by_user_id',
        'state_hash',
        'requested_scopes',
        'capability_context',
        'return_route',
        'return_params',
        'status',
        'provider_error_code',
        'safe_error_message',
        'expires_at',
        'consumed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requested_scopes' => 'array',
            'return_params' => 'array',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CoreIntegration, $this>
     */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(CoreIntegration::class, 'integration_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING
            && $this->consumed_at === null
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    public static function hashState(string $state): string
    {
        return hash('sha256', $state);
    }
}
