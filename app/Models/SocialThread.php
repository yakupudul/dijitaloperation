<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One conversation in the social inbox (ADR-083, phase 2): an Instagram / Facebook direct message thread or a top-level
 * comment with its replies. The AI drafts the answer; the Admin sends it.
 */
class SocialThread extends Model
{
    public const string DM = 'dm';

    public const string COMMENT = 'comment';

    public const string OPEN = 'open';

    public const string ANSWERED = 'answered';

    public const string IGNORED = 'ignored';

    /** Instagram and Messenger accept a free reply only within 24 hours of the person's last message. */
    public const int DM_WINDOW_HOURS = 24;

    protected $guarded = [];

    /**
     * Only threads of accounts that are switched on: a customer's Page that came in with the Meta login stays off, and
     * its DMs and comments never wait in Moximu's queue, count on a badge or get sent from "Hepsini gönder".
     *
     * @param  Builder<SocialThread>  $query
     */
    public function scopeOnEnabledAccount($query): void
    {
        $query->whereHas('account', fn ($q) => $q->where('enabled', true));
    }

    /** @return BelongsTo<SocialAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }

    /** @return HasMany<SocialMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(SocialMessage::class);
    }

    /** @return BelongsTo<SalesLead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(SalesLead::class, 'sales_lead_id');
    }

    public function kindLabel(): string
    {
        return $this->kind === self::DM ? 'Mesaj' : 'Yorum';
    }

    /** A DM reply is still allowed (24-hour window); comments can always be answered. */
    public function canReply(): bool
    {
        if ($this->kind !== self::DM) {
            return true;
        }

        return $this->last_in_at !== null && $this->last_in_at->gt(now()->subHours(self::DM_WINDOW_HOURS));
    }

    public function waitsForUs(): bool
    {
        return $this->status === self::OPEN && $this->last_direction === 'in';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['last_in_at' => 'datetime', 'last_at' => 'datetime', 'draft_at' => 'datetime', 'draft_lead' => 'boolean', 'snoozed_until' => 'datetime'];
    }
}
