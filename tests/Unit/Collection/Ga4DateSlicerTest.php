<?php

namespace Tests\Unit\Collection;

use App\Services\Collection\Providers\Ga4\Ga4DateSlicer;
use App\Services\Collection\Providers\Ga4\Ga4RequestFamilyCatalog;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class Ga4DateSlicerTest extends TestCase
{
    #[Test]
    public function inclusive_slices_in_property_timezone_have_no_gaps(): void
    {
        $slicer = new Ga4DateSlicer;
        $slices = $slicer->slices('2024-01-01', '2024-01-10', 3, 'Europe/Istanbul');

        $this->assertSame([
            ['start' => '2024-01-01', 'end' => '2024-01-03'],
            ['start' => '2024-01-04', 'end' => '2024-01-06'],
            ['start' => '2024-01-07', 'end' => '2024-01-09'],
            ['start' => '2024-01-10', 'end' => '2024-01-10'],
        ], $slices);
    }

    #[Test]
    public function leap_year_boundary(): void
    {
        $slicer = new Ga4DateSlicer;
        $slices = $slicer->slices('2024-02-28', '2024-03-01', 1, 'America/Los_Angeles');
        $this->assertSame([
            ['start' => '2024-02-28', 'end' => '2024-02-28'],
            ['start' => '2024-02-29', 'end' => '2024-02-29'],
            ['start' => '2024-03-01', 'end' => '2024-03-01'],
        ], $slices);
    }

    #[Test]
    public function high_cardinality_families_use_daily_slices(): void
    {
        $slicer = new Ga4DateSlicer;
        $this->assertSame(1, $slicer->sliceDaysForFamily(Ga4RequestFamilyCatalog::FAMILY_LANDING_PAGE_DAILY));
        $this->assertSame(1, $slicer->sliceDaysForFamily(Ga4RequestFamilyCatalog::FAMILY_SOURCE_MEDIUM_DAILY));
        $this->assertSame(28, $slicer->sliceDaysForFamily(Ga4RequestFamilyCatalog::FAMILY_PROPERTY_DAILY));
    }
}
