<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One browser / phone that accepted MoxDOP notifications (Web Push). The endpoint is the push service URL the browser
 * gave; MoxDOP sends an empty push and the service worker fetches the text from /push/latest.
 */
class PushSubscription extends Model
{
    protected $fillable = ['user_id', 'endpoint_hash', 'endpoint', 'public_key', 'auth_token', 'device', 'failures', 'last_sent_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['last_sent_at' => 'datetime', 'failures' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}
