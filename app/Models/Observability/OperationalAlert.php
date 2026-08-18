<?php

namespace App\Models\Observability;

use App\Enums\Observability\OperationalAlertRuleType;
use App\Enums\Observability\OperationalAlertSeverity;
use App\Enums\Observability\OperationalAlertState;
use App\Enums\Observability\OperationalSignalFamily;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Durable operational alert — not a Finding, not a Notification.
 */
class OperationalAlert extends Model
{
    protected $fillable = [
        'semantic_key',
        'rule_key',
        'rule_version',
        'rule_type',
        'signal_family',
        'severity',
        'state',
        'scope_type',
        'scope_key',
        'title',
        'summary',
        'observed',
        'observation_count',
        'first_observed_at',
        'last_observed_at',
        'opened_at',
        'acknowledged_at',
        'acknowledged_by_user_id',
        'ack_note',
        'resolved_at',
        'resolution_kind',
        'notification_emitted',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rule_type' => OperationalAlertRuleType::class,
            'signal_family' => OperationalSignalFamily::class,
            'severity' => OperationalAlertSeverity::class,
            'state' => OperationalAlertState::class,
            'observed' => 'array',
            'observation_count' => 'integer',
            'rule_version' => 'integer',
            'first_observed_at' => 'datetime',
            'last_observed_at' => 'datetime',
            'opened_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
            'notification_emitted' => 'boolean',
        ];
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_user_id');
    }

    public function isActive(): bool
    {
        return in_array($this->state, [
            OperationalAlertState::Open,
            OperationalAlertState::Acknowledged,
        ], true);
    }
}
