<?php

namespace Tests\Feature\ProductionReadiness;

use App\Enums\BusinessOutcomeKind;
use App\Enums\ReportDeliveryStatus;
use App\Jobs\Reports\SendReportDeliveryJob;
use App\Mail\ReportDeliveryMail;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\ReportArtifact;
use App\Models\ReportDelivery;
use App\Models\ReportShareGrant;
use App\Models\ReportSnapshot;
use App\Models\User;
use App\Services\BusinessOutcomes\BusinessOutcomeDefinitionService;
use App\Services\BusinessOutcomes\BusinessOutcomeObservationService;
use App\Services\BusinessOutcomes\BusinessOutcomeReadService;
use App\Services\ReportDelivery\CreateReportDeliveryService;
use App\Services\ReportDelivery\GenerateReportPdfService;
use App\Services\ReportDelivery\ReportShareService;
use App\Services\ReportDelivery\SendReportDeliveryService;
use App\Services\ReportSnapshots\CreateReportSnapshotService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Prompt 68 extended delivery path: Value Story → Snapshot → PDF → Share → Delivery.
 * Uses fake mail transport — does not claim real SMTP verification.
 */
class ReportPathE2ETest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_pdf_share_delivery_path_with_fake_mail(): void
    {
        Mail::fake();
        Queue::fake();
        Storage::fake('local');

        $user = User::factory()->create();
        $customer = Customer::factory()->create(['name' => 'RC Report Customer']);
        $brand = Brand::factory()->create([
            'customer_id' => $customer->id,
            'name' => 'RC Report Brand',
        ]);
        $brand->refresh();

        app(BusinessOutcomeDefinitionService::class)->createStandardDefinitionsForBrand($brand, $user);
        $ql = app(BusinessOutcomeReadService::class)->findActiveDefinitionByKind($brand, BusinessOutcomeKind::QualifiedLead);
        $this->assertNotNull($ql);
        app(BusinessOutcomeObservationService::class)->record($brand, $ql, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'value' => 9,
            'completeness' => 'complete',
        ], $user);

        $snapshot = app(CreateReportSnapshotService::class)->create($brand, $user, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'locale' => 'en',
            'idempotency_key' => 'rc-report-snap-1',
        ]);
        $this->assertInstanceOf(ReportSnapshot::class, $snapshot);
        $this->assertSame((int) $brand->id, (int) $snapshot->brand_id);
        $this->assertSame((int) $brand->customer_id, (int) $snapshot->customer_id);

        $artifact = app(GenerateReportPdfService::class)->generate($snapshot, $user, 'rc-pdf-1');
        $this->assertInstanceOf(ReportArtifact::class, $artifact);
        $this->assertSame((int) $snapshot->id, (int) $artifact->report_snapshot_id);
        Storage::disk('local')->assertExists((string) $artifact->storage_path);

        $grantBundle = app(ReportShareService::class)->createGrant(
            $snapshot,
            'recipient@example.test',
            null,
            CarbonImmutable::now()->addDay(),
            $user,
        );
        $this->assertInstanceOf(ReportShareGrant::class, $grantBundle['grant']);
        $this->assertSame((int) $snapshot->id, (int) $grantBundle['grant']->report_snapshot_id);

        $delivery = app(CreateReportDeliveryService::class)->sendFromSnapshot($snapshot, [
            'recipient_email' => 'ops@client.com',
            'idempotency_key' => 'rc-manual-send-1',
        ], $user, [(int) $brand->customer_id], [(int) $brand->id]);
        $this->assertInstanceOf(ReportDelivery::class, $delivery);
        Queue::assertPushed(SendReportDeliveryJob::class, 1);

        app(SendReportDeliveryService::class)->send((int) $delivery->id);
        $delivery->refresh();
        $this->assertSame(ReportDeliveryStatus::Sent, $delivery->status);
        Mail::assertSent(ReportDeliveryMail::class);

        $this->assertSame(1, ReportSnapshot::query()->count());
        $this->assertSame(1, ReportArtifact::query()->count());
    }
}
