<?php

namespace App\Models;

use App\Enums\AutomaticIntelligencePolicyStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Human-approved automatic AI Skill policy (Prompt 63).
 * Pins exact Agent / Skill / Route versions. Never AI-created.
 */
class AutomaticIntelligencePolicy extends Model
{
    protected $fillable = [
        'customer_id',
        'brand_id',
        'digital_asset_id',
        'agent_slug',
        'agent_version',
        'skill_signature',
        'skill_version',
        'route_key',
        'route_signature',
        'allowed_trigger_kinds',
        'trigger_on_required_evidence_change',
        'trigger_on_optional_evidence_change',
        'max_automatic_runs_per_window',
        'window_minutes',
        'min_interval_minutes',
        'max_fanout_per_plan',
        'status',
        'policy_fingerprint',
        'policy_version',
        'created_by',
        'last_automatic_run_at',
        'runs_in_window',
        'window_started_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allowed_trigger_kinds' => 'array',
            'trigger_on_required_evidence_change' => 'boolean',
            'trigger_on_optional_evidence_change' => 'boolean',
            'status' => AutomaticIntelligencePolicyStatus::class,
            'last_automatic_run_at' => 'datetime',
            'window_started_at' => 'datetime',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function isActive(): bool
    {
        return $this->status === AutomaticIntelligencePolicyStatus::Active;
    }
}
