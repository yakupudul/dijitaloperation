<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ONE suggestions table for every channel (search · maps · google_ads · meta): what to do, the one-line reason with
 * a number from the data pack, the evidence behind it, the action to apply, and the outcome follow-up (baseline at
 * apply time, measured at 28 / 56 days). Persisted by fingerprint per brand across runs. Formerly analyst_decisions.
 */
class Suggestion extends Model
{
    public const string OPEN = 'open';

    public const string APPROVED = 'approved';

    public const string APPLIED = 'applied';

    public const string DISMISSED = 'dismissed';

    public const string SNOOZED = 'snoozed';

    /** Based on an old page / data version or no longer proposed: needs a re-check before it is shown as open. */
    public const string RECHECK = 'recheck';

    public const array STATUSES = [self::OPEN, self::APPROVED, self::APPLIED, self::DISMISSED, self::SNOOZED, self::RECHECK];

    public const array CHANNELS = ['search', 'maps', 'google_ads', 'meta'];

    /** After "Yaptım": the next data pull confirms the change (Genel işler). */
    public const string VERIFY_PENDING = 'pending';

    public const string VERIFY_CONFIRMED = 'confirmed';

    /** The data pulled after the change still shows the problem. */
    public const string VERIFY_STILL_SEEN = 'still_seen';

    /** The system check passed by itself: the change was noticed without a click. */
    public const string VERIFY_AUTO = 'auto';

    public const array CHANNEL_LABELS = ['search' => 'Arama', 'maps' => 'Harita', 'google_ads' => 'Google Ads', 'meta' => 'Meta'];

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'impact' => 'array', 'evidence_refs' => 'array', 'evidence' => 'array', 'action' => 'array',
            'baseline' => 'array', 'outcome' => 'array', 'priority' => 'integer',
            'snoozed_until' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime',
            'applied_at' => 'immutable_datetime', 'measured_at' => 'immutable_datetime',
            'first_seen_at' => 'immutable_datetime', 'last_seen_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
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

    /** @return BelongsTo<Page, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /** @return BelongsTo<Cluster, $this> */
    public function cluster(): BelongsTo
    {
        return $this->belongsTo(Cluster::class);
    }

    /** @return BelongsTo<PromptVersion, $this> */
    public function promptVersion(): BelongsTo
    {
        return $this->belongsTo(PromptVersion::class);
    }

    /**
     * Open cards: open, or snoozed whose date has passed.
     *
     * @param  Builder<Suggestion>  $query
     * @return Builder<Suggestion>
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
