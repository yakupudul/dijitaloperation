<?php

namespace Tests\Unit\ReportDelivery;

use App\Enums\ReportDeliveryMode;
use App\Enums\ReportDeliveryScheduleCadence;
use App\Enums\ReportDeliveryScheduleStatus;
use App\Enums\ReportDeliveryStatus;
use App\Enums\ReportPeriodStrategy;
use App\Models\ReportArtifact;
use App\Models\ReportDelivery;
use App\Models\ReportDeliverySchedule;
use App\Models\ReportShareGrant;
use App\Models\ReportShareSession;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ReportDeliveryModelsTest extends TestCase
{
    public function test_report_artifact_update_throws_when_persisted(): void
    {
        $artifact = new ReportArtifact;
        $artifact->exists = true;

        $this->expectException(ValidationException::class);

        try {
            $artifact->update(['mime_type' => 'application/pdf']);
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['REPORT_ARTIFACT_IMMUTABLE'],
                $exception->errors()['report_artifact'] ?? null
            );

            throw $exception;
        }
    }

    public function test_share_grant_active_and_permission_helpers(): void
    {
        $active = new ReportShareGrant([
            'permissions' => [
                'html_view' => true,
                'pdf_download' => false,
            ],
            'expires_at' => Carbon::now()->addDay(),
            'revoked_at' => null,
        ]);

        $this->assertTrue($active->isActive());
        $this->assertTrue($active->allowsHtml());
        $this->assertFalse($active->allowsPdf());

        $revoked = new ReportShareGrant([
            'permissions' => ['html_view' => true, 'pdf_download' => true],
            'expires_at' => Carbon::now()->addDay(),
            'revoked_at' => Carbon::now(),
        ]);

        $this->assertFalse($revoked->isActive());

        $expired = new ReportShareGrant([
            'permissions' => ['html_view' => true, 'pdf_download' => true],
            'expires_at' => Carbon::now()->subMinute(),
            'revoked_at' => null,
        ]);

        $this->assertFalse($expired->isActive());
        $this->assertTrue($expired->allowsPdf());
    }

    public function test_share_session_is_active(): void
    {
        $session = new ReportShareSession([
            'expires_at' => Carbon::now()->addHour(),
            'revoked_at' => null,
        ]);

        $this->assertTrue($session->isActive());

        $session->revoked_at = Carbon::now();

        $this->assertFalse($session->isActive());
    }

    public function test_delivery_and_schedule_enum_casts(): void
    {
        $delivery = new ReportDelivery([
            'status' => 'queued',
            'delivery_mode' => 'authenticated_secure_link_with_pdf_access',
        ]);

        $this->assertInstanceOf(ReportDeliveryStatus::class, $delivery->status);
        $this->assertSame(ReportDeliveryStatus::Queued, $delivery->status);
        $this->assertSame(ReportDeliveryMode::AuthenticatedSecureLinkWithPdf, $delivery->delivery_mode);

        $schedule = new ReportDeliverySchedule([
            'status' => 'active',
            'cadence' => 'monthly',
            'period_strategy' => 'previous_calendar_month',
        ]);

        $this->assertSame(ReportDeliveryScheduleStatus::Active, $schedule->status);
        $this->assertSame(ReportDeliveryScheduleCadence::Monthly, $schedule->cadence);
        $this->assertSame(ReportPeriodStrategy::PreviousCalendarMonth, $schedule->period_strategy);
    }

    public function test_timestamp_flags(): void
    {
        $this->assertFalse((new ReportArtifact)->timestamps);
        $this->assertFalse((new ReportShareGrant)->timestamps);
        $this->assertFalse((new ReportShareSession)->timestamps);
        $this->assertFalse((new ReportDelivery)->timestamps);
        $this->assertTrue((new ReportDeliverySchedule)->timestamps);
    }
}
