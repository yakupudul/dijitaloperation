<?php

namespace Tests\Unit\Collection;

use App\Services\Collection\GoogleAds\GoogleAdsCentralCollectionService;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

class GoogleAdsCentralCollectionResumeTest extends TestCase
{
    #[Test]
    public function checkpoint_is_reused_when_repair_range_is_unchanged(): void
    {
        $service = (new ReflectionClass(GoogleAdsCentralCollectionService::class))->newInstanceWithoutConstructor();
        $method = (new ReflectionClass(GoogleAdsCentralCollectionService::class))->getMethod('checkpointMatchesRepairRange');
        $method->setAccessible(true);

        $range = ['start' => '2024-01-01', 'end' => '2024-12-31'];
        $checkpoint = [
            'slice_index' => 12,
            'last_slice' => ['start' => '2024-06-01', 'end' => '2024-06-30'],
            'date_range' => $range,
        ];

        $this->assertTrue($method->invoke($service, $checkpoint, $range, $range));
    }

    #[Test]
    public function checkpoint_is_reset_when_safe_repair_range_changes(): void
    {
        $service = (new ReflectionClass(GoogleAdsCentralCollectionService::class))->newInstanceWithoutConstructor();
        $method = (new ReflectionClass(GoogleAdsCentralCollectionService::class))->getMethod('checkpointMatchesRepairRange');
        $method->setAccessible(true);

        $original = ['start' => '2024-01-01', 'end' => '2024-12-31'];
        $repaired = ['start' => '2024-12-01', 'end' => '2024-12-31'];
        $checkpoint = [
            'slice_index' => 12,
            'date_range' => $original,
        ];

        $this->assertFalse($method->invoke($service, $checkpoint, $original, $repaired));
    }

    #[Test]
    public function non_dated_checkpoint_can_resume_without_a_date_range(): void
    {
        $service = (new ReflectionClass(GoogleAdsCentralCollectionService::class))->newInstanceWithoutConstructor();
        $method = (new ReflectionClass(GoogleAdsCentralCollectionService::class))->getMethod('checkpointMatchesRepairRange');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($service, ['step_index' => 4], null, null));
    }
}
