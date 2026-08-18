<?php

namespace App\Models;

use App\Enums\IntentRadarRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesIntentRadarRun extends Model
{
    protected $fillable = [
        'sales_search_profile_id',
        'status',
        'provider',
        'provider_reality',
        'query_count',
        'signal_count',
        'paid_call',
        'reported_cost_usd',
        'query_plan',
        'error_summary',
        'metadata',
        'started_at',
        'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => IntentRadarRunStatus::class,
            'query_count' => 'integer',
            'signal_count' => 'integer',
            'paid_call' => 'boolean',
            'reported_cost_usd' => 'float',
            'query_plan' => 'array',
            'error_summary' => 'array',
            'metadata' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SalesSearchProfile, $this>
     */
    public function searchProfile(): BelongsTo
    {
        return $this->belongsTo(SalesSearchProfile::class, 'sales_search_profile_id');
    }

    /**
     * @return HasMany<SalesIntentSignal, $this>
     */
    public function signals(): HasMany
    {
        return $this->hasMany(SalesIntentSignal::class);
    }
}
