<?php

namespace Tests\Feature\Performance;

use App\Services\DataPool\PartitionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('performance')]
class PartitionDecisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_partition_decision_is_defer_with_no_customer_partitions(): void
    {
        $policy = File::get(base_path('docs/architecture/DATA_SCALE_PARTITIONING_POLICY.md'));
        $this->assertStringContainsString('DEFER', $policy);
        $this->assertStringContainsString('Customer-based partitions', $policy);
        $this->assertStringContainsString('REJECT', $policy);

        $audit = File::get(base_path('docs/implementation/PERFORMANCE_SCALE_AUDIT.md'));
        $this->assertStringContainsString('Partition Decision', $audit);
        $this->assertStringContainsString('RANGE_MONTHLY', $audit);
        $this->assertDoesNotMatchRegularExpression('/one partition per [Cc]ustomer/', $audit);

        $this->assertTrue(class_exists(PartitionManager::class));
    }

    public function test_no_customer_partition_migration_exists(): void
    {
        $migrations = File::files(database_path('migrations'));
        foreach ($migrations as $file) {
            $contents = File::get($file->getPathname());
            $this->assertStringNotContainsString('PARTITION BY LIST (customer_id)', $contents);
            $this->assertStringNotContainsString('partition per customer', strtolower($contents));
        }
    }
}
