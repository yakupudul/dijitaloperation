<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'agent_execution_run_id',
    'skill_module',
    'skill_slug',
    'skill_version',
    'skill_signature',
    'status',
    'abstention_reason_code',
    'provider_attempt_count',
    'validated_output',
    'eligibility',
    'started_at',
    'completed_at',
])]
class SkillExecutionRun extends Model
{
    public const string STATUS_PENDING = 'pending';

    public const string STATUS_RUNNING = 'running';

    public const string STATUS_VALIDATED = 'validated';

    public const string STATUS_ABSTAINED = 'abstained';

    public const string STATUS_FAILED = 'failed';

    /**
     * @return BelongsTo<AgentExecutionRun, $this>
     */
    public function agentExecutionRun(): BelongsTo
    {
        return $this->belongsTo(AgentExecutionRun::class);
    }

    /**
     * @return HasMany<AiProviderAttempt, $this>
     */
    public function providerAttempts(): HasMany
    {
        return $this->hasMany(AiProviderAttempt::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'validated_output' => 'array',
            'eligibility' => 'array',
            'provider_attempt_count' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
