<?php

namespace Tests\Feature\TrackA;

use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoPeriod;
use App\Support\Operator\OperatorPeriod;
use App\Support\Operator\OperatorReportingPeriod;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OperatorPeriodComparisonTest extends TestCase
{
    #[Test]
    public function production_custom_range_allows_sixteen_months(): void
    {
        $anchor = CarbonImmutable::parse('2026-08-21');
        $start = $anchor->subDays(DemoPeriod::PRODUCTION_HISTORY_DAYS)->toDateString();
        $end = $anchor->toDateString();

        $this->assertNull(DemoPeriod::validateCustom($start, $end, '12', $anchor, 'UTC'));
    }

    #[Test]
    public function demo_catalog_custom_range_stays_within_fixture_window(): void
    {
        $error = DemoPeriod::validateCustom('2026-01-01', '2026-08-12', DemoCatalog::GSC_ASSET_ID);
        $this->assertNotNull($error);
    }

    #[Test]
    public function operator_picker_min_date_covers_sixteen_months(): void
    {
        $min = OperatorPeriod::pickerMinDate();
        $max = OperatorPeriod::pickerMaxDate();
        $days = CarbonImmutable::parse($min)->diffInDays(CarbonImmutable::parse($max));
        $this->assertGreaterThanOrEqual(DemoPeriod::PRODUCTION_HISTORY_DAYS, $days);
    }

    #[Test]
    public function previous_and_yoy_comparison_bounds_are_distinct(): void
    {
        $previous = OperatorReportingPeriod::comparisonQueryBounds('previous', 'custom', '2026-07-01', '2026-07-31');
        $yoy = OperatorReportingPeriod::comparisonQueryBounds('yoy', 'custom', '2026-07-01', '2026-07-31');

        $this->assertSame('2026-05-31', $previous['start']->toDateString());
        $this->assertSame('2026-06-30', $previous['end']->toDateString());
        $this->assertSame('2025-07-01', $yoy['start']->toDateString());
        $this->assertSame('2025-07-31', $yoy['end']->toDateString());
    }
}
