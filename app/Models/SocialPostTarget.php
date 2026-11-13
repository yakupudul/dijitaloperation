<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One channel of a social post (ADR-083) and what happened when it was sent. */
class SocialPostTarget extends Model
{
    public const string PENDING = 'pending';

    /** Instagram is still processing the uploaded video; checked again on the next run. */
    public const string PROCESSING = 'processing';

    public const string PUBLISHED = 'published';

    public const string FAILED = 'failed';

    public const string SKIPPED = 'skipped';

    /** LinkedIn: its time came, the operator copies the text and posts it. */
    public const string MANUAL = 'manual';

    /** LinkedIn: the operator marked it as posted. */
    public const string DONE = 'done';

    public const int MAX_ATTEMPTS = 3;

    protected $guarded = [];

    /** @return BelongsTo<SocialPost, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class, 'social_post_id');
    }

    /** @return BelongsTo<SocialAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::PUBLISHED, self::SKIPPED, self::DONE], true);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::PENDING => 'Sırada',
            self::PROCESSING => 'Hazırlanıyor',
            self::PUBLISHED => 'Yayınlandı',
            self::FAILED => 'Yayınlanamadı',
            self::SKIPPED => 'Atlandı',
            self::MANUAL => 'Paylaşmanız bekleniyor',
            self::DONE => 'Paylaşıldı',
            default => (string) $this->status,
        };
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'insights' => 'array', 'insights_at' => 'datetime'];
    }
}
