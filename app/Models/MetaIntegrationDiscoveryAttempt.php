<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetaIntegrationDiscoveryAttempt extends Model
{
    public const string PHASE_BUSINESSES = 'businesses';

    public const string PHASE_AD_ACCOUNTS = 'ad_accounts';

    public const string STATUS_RUNNING = 'running';

    public const string STATUS_COMPLETED = 'completed';

    public const string STATUS_PARTIAL = 'partial';

    public const string STATUS_FAILED = 'failed';

    public const string STATUS_PERMISSION_REQUIRED = 'permission_required';

    public const string STATUS_AUTHENTICATION_REQUIRED = 'authentication_required';

    protected $fillable = [
        'integration_id',
        'phase',
        'connector',
        'business_resource_id',
        'edge',
        'status',
        'complete_inventory',
        'resources_seen',
        'resources_created',
        'resources_updated',
        'resources_unchanged',
        'resources_marked_unavailable',
        'graph_api_version',
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
