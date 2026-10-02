<?php

namespace App\Services\DataPool\Integrity\Support;

use Carbon\CarbonImmutable;

/**
 * Interval-set coverage — never use min/max alone to prove continuity.
 */
final class CoverageIntervalSet
{
    /**
     * @param  list<array{start: string, end: string}>  $intervals
     */
    public function __construct(
        public readonly array $intervals,
    ) {}

    /**
     * @param  list<string>  $dates  Y-m-d dates that were successfully collected (including zero-row days)
     */
    public static function fromSuccessfulDates(array $dates): self
    {
        $unique = array_values(array_unique(array_filter($dates)));
        sort($unique);
        if ($unique === []) {
            return new self([]);
        }

        $intervals = [];
        $start = $unique[0];
        $prev = $unique[0];
        for ($i = 1; $i < count($unique); $i++) {
            $cur = $unique[$i];
            $expectedNext = CarbonImmutable::parse($prev)->addDay()->toDateString();
            if ($cur !== $expectedNext) {
                $intervals[] = ['start' => $start, 'end' => $prev];
                $start = $cur;
            }
            $prev = $cur;
        }
        $intervals[] = ['start' => $start, 'end' => $prev];

        return new self($intervals);
    }

    /**
     * @return list<string> missing dates inside target inclusive range not covered by intervals
     */
    public function gapsIn(string $targetStart, string $targetEnd): array
    {
        $gaps = [];
        $cursor = CarbonImmutable::parse($targetStart)->startOfDay();
        $end = CarbonImmutable::parse($targetEnd)->startOfDay();
        while ($cursor->lessThanOrEqualTo($end)) {
            $day = $cursor->toDateString();
            if (! $this->covers($day)) {
                $gaps[] = $day;
            }
            $cursor = $cursor->addDay();
        }

        return $gaps;
    }

    public function covers(string $date): bool
    {
        foreach ($this->intervals as $interval) {
            if ($date >= $interval['start'] && $date <= $interval['end']) {
                return true;
            }
        }

        return false;
    }

    public function isEmpty(): bool
    {
        return $this->intervals === [];
    }

    /**
     * Min/max appearance of coverage (must NOT be used alone for integrity PASS).
     *
     * @return array{start: ?string, end: ?string}
     */
    public function bounds(): array
    {
        if ($this->intervals === []) {
            return ['start' => null, 'end' => null];
        }

        $starts = array_column($this->intervals, 'start');
        $ends = array_column($this->intervals, 'end');

        return ['start' => min($starts), 'end' => max($ends)];
    }

    /**
     * Latest reporting date through which coverage is contiguous from the earliest
     * interval (or from $requiredStart when provided). Never jumps an unresolved gap.
     * Distinct from bounds()['end'] / MAX(stored fact date).
     */
    public function verifiedContiguousWatermark(?string $requiredStart = null): ?string
    {
        if ($this->intervals === []) {
            return null;
        }

        $intervals = $this->intervals;
        usort($intervals, static fn (array $a, array $b): int => strcmp($a['start'], $b['start']));

        $first = $intervals[0];
        if ($requiredStart !== null && $first['start'] > $requiredStart) {
            return null;
        }

        $watermark = $first['end'];
        $expectedNext = CarbonImmutable::parse($watermark)->addDay()->toDateString();

        for ($i = 1; $i < count($intervals); $i++) {
            $interval = $intervals[$i];
            if ($interval['start'] > $expectedNext) {
                break;
            }

            if ($interval['end'] > $watermark) {
                $watermark = $interval['end'];
                $expectedNext = CarbonImmutable::parse($watermark)->addDay()->toDateString();
            }
        }

        return $watermark;
    }

    /**
     * @return list<array{start: string, end: string}>
     */
    public function internalGaps(): array
    {
        if (count($this->intervals) < 2) {
            return [];
        }

        $intervals = $this->intervals;
        usort($intervals, static fn (array $a, array $b): int => strcmp($a['start'], $b['start']));

        $gaps = [];
        for ($i = 0; $i < count($intervals) - 1; $i++) {
            $nextStart = CarbonImmutable::parse($intervals[$i]['end'])->addDay()->toDateString();
            $following = $intervals[$i + 1]['start'];
            if ($following > $nextStart) {
                $gapEnd = CarbonImmutable::parse($following)->subDay()->toDateString();
                $gaps[] = ['start' => $nextStart, 'end' => $gapEnd];
            }
        }

        return $gaps;
    }
}
