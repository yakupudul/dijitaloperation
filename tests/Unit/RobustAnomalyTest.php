<?php

namespace Tests\Unit;

use App\Services\Advisor\Anomaly\RobustAnomaly;
use PHPUnit\Framework\TestCase;

final class RobustAnomalyTest extends TestCase
{
    public function test_median_mad_z_and_ewma(): void
    {
        $this->assertSame(3.0, RobustAnomaly::median([5, 1, 3]));
        $this->assertSame(2.5, RobustAnomaly::median([1, 2, 3, 4]));
        $this->assertSame(1.0, RobustAnomaly::mad([1, 2, 3, 4, 100]));
        $this->assertGreaterThan(10, RobustAnomaly::zScore(100, [10, 11, 9, 10, 12, 10]));
        $this->assertEqualsWithDelta(0.0, RobustAnomaly::zScore(10, [10, 10, 10]), 0.001, 'flat series uses a small floor, no division by zero');
        $this->assertEqualsWithDelta(13.0, RobustAnomaly::ewma([10, 20], 0.3), 0.001);
    }
}
