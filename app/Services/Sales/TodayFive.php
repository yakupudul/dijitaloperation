<?php

namespace App\Services\Sales;

use App\Models\SalesLead;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * "Bugün 5 kişi" (Satış Düzeni 2.4): the short list of the day, ordered by how close each person is to money, not by
 * date. It takes the place of the "bugün ve gecikmiş" list that grew without end. The five are chosen once a day
 * (Istanbul); a card leaves the deck when it is answered, put off or closed, and the deck is not refilled, so the day
 * has an end. Only someone who writes today joins it, because an answer is owed the same day.
 */
final class TodayFive
{
    public const int SIZE = 5;

    /** Why a card is in the deck, in the order the deck is worked. */
    public const array REASONS = [
        0 => 'Güçlü aday, cevap bekliyor',
        1 => 'Görüşme aşamasında',
        2 => 'Teklif masada',
        3 => 'Cevap bekliyor',
        4 => 'Adımı bugün',
    ];

    /** @return Collection<int, array{lead: SalesLead, rank: int, reason: string}> */
    public function deck(): Collection
    {
        $candidates = $this->ranked();
        $key = 'sales.today-five.'.CarbonImmutable::now('Europe/Istanbul')->toDateString();
        $picked = Cache::get($key);
        if (! is_array($picked)) {
            $picked = $candidates->take(self::SIZE)->map(fn (array $row): int => $row['lead']->id)->values()->all();
            Cache::put($key, $picked, CarbonImmutable::now('Europe/Istanbul')->endOfDay());
        }
        $today = CarbonImmutable::now('Europe/Istanbul')->startOfDay();

        return $candidates->filter(fn (array $row): bool => in_array($row['lead']->id, $picked, true)
            || ($row['lead']->awaitsUs() && $row['lead']->last_incoming_at->gte($today)))->values();
    }

    /** Forgets today's choice (tests, and "yeniden seç"). */
    public function reset(): void
    {
        Cache::forget('sales.today-five.'.CarbonImmutable::now('Europe/Istanbul')->toDateString());
    }

    /**
     * Everyone something can be done for today (an answer is owed or a step is due), nearest to money first.
     *
     * @return Collection<int, array{lead: SalesLead, rank: int, reason: string}>
     */
    private function ranked(): Collection
    {
        return SalesLead::query()->open()->relevant()->where('lifecycle', '!=', 'customer')->get()
            // A step the operator put off to a later day ("Sonra") keeps the card out of today.
            ->filter(fn (SalesLead $lead): bool => ($lead->awaitsUs() || $lead->isDue()) && $lead->next_step_at?->gt(SalesLead::endOfToday()) !== true)
            ->map(function (SalesLead $lead): array {
                $rank = match (true) {
                    $lead->awaitsUs() && ((bool) $lead->is_strong || $lead->stage === 'thinking') => 0,
                    $lead->stage === 'meeting' => 1,
                    $lead->hasRealOffer() => 2,
                    $lead->awaitsUs() => 3,
                    default => 4,
                };

                return ['lead' => $lead, 'rank' => $rank, 'reason' => self::REASONS[$rank]];
            })
            ->sortBy([
                fn (array $a, array $b): int => $a['rank'] <=> $b['rank'],
                fn (array $a, array $b): int => (int) $b['lead']->offer_amount <=> (int) $a['lead']->offer_amount,
                fn (array $a, array $b): int => (($a['lead']->last_incoming_at ?? $a['lead']->next_step_at)?->getTimestamp() ?? PHP_INT_MAX)
                    <=> (($b['lead']->last_incoming_at ?? $b['lead']->next_step_at)?->getTimestamp() ?? PHP_INT_MAX),
            ])->values();
    }
}
