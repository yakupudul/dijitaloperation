<?php

namespace App\Services\Sales;

use App\Models\SalesLead;
use Carbon\CarbonImmutable;

/**
 * When the next step of a prospect falls due (yakup's sales rules, 2026-10-31 → 2026-11-03): a hot lead who wrote
 * gets an answer the same day; after a message of ours the follow-ups come on day 2, 7 and 21, then once a quarter
 * with something new; a cold lead is re-warmed in December with a new finding. Steps land on a weekday morning.
 */
final class FollowUpSequence
{
    /** Days after our message until the next follow-up, by how many we already sent; beyond the list: quarterly. */
    private const array DAYS = [2, 7, 21];

    public const int QUARTER_DAYS = 90;

    /** Cold leads (no answer for this long) wait for the next re-warm round instead of filling Bugün. */
    public const int COLD_AFTER_DAYS = 45;

    /** The next re-warm round of the silent leads (first week of December, yakup's plan). */
    public const string REWARM_ON = '2026-12-01';

    /** @return array{0: string, 1: CarbonImmutable} next step text and date after our message number $sent */
    public static function afterOurMessage(int $sent, ?CarbonImmutable $from = null): array
    {
        $from ??= CarbonImmutable::now('Europe/Istanbul');
        $index = max(0, $sent - 1);
        if ($index < count(self::DAYS)) {
            $days = self::DAYS[$index];
            $text = match ($index) {
                0 => 'Tek satır hatırlat',
                1 => 'Yeni bir bulgu ile yaz',
                default => 'Kapanış sorusu sor',
            };
        } else {
            $days = self::QUARTER_DAYS;
            $text = 'Yeni bir şeyle yaz (vaka, kampanya)';
        }

        return [$text, self::weekdayMorning($from->addDays($days))];
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
