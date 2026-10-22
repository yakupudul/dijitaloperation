<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One approval or send of Akış (Aşama 4, 2026-10-22): a bulk approval on the desk, the daily send, "Hepsini siteye
 * gönder". `total` / `done` / `failed` count the rows it was given (a row may make no write, or several rows one
 * write); `failures` holds the rows that did not go, with their reason; the writes it made point at it.
 */
class WorkBatch extends Model
{
    public const string QUEUED = 'queued';

    public const string RUNNING = 'running';

    public const string DONE = 'done';

    public const string FAILED = 'failed';

    public const string KIND_DESK = 'desk_approval';

    public const string KIND_DAILY = 'daily_send';

    public const string KIND_CONTENT = 'content_send';

    /** Failure rows kept per batch (the rest are only counted). */
    public const int FAILURES_KEPT = 200;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'brand_id' => 'integer',
            'brand_count' => 'integer',
            'user_id' => 'integer',
            'total' => 'integer',
            'done' => 'integer',
            'failed' => 'integer',
            'failures' => 'array',
            'decision_wait_seconds' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<ExternalWriteAction, $this> */
    public function writes(): HasMany
    {
        return $this->hasMany(ExternalWriteAction::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::QUEUED, self::RUNNING], true);
    }
}
