<?php

namespace Tests\Unit;

use App\Services\Collection\Providers\MetaAds\MetaAdsDateSlicer;
use App\Support\Time\SafeTimezone;
use PHPUnit\Framework\TestCase;

/** Legacy provider time zone names ("Turkey") are rejected by newer tzdata; they must map, not crash. */
final class SafeTimezoneTest extends TestCase
{
    public function test_legacy_names_map_and_unknown_falls_back(): void
    {
        $this->assertSame('Europe/Istanbul', SafeTimezone::normalize('Turkey'));
        $this->assertSame('Europe/Istanbul', SafeTimezone::normalize('Europe/Istanbul'));
        $this->assertSame('UTC', SafeTimezone::normalize(null));
        $this->assertSame('UTC', SafeTimezone::normalize('Mars/Olympus'));
        $this->assertSame('America/New_York', SafeTimezone::normalize('US/Eastern'));
    }

    public function test_meta_date_slicer_accepts_turkey(): void
    {
        $slices = (new MetaAdsDateSlicer)->slices('2026-09-01', '2026-09-10', 7, 'Turkey');

        $this->assertSame([['start' => '2026-09-01', 'end' => '2026-09-07'], ['start' => '2026-09-08', 'end' => '2026-09-10']], $slices);
    }
}
