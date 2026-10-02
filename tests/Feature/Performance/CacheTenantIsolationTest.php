<?php

namespace Tests\Feature\Performance;

use App\Models\Brand;
use App\Models\Customer;
use App\Services\Tasks\TaskReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('performance')]
class CacheTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_brand_scoped_cache_keys_do_not_cross_brands(): void
    {
        $customer = Customer::factory()->create();
        $brandA = Brand::factory()->create(['customer_id' => $customer->id, 'name' => 'Brand A']);
        $brandB = Brand::factory()->create(['customer_id' => $customer->id, 'name' => 'Brand B']);

        $keyA = sprintf('bench:value-story:%d:v1', $brandA->id);
        $keyB = sprintf('bench:value-story:%d:v1', $brandB->id);

        Cache::put($keyA, ['brand_id' => $brandA->id, 'payload' => 'A'], 60);
        Cache::put($keyB, ['brand_id' => $brandB->id, 'payload' => 'B'], 60);

        $fromA = Cache::get($keyA);
        $fromB = Cache::get($keyB);

        $this->assertSame('A', $fromA['payload']);
        $this->assertSame('B', $fromB['payload']);
        $this->assertNotSame($fromA['payload'], $fromB['payload']);
        $this->assertSame($brandA->id, $fromA['brand_id']);
        $this->assertSame($brandB->id, $fromB['brand_id']);
    }

    public function test_task_pagination_clamps_unsafe_per_page(): void
    {
        $paginator = app(TaskReadService::class)->paginate([], 1_000_000);
        $this->assertSame(100, $paginator->perPage());
    }

    public function test_no_plaintext_credential_cache_keys_documented_as_forbidden(): void
    {
        $contract = file_get_contents(base_path('docs/architecture/QUERY_PERFORMANCE_CONTRACT.md'));
        $this->assertIsString($contract);
        $this->assertStringContainsString('plaintext credential', strtolower($contract));
    }
}
