<?php

namespace App\Livewire\Operator\Concerns;

use App\Services\Site\Analysis\SiteRange;
use Livewire\Attributes\Url;

/**
 * The website screen's date picker on another asset screen (Google Ads, Meta, İşletme Profili; yakup, 2026-10-07): the
 * page keeps its own `days` (preset) and `compare` (prev | year) properties, this adds the custom start–end and the
 * picker's "Uygula". The readers take the SiteRange instead of a day count.
 */
trait HasDateRange
{
    #[Url(as: 'bas')]
    public string $start = '';

    #[Url(as: 'bit')]
    public string $end = '';

    /** "Uygula" in the date picker: a preset (days) or a custom start–end, and the comparison. */
    public function setRange(int $days, string $start = '', string $end = '', string $compare = SiteRange::COMPARE_PREVIOUS): void
    {
        $range = SiteRange::from($days, $start !== '' ? $start : null, $end !== '' ? $end : null, $compare);
        $this->days = $range->days;
        $this->start = (string) $range->start;
        $this->end = (string) $range->end;
        $this->compare = $range->compare;
    }

    /** Old "N gün" buttons and links: a preset, the comparison kept. */
    public function setDays(int $days): void
    {
        $this->setRange($days, '', '', $this->compare);
    }

    public function dateRange(): SiteRange
    {
        return SiteRange::from($this->days, $this->start !== '' ? $this->start : null, $this->end !== '' ? $this->end : null, $this->compare);
    }

    /** URL values that are not a valid range fall back like the picker does. */
    protected function normalizeDateRange(): void
    {
        $range = $this->dateRange();
        $this->days = $range->days;
        $this->start = (string) $range->start;
        $this->end = (string) $range->end;
        $this->compare = $range->compare;
    }
}
