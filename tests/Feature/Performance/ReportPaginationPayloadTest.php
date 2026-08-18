<?php

namespace Tests\Feature\Performance;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\ReportSnapshot;
use App\Models\User;
use App\Services\ReportSnapshots\ReportSnapshotReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('performance')]
class ReportPaginationPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_list_does_not_select_snapshot_body_columns(): void
    {
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $generatedBy = User::factory()->create();

        ReportSnapshot::query()->create([
            'customer_id' => $customer->id,
            'brand_id' => $brand->id,
            'report_type' => 'client_value_story',
            'period_start' => now()->subDays(30)->toDateString(),
            'period_end' => now()->toDateString(),
            'title_snapshot' => 'Bench Snapshot',
            'customer_name_snapshot' => $customer->name,
            'brand_name_snapshot' => $brand->name,
            'locale' => 'en',
            'reporting_timezone' => 'UTC',
            'snapshot_schema_version' => 'client_value_story_v1',
            'source_manifest_fingerprint' => hash('sha256', 'manifest'),
            'content_checksum' => hash('sha256', 'content'),
            'content_payload' => ['huge' => str_repeat('x', 10_000)],
            'source_manifest_payload' => ['sources' => ['a', 'b', 'c']],
            'generated_by' => $generatedBy->id,
            'generated_at' => now(),
            'created_at' => now(),
            'idempotency_key' => 'bench-report-1',
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $page = app(ReportSnapshotReadService::class)->listForCustomer(
            $customer,
            ['per_page' => 20],
            [(int) $customer->id],
            [(int) $brand->id],
        );

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(1, $page->total());
        $sql = strtolower(implode("\n", array_column($log, 'query')));
        $this->assertStringNotContainsString('content_payload', $sql);
        $this->assertStringNotContainsString('source_manifest_payload', $sql);
        $this->assertNull($page->first()->content_payload ?? null);
    }

    public function test_report_list_clamps_per_page(): void
    {
        $customer = Customer::factory()->create();
        $page = app(ReportSnapshotReadService::class)->listForCustomer(
            $customer,
            ['per_page' => 10_000],
            [(int) $customer->id],
            [],
        );
        $this->assertSame(100, $page->perPage());
    }
}
