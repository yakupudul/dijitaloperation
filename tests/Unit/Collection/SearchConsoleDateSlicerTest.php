<?php

namespace Tests\Unit\Collection;

use App\Services\Collection\Providers\SearchConsole\SearchConsoleDateSlicer;
use App\Services\Collection\Providers\SearchConsole\SearchConsoleRequestFamilyCatalog;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SearchConsoleDateSlicerTest extends TestCase
{
    #[Test]
    public function inclusive_slices_have_no_gaps_or_overlaps(): void
    {
        $slicer = new SearchConsoleDateSlicer;
        $slices = $slicer->slices('2024-01-01', '2024-01-10', 3);

        $this->assertSame([
            ['start' => '2024-01-01', 'end' => '2024-01-03'],
            ['start' => '2024-01-04', 'end' => '2024-01-06'],
            ['start' => '2024-01-07', 'end' => '2024-01-09'],
            ['start' => '2024-01-10', 'end' => '2024-01-10'],
        ], $slices);
    }

    #[Test]
    public function leap_year_and_month_boundaries(): void
    {
        $slicer = new SearchConsoleDateSlicer;
        $slices = $slicer->slices('2024-02-28', '2024-03-02', 1);

        $this->assertSame([
            ['start' => '2024-02-28', 'end' => '2024-02-28'],
            ['start' => '2024-02-29', 'end' => '2024-02-29'],
            ['start' => '2024-03-01', 'end' => '2024-03-01'],
            ['start' => '2024-03-02', 'end' => '2024-03-02'],
        ], $slices);
    }

    #[Test]
    public function query_page_family_uses_narrow_daily_slices(): void
    {
        $slicer = new SearchConsoleDateSlicer;
        $this->assertSame(1, $slicer->sliceDaysForFamily(SearchConsoleRequestFamilyCatalog::FAMILY_QUERY_PAGE_DAILY));
        $this->assertSame(28, $slicer->sliceDaysForFamily(SearchConsoleRequestFamilyCatalog::FAMILY_PROPERTY_DAILY));
    }
}
