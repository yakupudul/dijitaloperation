<?php

namespace Tests\Feature\TrackA;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OperatorChartVisibilityContractTest extends TestCase
{
    #[Test]
    public function operator_charts_wait_for_visible_width_and_recover_after_tab_switches(): void
    {
        $source = file_get_contents(resource_path('js/operator.js'));

        $this->assertIsString($source);
        $this->assertStringContainsString('function chartHostWidth(el)', $source);
        $this->assertStringContainsString('if (chartHostWidth(el) <= 0)', $source);
        $this->assertStringContainsString('new ResizeObserver', $source);
        $this->assertStringContainsString('schedulePostMorphSynchronization();', $source);
        $this->assertStringContainsString("window.addEventListener('resize'", $source);
        $this->assertStringContainsString('function synchronizeChartWidth(el', $source);

        // The lifecycle must apply to every [data-chart] host, not one aria-label.
        $this->assertStringNotContainsString('isDeviceDistributionChart', $source);
        $this->assertStringNotContainsString('[aria-label="Visitor device distribution"]', $source);
    }
}
