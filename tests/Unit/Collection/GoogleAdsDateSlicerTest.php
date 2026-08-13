<?php

namespace Tests\Unit\Collection;

use App\Services\Collection\Providers\GoogleAds\GoogleAdsDateSlicer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GoogleAdsDateSlicerTest extends TestCase
{
    #[Test]
    public function slices_are_inclusive_contiguous_and_cover_month_boundaries(): void
    {
        $slicer = new GoogleAdsDateSlicer;
        $slices = $slicer->slices('2024-01-30', '2024-02-02', 2, 'Europe/Berlin');

        $this->assertSame([
            ['start' => '2024-01-30', 'end' => '2024-01-31'],
            ['start' => '2024-02-01', 'end' => '2024-02-02'],
        ], $slices);
    }

    #[Test]
    public function leap_day_is_included(): void
    {
        $slicer = new GoogleAdsDateSlicer;
        $slices = $slicer->slices('2024-02-28', '2024-03-01', 1, 'UTC');

        $this->assertSame([
            ['start' => '2024-02-28', 'end' => '2024-02-28'],
            ['start' => '2024-02-29', 'end' => '2024-02-29'],
            ['start' => '2024-03-01', 'end' => '2024-03-01'],
        ], $slices);
    }
}
