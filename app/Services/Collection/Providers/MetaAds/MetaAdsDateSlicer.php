<?php

namespace App\Services\Collection\Providers\MetaAds;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Inclusive, gap-free date slices in the Meta Ad Account reporting timezone.
 */
final class MetaAdsDateSlicer
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
        $map = config('moxdop-meta-ads-collector.date_slice_days', []);

        return (int) ($map[$familyId] ?? config('moxdop-meta-ads-collector.default_date_slice_days', 7));
    }

    public function inclusiveDayCount(string $startDate, string $endDate, string $timezone): int
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d', $startDate, $timezone)?->startOfDay();
        $end = CarbonImmutable::createFromFormat('Y-m-d', $endDate, $timezone)?->startOfDay();
        if ($start === null || $end === null) {
            return 0;
        }

        return (int) $start->diffInDays($end) + 1;
    }
}
