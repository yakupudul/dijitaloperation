<?php

namespace Tests\Unit\Collection;

use App\Services\Collection\Providers\MetaAds\MetaAdsDateSlicer;
use App\Services\Collection\Providers\MetaAds\MetaAdsRequestFamilyCatalog;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MetaAdsDateSlicerTest extends TestCase
{
    #[Test]
    public function slices_are_inclusive_contiguous_and_cover_month_boundaries(): void
    {
        $slicer = new MetaAdsDateSlicer;
        $slices = $slicer->slices('2024-01-30', '2024-02-02', 2, 'Europe/Berlin');

        $this->assertSame([
            ['start' => '2024-01-30', 'end' => '2024-01-31'],
            ['start' => '2024-02-01', 'end' => '2024-02-02'],
        ], $slices);
    }

    #[Test]
    public function leap_day_is_included(): void
    {
        $slicer = new MetaAdsDateSlicer;
        $slices = $slicer->slices('2024-02-28', '2024-03-01', 1, 'UTC');

        $this->assertSame([
            ['start' => '2024-02-28', 'end' => '2024-02-28'],
            ['start' => '2024-02-29', 'end' => '2024-02-29'],
            ['start' => '2024-03-01', 'end' => '2024-03-01'],
        ], $slices);
    }

    #[Test]
    public function contract_180d_window_is_covered_without_gaps_or_overlap(): void
    {
        $slicer = new MetaAdsDateSlicer;
        $start = '2026-02-14';
        $end = '2026-08-12';
        $sliceDays = $slicer->sliceDaysForFamily(MetaAdsRequestFamilyCatalog::FAMILY_INSIGHTS_DAILY);
        $this->assertSame(7, $sliceDays);

        $slices = $slicer->slices($start, $end, $sliceDays, 'UTC');
        $this->assertNotEmpty($slices);
        $this->assertSame($start, $slices[0]['start']);
        $this->assertSame($end, $slices[array_key_last($slices)]['end']);
        $this->assertSame(180, $slicer->inclusiveDayCount($start, $end, 'UTC'));

        $cursor = $start;
        foreach ($slices as $index => $slice) {
            $this->assertSame($cursor, $slice['start'], "slice {$index} must continue the previous exclusive next day");
            $this->assertTrue(
                $slice['end'] <= $end,
                "slice {$index} end {$slice['end']} must not pass window end {$end}",
            );
            $width = $slicer->inclusiveDayCount($slice['start'], $slice['end'], 'UTC');
            $this->assertGreaterThanOrEqual(1, $width);
            $this->assertLessThanOrEqual($sliceDays, $width);
            $cursor = CarbonImmutable::parse($slice['end'], 'UTC')->addDay()->toDateString();
        }
        $this->assertSame(
            CarbonImmutable::parse($end, 'UTC')->addDay()->toDateString(),
            $cursor,
        );
    }
}
