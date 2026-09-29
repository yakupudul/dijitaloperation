<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One AI analysis of one brand × channel (weekly or "Yeniden analiz et"). */
class AnalystRun extends Model
{
    public const string QUEUED = 'queued';

    public const string RUNNING = 'running';

    public const string DONE = 'done';

    /** No data for the channel (one-line reason in `error`), or the AI is off: nothing was asked. */
    public const string SKIPPED = 'skipped';

    public const string FAILED = 'failed';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'stats' => 'array', 'dropped' => 'array', 'cost_usd' => 'float',
            'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return HasMany<Suggestion, $this> */
    public function decisions(): HasMany
    {
        return $this->hasMany(Suggestion::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::QUEUED, self::RUNNING], true);
    }
}
