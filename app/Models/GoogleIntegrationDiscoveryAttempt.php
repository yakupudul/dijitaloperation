<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoogleIntegrationDiscoveryAttempt extends Model
{
    protected $table = 'google_integration_discovery_attempts';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'integration_id',
        'connector',
        'status',
        'complete_inventory',
        'resources_seen',
        'resources_created',
        'resources_updated',
        'resources_unchanged',
        'resources_marked_unavailable',
        'error_category',
        'safe_error_message',
        'triggered_by_user_id',
        'started_at',
        'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'complete_inventory' => 'boolean',
            'resources_seen' => 'integer',
            'resources_created' => 'integer',
            'resources_updated' => 'integer',
            'resources_unchanged' => 'integer',
            'resources_marked_unavailable' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(CoreIntegration::class, 'integration_id');
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }
}
