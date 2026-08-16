<?php

namespace Tests\Feature\Performance;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('performance')]
class QueueCapacityContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_horizon_uses_bounded_workload_classes_not_queue_explosion(): void
    {
        $queues = collect(config('horizon.defaults'))
            ->flatMap(fn (array $supervisor): array => $supervisor['queue'] ?? [])
            ->unique()
            ->values()
            ->all();

        $this->assertContains('default', $queues);
        $this->assertContains('collection', $queues);
        $this->assertLessThanOrEqual(8, count($queues), 'Prompt65 forbids queue-name explosion');

        $this->assertSame(300, (int) config('horizon.defaults.supervisor-1.timeout'));
        $this->assertSame(300, (int) config('horizon.defaults.supervisor-collection.timeout'));
    }

    public function test_queue_capacity_contract_documents_starvation_rules(): void
    {
        $path = base_path('docs/architecture/QUEUE_CAPACITY_CONTRACT.md');
        $this->assertFileExists($path);
        $body = File::get($path);
        $this->assertStringContainsString('Backfill', $body);
        $this->assertStringContainsString('Incremental', $body);
        $this->assertStringContainsString('automatic ai', strtolower($body));
        $this->assertStringContainsString('manual', strtolower($body));
        $this->assertStringContainsString('provider', strtolower($body));
        $this->assertStringContainsString('connection', strtolower($body));
    }
}
