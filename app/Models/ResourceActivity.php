<?php

namespace App\Models;

use App\Enums\Collection\ActivityTier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Activity tier + operator pause flag of one collected provider account/property (resource_activity).
 */
class ResourceActivity extends Model
{
    protected $table = 'resource_activity';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tier' => ActivityTier::class,
            'last_active_on' => 'immutable_date',
            'backfill_from' => 'immutable_date',
            'tier_since' => 'immutable_datetime',
            'operator_paused_at' => 'immutable_datetime',
            'last_light_check_at' => 'immutable_datetime',
            'last_full_collection_at' => 'immutable_datetime',
            'last_deep_restatement_at' => 'immutable_datetime',
            'refreshed_at' => 'immutable_datetime',
        ];
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(CoreExternalResource::class, 'external_resource_id');
    }

    public function isPaused(): bool
    {
        return $this->operator_paused_at !== null;
    }

    /** The tier collection follows: an operator pause counts as dormant. */
    public function effectiveTier(): ActivityTier
    {
        return $this->isPaused() ? ActivityTier::Dormant : $this->tier;
    }
}
