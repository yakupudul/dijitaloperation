<?php

namespace App\Services\Sales;

use App\Models\SalesLead;
use App\Models\SalesLesson;
use App\Support\SchemaState;
use Carbon\CarbonImmutable;

/**
 * Haftalık 10 dakika (Satış Düzeni 4.2): the week's sales on one card, read on Friday. Who moved forward, who is
 * stuck, and at most three decisions that wait (the Satış beyni's questions and proposals, oldest first), so the
 * questions asked per closed card no longer pile up unseen. Read from the records; nothing is written.
 */
final class WeekReview
{
    private const string TZ = 'Europe/Istanbul';

    /** An answer owed this long, or an offer silent this long, is "stuck". */
    public const int OWED_DAYS = 3;

    public const int OFFER_SILENT_DAYS = 7;

    /**
     * @return array{from: CarbonImmutable, moved: list<array{id: int, title: string, line: string}>, stuck: list<array{id: int, title: string, line: string}>, decisions: list<array{id: int, kind: string, text: string}>, waiting: int, line: string}
     */
    public function week(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now(self::TZ);
        $from = $now->startOfWeek(CarbonImmutable::MONDAY);
        $row = fn (SalesLead $lead, string $line): array => ['id' => (int) $lead->id, 'title' => (string) $lead->title(), 'line' => $line];

        $moved = SalesLead::query()->relevant()->where('stage_at', '>=', $from->utc())->whereIn('stage', ['meeting', 'priced', 'thinking', 'won'])
            ->orderByRaw("case stage when 'won' then 0 when 'thinking' then 1 when 'priced' then 2 else 3 end")->orderByDesc('stage_at')->orderBy('id')->limit(12)->get()
            ->map(fn (SalesLead $lead): array => $row($lead, SalesLead::STAGES[$lead->stage].((int) $lead->offer_amount > 0 ? ' · '.number_format((int) $lead->offer_amount, 0, ',', '.').' ₺' : '')))->all();

        $open = SalesLead::query()->open()->relevant()->where('lifecycle', '!=', 'customer')->orderBy('id')->get();
        $stuck = $open->map(function (SalesLead $lead) use ($now, $row): ?array {
            if ($lead->awaitsUs() && $lead->last_incoming_at->lte($now->subDays(self::OWED_DAYS))) {
                return $row($lead, (int) $lead->last_incoming_at->diffInDays($now).' gündür cevap bekliyor');
            }
            if ($lead->hasRealOffer() && ! $lead->awaitsUs() && $lead->last_contact_at !== null && $lead->last_contact_at->lte($now->subDays(self::OFFER_SILENT_DAYS))) {
                return $row($lead, 'Teklif '.(int) $lead->last_contact_at->diffInDays($now).' gündür sessiz');
            }

            return null;
        })->filter()->sortBy(fn (array $r): int => str_starts_with($r['line'], 'Teklif') ? 1 : 0, SORT_REGULAR)->take(12)->values()->all();

        $lessons = SchemaState::hasTable('sales_lessons') ? SalesLesson::query()->proposed()->orderByRaw("case kind when 'soru' then 0 else 1 end")->orderBy('id') : null;
        $waiting = $lessons !== null ? (clone $lessons)->count() : 0;
        $decisions = $lessons !== null ? $lessons->limit(3)->get()->map(fn (SalesLesson $l): array => ['id' => (int) $l->id, 'kind' => $l->kindLabel(), 'text' => (string) $l->text])->all() : [];

        $parts = array_filter([count($moved) > 0 ? count($moved).' kişi ilerledi' : null, count($stuck) > 0 ? count($stuck).' kişi takıldı' : null, $waiting > 0 ? min(3, $waiting).' karar bekliyor' : null]);

        return ['from' => $from, 'moved' => $moved, 'stuck' => $stuck, 'decisions' => $decisions, 'waiting' => $waiting,
            'line' => $parts !== [] ? 'Satış haftası: '.implode(', ', $parts).'.' : ''];
    }
}
