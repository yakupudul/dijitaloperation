<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A brand approval link for prepared review replies (Yorumlar › "Markaya onaya gönder"). `items` is the copy the brand
 * sees: review_id, business, reviewer (first name), rating, comment, date, text, and after the brand answered
 * decision (ok | edit | skip) and brand_text.
 *
 * `live` (Müşteri onay linki, 2026-10-26): a standing link of one brand or one profile (`digital_asset_id`) that shows
 * the current drafts instead of a copy; `items` only keeps the client's answers. Approved replies go to Google by
 * themselves (ReviewApprovals::answer), for both kinds of link.
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
        return ['items' => 'array', 'live' => 'boolean', 'expires_at' => 'datetime', 'answered_at' => 'datetime'];
    }

    /** @return BelongsTo<DigitalAsset, $this> */
    public function digitalAsset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class);
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
