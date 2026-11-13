<?php

namespace App\Livewire\Operator\Concerns;

use App\Services\Site\Analysis\SiteRange;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The panel's date picker (x-operator.date-range-picker) on a plain date filter: two Y-m-d properties (start, end) and
 * no comparison (AI işleri, WordPress geçmişi; yakup 2026-11-13 "tarih filtresi olan her yere bu modeli kur"). "Uygula"
 * writes the chosen window into the two properties; "Tüm zamanlar" (days 0, no dates) clears them where the screen
 * allows no filter.
 */
trait FiltersByDateRange
{
    public const string DATE_FILTER_TZ = 'Europe/Istanbul';

    /** @return array{0: string, 1: string} the properties holding the start and the end */
    protected function dateFilterFields(): array
    {
        return ['from', 'to'];
    }

    /** After the window changed (reset the paging and the like). */
    protected function dateFilterChanged(): void {}

    public static function dateFilterToday(): string
    {
        return CarbonImmutable::now(self::DATE_FILTER_TZ)->toDateString();
    }

    /** "Uygula" in the date picker: a preset (days ending today), a custom start–end, or 0 without dates = no filter. */
    public function setRange(int $days, string $start = '', string $end = '', string $compare = ''): void
    {
        [$from, $to] = $this->dateFilterFields();
        if ($days <= 0 && $start === '' && $end === '') {
            $this->{$from} = '';
            $this->{$to} = '';
        } else {
            $window = SiteRange::from($days, $start !== '' ? $start : null, $end !== '' ? $end : null)->window(CarbonImmutable::parse(self::dateFilterToday()));
            $this->{$from} = $window['start'];
            $this->{$to} = $window['end'];
        }
        $this->dateFilterChanged();
    }

    /** The filter as the picker's range (a window that is exactly "last N days" shows as that preset); null = no filter. */
    public function dateFilterRange(): ?SiteRange
    {
        [$fromField, $toField] = $this->dateFilterFields();
        $from = self::dateFilterDay((string) $this->{$fromField});
        $to = self::dateFilterDay((string) $this->{$toField});
        if ($from === null && $to === null) {
            return null;
        }
        $today = CarbonImmutable::parse(self::dateFilterToday());
        $to ??= $today;
        $from ??= $to->subDays(27);
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        $days = (int) $from->diffInDays($to) + 1;
        if ($to->equalTo($today) && array_key_exists($days, SiteRange::PRESETS)) {
            return SiteRange::from($days);
        }

        return SiteRange::from(28, $from->toDateString(), $to->toDateString());
    }

    private static function dateFilterDay(string $value): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
