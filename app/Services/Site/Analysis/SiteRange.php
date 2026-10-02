<?php

namespace App\Services\Site\Analysis;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * The website screen's date range (one picker for every tab, Google Ads / Meta style): a preset of N days ending on the
 * last data day, or a custom start–end; compared with the previous period of the same length or the same dates a year
 * earlier. The screen binds it for the request (`bind()`); the analysis readers read it in `window()`.
 */
final class SiteRange
{
    public const array PRESETS = [7 => 'Son 7 gün', 14 => 'Son 14 gün', 28 => 'Son 28 gün', 90 => 'Son 3 ay', 180 => 'Son 6 ay', 365 => 'Son 12 ay'];

    public const string COMPARE_PREVIOUS = 'prev';

    public const string COMPARE_YEAR = 'year';

    public const int MAX_DAYS = 730;

    public function __construct(
        public readonly int $days = 28,
        public readonly ?string $start = null,
        public readonly ?string $end = null,
        public readonly string $compare = self::COMPARE_PREVIOUS,
    ) {}

    /** From the screen's URL parameters; anything invalid falls back to the last 28 days. */
    public static function from(int|string $days, ?string $start = null, ?string $end = null, ?string $compare = null): self
    {
        $compare = $compare === self::COMPARE_YEAR ? self::COMPARE_YEAR : self::COMPARE_PREVIOUS;
        $from = self::date($start);
        $to = self::date($end);
        if ($from !== null && $to !== null && $from->lte($to) && $from->diffInDays($to) < self::MAX_DAYS) {
            return new self((int) $from->diffInDays($to) + 1, $from->toDateString(), $to->toDateString(), $compare);
        }
        $days = (int) $days;

        return new self(array_key_exists($days, self::PRESETS) ? $days : 28, null, null, $compare);
    }

    public function custom(): bool
    {
        return $this->start !== null && $this->end !== null;
    }

    /** Binds the range for this request (the readers use it). */
    public function bind(): self
    {
        app()->instance(self::class, $this);

        return $this;
    }

    public static function current(): ?self
    {
        return app()->bound(self::class) ? app(self::class) : null;
    }

    /** "Son 28 gün" or "Özel aralık". */
    public function label(): string
    {
        return $this->custom() ? 'Özel aralık' : (self::PRESETS[$this->days] ?? $this->days.' gün');
    }

    /**
     * The window: an explicit range, or the last N days up to the last data day; the comparison window before it.
     *
     * @return array{start: string, end: string, prev_start: string, prev_end: string}
     */
    public function window(CarbonImmutable $lastDataDay): array
    {
        $end = $this->custom() ? CarbonImmutable::parse((string) $this->end) : $lastDataDay;
        $start = $this->custom() ? CarbonImmutable::parse((string) $this->start) : $end->subDays($this->days - 1);
        [$prevStart, $prevEnd] = $this->compare === self::COMPARE_YEAR
            ? [$start->subYear(), $end->subYear()]
            : [$start->subDays($this->days), $start->subDay()];

        return ['start' => $start->toDateString(), 'end' => $end->toDateString(), 'prev_start' => $prevStart->toDateString(), 'prev_end' => $prevEnd->toDateString()];
    }

    /** Short Turkish range: "1 Eyl – 28 Eyl 2026". */
    public static function format(string $start, string $end): string
    {
        $months = ['Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'];
        $a = CarbonImmutable::parse($start);
        $b = CarbonImmutable::parse($end);

        return $a->day.' '.$months[$a->month - 1].($a->year !== $b->year ? ' '.$a->year : '').' – '.$b->day.' '.$months[$b->month - 1].' '.$b->year;
    }

    private static function date(?string $value): ?CarbonImmutable
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
