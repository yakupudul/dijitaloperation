<?php

namespace App\Services\Collection\Providers\Ga4;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Inclusive, gap-free date slices in the GA4 Property reporting timezone.
 */
final class Ga4DateSlicer
{
    /**
     * @return list<array{start: string, end: string}>
     */
    public function slices(string $startDate, string $endDate, int $sliceDays, string $timezone): array
    {
        if ($sliceDays < 1) {
            throw new InvalidArgumentException('Date slice width must be >= 1 day.');
        }

        $start = CarbonImmutable::createFromFormat('Y-m-d', $startDate, $timezone);
        $end = CarbonImmutable::createFromFormat('Y-m-d', $endDate, $timezone);
        if ($start === false || $end === false) {
            throw new InvalidArgumentException('Date range must use Y-m-d inclusive boundaries.');
        }

        $start = $start->startOfDay();
        $end = $end->startOfDay();
        if ($start->greaterThan($end)) {
            throw new InvalidArgumentException('Date range start must be <= end.');
        }

        $slices = [];
        $cursor = $start;
        while ($cursor->lessThanOrEqualTo($end)) {
            $sliceEnd = $cursor->addDays($sliceDays - 1);
            if ($sliceEnd->greaterThan($end)) {
                $sliceEnd = $end;
            }
            $slices[] = [
                'start' => $cursor->toDateString(),
                'end' => $sliceEnd->toDateString(),
            ];
            $cursor = $sliceEnd->addDay();
        }

        return $slices;
    }

    public function sliceDaysForFamily(string $familyId): int
    {
        /** @var array<string, int> $map */
        $map = config('moxdop-ga4-collector.date_slice_days', []);

        return (int) ($map[$familyId] ?? config('moxdop-ga4-collector.default_date_slice_days', 7));
    }
}
