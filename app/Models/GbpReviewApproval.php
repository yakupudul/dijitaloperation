<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A brand approval link for prepared review replies (Yorumlar › "Markaya onaya gönder"). `items` is the copy the brand
 * sees: review_id, business, reviewer (first name), rating, comment, date, text, and after the brand answered
 * decision (ok | edit | skip) and brand_text.
 */
class GbpReviewApproval extends Model
{
    public const string OPEN = 'open';

    public const string ANSWERED = 'answered';

    public const string CLOSED = 'closed';

    public const array DECISIONS = ['ok' => 'Marka onayladı', 'edit' => 'Marka düzeltti', 'skip' => 'Marka istemedi'];

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['items' => 'array', 'expires_at' => 'datetime', 'answered_at' => 'datetime'];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function isUsable(): bool
    {
        return $this->status !== self::CLOSED && $this->expires_at->isFuture();
    }
}
