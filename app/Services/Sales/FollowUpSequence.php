<?php

namespace App\Services\Sales;

use App\Models\SalesLead;
use Carbon\CarbonImmutable;

/**
 * When the next step of a prospect falls due (yakup's sales rules, 2026-10-31 → 2026-11-03): a hot lead who wrote
 * gets an answer the same day; after a message of ours nothing is planned by itself (Satış Düzeni 2.3, 2026-11-11): the
 * card waits for their answer and closes as "Sessiz kaldı" after 21 silent days; a cold lead is re-warmed in December
 * with a new finding. Steps land on a weekday morning.
 */
final class FollowUpSequence
{
    public const int QUARTER_DAYS = 90;

    /** Cold leads (no answer for this long) wait for the next re-warm round instead of filling Bugün. */
    public const int COLD_AFTER_DAYS = 45;

    /** The next re-warm round of the silent leads (first week of December, yakup's plan). */
    public const string REWARM_ON = '2026-12-01';

    /** A prospect silent this long after our last message closes on its own as "Sessiz kaldı" (Satış Düzeni 2.3). */
    public const int SILENT_CLOSE_DAYS = 21;

    /** The follow-up steps the old 2 / 7 / 21 day sequence wrote by itself; they are taken off wherever they still stand. */
    public const array AUTO_STEPS = ['Tek satır hatırlat', 'Yeni bir bulgu ile yaz', 'Kapanış sorusu sor', 'Yeni bir şeyle yaz (vaka, kampanya)'];

    /**
     * After a message of ours the ball is theirs: no automatic follow-up step any more (they piled up as overdue work,
     * Satış Düzeni 2.3). The card shows "Cevap bekleniyor" and closes by itself after SILENT_CLOSE_DAYS; a step the
     * operator writes by hand stays.
     *
     * @return array{0: null, 1: null}
     */
    public static function afterOurMessage(int $sent = 1, ?CarbonImmutable $from = null): array
    {
        return [null, null];
    }

    /** They wrote and we have not answered: the step is today. */
    public static function answerNow(): array
    {
        return ['Cevap yaz', CarbonImmutable::now('Europe/Istanbul')];
    }

    /** A new lead nobody has written to: the first message is today. */
    public static function firstMessage(): array
    {
        return ['İlk mesajı gönder', CarbonImmutable::now('Europe/Istanbul')];
    }

    /** A lead that went silent long ago: the next re-warm round. */
    public static function rewarm(): array
    {
        $on = CarbonImmutable::parse(self::REWARM_ON, 'Europe/Istanbul');
        if ($on->isPast()) {
            $on = self::weekdayMorning(CarbonImmutable::now('Europe/Istanbul')->addDays(self::QUARTER_DAYS));
        }

        return ['Yeniden ısıt: yeni bulgu ya da vaka ile yaz', $on];
    }

    public static function isCold(SalesLead $lead): bool
    {
        $last = $lead->last_incoming_at ?? $lead->last_contact_at;

        return $last !== null && $last->lt(now()->subDays(self::COLD_AFTER_DAYS));
    }

    /** Saturday and Sunday move to Monday; the time is 10:00 Istanbul. */
    public static function weekdayMorning(CarbonImmutable $day): CarbonImmutable
    {
        $day = $day->setTimezone('Europe/Istanbul')->setTime(10, 0);
        while ($day->isWeekend()) {
            $day = $day->addDay();
        }

        return $day;
    }
}
