<?php

namespace App\Services\Sales;

use App\Models\SalesLead;
use App\Models\SalesLeadEvent;
use App\Models\User;
use App\Models\WhatsAppMessage;
use Carbon\CarbonImmutable;

/**
 * Satış'ı gerçek işe indirmek (yakup 2026-11-09): the phone backup and Meta's history sync brought hundreds of old chats,
 * each became an open card with a red "gecikti" step, and the real prospects drowned. Rules, the same for every channel
 * that imports its past:
 *  - a card known only from imported history (history_only) owes no answer and gets no automatic step: whether it was
 *    answered on the phone is not known, so the system says nothing rather than something false;
 *  - such a card silent for STALE_DAYS goes to the archive (Satış › Arşiv): out of every list and count, never deleted;
 *  - a live message, or the operator's own hand on the card, brings it back.
 * Cards the operator worked (a note, a call, an offer, a sent text, Güçlü aday) are never archived by this.
 */
final class SalesTidy
{
    public const int STALE_DAYS = 14;

    /** Kinds of events only the operator writes. */
    private const array OPERATOR_KINDS = ['note', 'call', 'meeting', 'offer', 'message_out', 'share'];

    /** Automatic steps that assume the last word was theirs or that nobody wrote yet: untrue for history (answered on the phone?). */
    private const array UNSAFE_STEPS = ['Cevap yaz', 'İlk mesajı gönder'];

    public static function hasOperatorTrace(SalesLead $lead): bool
    {
        if ($lead->is_strong || $lead->offer_confirmed || $lead->came_via === 'referral' || $lead->created_by !== null) {
            return true;
        }
        if (! $lead->exists) {
            return false;
        }

        return SalesLeadEvent::query()->where('lead_id', $lead->id)->where(fn ($q) => $q->whereIn('kind', self::OPERATOR_KINDS)
            ->orWhere(fn ($s) => $s->where('kind', 'stage')->whereNotNull('meta->by')))->exists();
    }

    /** An open prospect card known only from history, silent for STALE_DAYS, that nobody worked on. */
    public static function isStale(SalesLead $lead): bool
    {
        if (! $lead->history_only || ! $lead->isOpen() || $lead->lifecycle !== 'prospect' || $lead->archived_at !== null) {
            return false;
        }
        $last = $lead->lastTouchAt() ?? $lead->created_at;

        return $last !== null && $last->lt(now()->subDays(self::STALE_DAYS)) && ! self::hasOperatorTrace($lead);
    }

    public static function isUnsafeStep(?string $step): bool
    {
        foreach (self::UNSAFE_STEPS as $prefix) {
            if (str_starts_with((string) $step, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The hourly round: marks which chat cards are history only, takes their "answer now" steps off, archives the stale
     * ones. Returns what changed.
     *
     * @return array{history: int, live: int, steps: int, archived: int}
     */
    public function sweep(): array
    {
        $live = WhatsAppMessage::query()->where('is_history', false)->distinct()->pluck('conversation_id')->flip();
        $stats = ['history' => 0, 'live' => 0, 'steps' => 0, 'archived' => 0];
        SalesLead::query()->whereNotNull('conversation_id')->where('source', 'whatsapp')->orderBy('id')
            ->chunkById(200, function ($leads) use ($live, &$stats): void {
                foreach ($leads as $lead) {
                    $historyOnly = ! isset($live[$lead->conversation_id]);
                    if ((bool) $lead->history_only !== $historyOnly) {
                        $lead->history_only = $historyOnly;
                        $stats[$historyOnly ? 'history' : 'live']++;
                    }
                    if ($historyOnly && $lead->isOpen() && self::isUnsafeStep($lead->next_step)) {
                        $lead->next_step = null;
                        $lead->next_step_at = null;
                        $stats['steps']++;
                    }
                    // We already answered (live): a leftover "Cevap yaz" turns into the follow-up after our message.
                    if (! $historyOnly && $lead->isOpen() && self::isUnsafeStep($lead->next_step) && ! $lead->awaitsUs() && $lead->last_contact_at !== null) {
                        [$lead->next_step, $lead->next_step_at] = FollowUpSequence::afterOurMessage(max(1, (int) $lead->followup_step), CarbonImmutable::instance($lead->last_contact_at));
                        $stats['steps']++;
                    }
                    if (self::isStale($lead)) {
                        $lead->archived_at = now();
                        $lead->archive_reason = 'history';
                        $stats['archived']++;
                    } elseif (! $historyOnly && $lead->archived_at !== null && $lead->archive_reason === 'history') {
                        $lead->archived_at = null;
                        $lead->archive_reason = null;
                    }
                    if ($lead->isDirty()) {
                        $lead->saveQuietly();
                    }
                }
            });

        return $stats;
    }

    public function archive(SalesLead $lead, ?User $user = null): void
    {
        $lead->forceFill(['archived_at' => now(), 'archive_reason' => 'manual'])->save();
        app(SalesLeads::class)->event($lead, 'system', 'Arşive alındı.', meta: ['by' => $user?->id]);
    }

    public function restore(SalesLead $lead, ?User $user = null): void
    {
        $lead->forceFill(['archived_at' => null, 'archive_reason' => null])->save();
        app(SalesLeads::class)->event($lead, 'note', 'Arşivden geri alındı.', meta: ['by' => $user?->id]);
    }
}
