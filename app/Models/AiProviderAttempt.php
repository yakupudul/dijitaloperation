<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'skill_execution_run_id',
    'attempt_number',
    'provider',
    'model',
    'status',
    'provider_request_id',
    'error_category',
    'usage',
    'latency_ms',
    'started_at',
    'completed_at',
])]
class AiProviderAttempt extends Model
{
    public const string STATUS_STARTED = 'started';

    public const string STATUS_SUCCEEDED = 'succeeded';

    public const string STATUS_FAILED = 'failed';

    /**
     * @return BelongsTo<SkillExecutionRun, $this>
     */
    public function skillExecutionRun(): BelongsTo
    {
        return $this->belongsTo(SkillExecutionRun::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'usage' => 'array',
            'attempt_number' => 'integer',
            'latency_ms' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
