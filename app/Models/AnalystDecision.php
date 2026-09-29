<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One AI decision of the brand workspace (a card): what to do, why (one sentence with a number from the pack),
 * priority 1 (most urgent) – 5, the action and the pack facts behind it. Persisted by fingerprint across runs.
 */
class AnalystDecision extends Model
{
    public const string OPEN = 'open';

    public const string DONE = 'done';

    public const string DISMISSED = 'dismissed';

    public const string SNOOZED = 'snoozed';

    /** Not proposed by the latest run any more (hidden). */
    public const string EXPIRED = 'expired';

    public const array CHANNEL_LABELS = ['search' => 'Arama', 'maps' => 'Harita', 'google_ads' => 'Google Ads', 'meta' => 'Meta'];

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'impact' => 'array', 'evidence_refs' => 'array', 'evidence' => 'array', 'action_params' => 'array',
            'baseline' => 'array', 'outcome' => 'array', 'priority' => 'integer',
            'snoozed_until' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime',
            'first_seen_at' => 'immutable_datetime', 'last_seen_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsTo<AnalystRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AnalystRun::class, 'analyst_run_id');
    }

    /**
     * Open cards: open, or snoozed whose date has passed.
     *
     * @param  Builder<AnalystDecision>  $query
     * @return Builder<AnalystDecision>
     */
    public function scopeActionable(Builder $query): Builder
    {
        return $query->where(fn (Builder $q): Builder => $q->where('status', self::OPEN)
            ->orWhere(fn (Builder $s): Builder => $s->where('status', self::SNOOZED)->where('snoozed_until', '<=', now())));
    }

    public function channelLabel(): string
    {
        return self::CHANNEL_LABELS[$this->channel] ?? $this->channel;
    }
}
