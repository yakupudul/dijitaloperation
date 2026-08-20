<?php

namespace Tests\Feature\ReportDelivery;

use App\Enums\BusinessOutcomeKind;
use App\Enums\ReportDeliveryOccurrenceStatus;
use App\Enums\ReportDeliveryStatus;
use App\Enums\ReportShareAccessEventType;
use App\Jobs\Reports\SendReportDeliveryJob;
use App\Mail\ReportDeliveryMail;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\ReportArtifact;
use App\Models\ReportDelivery;
use App\Models\ReportDeliveryOccurrence;
use App\Models\ReportShareAccessEvent;
use App\Models\ReportShareGrant;
use App\Models\ReportShareVerificationChallenge;
use App\Models\ReportSnapshot;
use App\Models\User;
use App\Services\BusinessOutcomes\BusinessOutcomeDefinitionService;
use App\Services\BusinessOutcomes\BusinessOutcomeObservationService;
use App\Services\BusinessOutcomes\BusinessOutcomeReadService;
use App\Services\ClientValueStory\ClientValueStoryReadService;
use App\Services\ReportDelivery\CreateReportDeliveryService;
use App\Services\ReportDelivery\ExecuteReportDeliveryOccurrenceService;
use App\Services\ReportDelivery\GenerateReportPdfService;
use App\Services\ReportDelivery\ReportDeliveryScheduleService;
use App\Services\ReportDelivery\ReportPdfRenderer;
use App\Services\ReportDelivery\ReportShareService;
use App\Services\ReportDelivery\SendReportDeliveryService;
use App\Services\ReportSnapshots\CreateReportSnapshotService;
use App\Support\IntelligenceEvaluation\IntelligenceEvaluationCaseCatalog;
use App\Support\ReportDelivery\ReportPdfRendererVersion;
use App\Support\ReportDelivery\SecretHasher;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ReportPdfSecureShareDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_architecture_boundaries_and_evaluation_keys(): void
    {
        $this->assertTrue(Schema::hasTable('report_artifacts'));
        $this->assertTrue(Schema::hasTable('report_share_grants'));
        $this->assertTrue(Schema::hasTable('report_deliveries'));
        $this->assertTrue(Schema::hasTable('report_delivery_schedules'));
        $this->assertTrue(Schema::hasTable('report_delivery_occurrences'));
        $this->assertFalse(Schema::hasTable('report_artifact_v2'));
        $this->assertFalse(Schema::hasTable('generic_automations'));
        $this->assertFalse(class_exists('App\\Models\\ReportArtifactV2'));
        $this->assertFalse(class_exists('App\\Models\\ReportShareV2'));
        $this->assertFalse(class_exists('App\\Models\\ReportDeliveryV2'));
        $this->assertFalse(class_exists('App\\Models\\GenericAutomation'));

        $keys = IntelligenceEvaluationCaseCatalog::reportDeliveryPreparedCaseKeys();
        $this->assertContains(IntelligenceEvaluationCaseCatalog::PDF_FROM_SNAPSHOT_ONLY, $keys);
        $this->assertContains(IntelligenceEvaluationCaseCatalog::SHARE_TOKEN_NOT_AUTHORIZATION, $keys);
        $this->assertContains(IntelligenceEvaluationCaseCatalog::ONE_SNAPSHOT_MULTIPLE_RECIPIENTS, $keys);
        $this->assertContains(IntelligenceEvaluationCaseCatalog::NO_FAKE_DELIVERED, $keys);
        $this->assertSame(ReportPdfRendererVersion::CLIENT_VALUE_STORY_PDF_V1, ReportPdfRendererVersion::current());
    }

    public function test_pdf_from_snapshot_only_idempotent_and_private(): void
    {
        [$user, $brand] = $this->seedBrandWithOutcomes(ql: 20);
        $snapshot = app(CreateReportSnapshotService::class)->create($brand, $user, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'locale' => 'tr',
            'idempotency_key' => 'pdf-snap-1',
        ]);

        Storage::fake('local');
        $pdfs = app(GenerateReportPdfService::class);
        $a1 = $pdfs->generate($snapshot, $user, 'pdf-key-1');
        $a2 = $pdfs->generate($snapshot, $user, 'pdf-key-2');

        $this->assertSame((int) $a1->id, (int) $a2->id);
        $this->assertSame(1, ReportArtifact::query()->count());
        $this->assertSame(ReportPdfRendererVersion::CLIENT_VALUE_STORY_PDF_V1, $a1->renderer_version);
        $this->assertSame((string) $snapshot->content_checksum, (string) $a1->content_checksum);
        $this->assertNotSame($a1->content_checksum, $a1->file_checksum);
        $this->assertSame('local', $a1->storage_disk);
        Storage::disk('local')->assertExists((string) $a1->storage_path);

        $bytes = $pdfs->streamBytes($a1);
        $this->assertStringStartsWith('%PDF', $bytes);
        $html = app(ReportPdfRenderer::class)->render($snapshot)['html'];
        $this->assertStringContainsString('Diş', $html);
        $this->assertStringContainsString('20', $html);

        // Live domain mutation must not change existing PDF bytes.
        $def = app(BusinessOutcomeReadService::class)->findActiveDefinitionByKind($brand, BusinessOutcomeKind::QualifiedLead);
        app(BusinessOutcomeObservationService::class)->record($brand, $def, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'value' => 99,
            'completeness' => 'complete',
            'correction_reason' => 'Corrected after PDF',
        ], $user, allowCorrection: true);

        $bytesAfter = $pdfs->streamBytes($a1->fresh());
        $this->assertSame(hash('sha256', $bytes), hash('sha256', $bytesAfter));
        $this->assertSame(1, ReportArtifact::query()->count());
    }

    public function test_share_requires_email_verification_not_token_alone(): void
    {
        [$user, $brand] = $this->seedBrandWithOutcomes();
        $snapshot = app(CreateReportSnapshotService::class)->create($brand, $user, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'idempotency_key' => 'share-1',
        ]);

        Mail::fake();
        $shares = app(ReportShareService::class);
        $created = $shares->createGrant(
            $snapshot,
            'client@example.com',
            'Client',
            CarbonImmutable::now()->addDays(2),
            $user,
        );
        $token = $created['locator_token'];
        $grant = $created['grant'];

        $this->assertDatabaseMissing('report_share_grants', ['locator_token_hash' => $token]);
        $this->assertTrue(SecretHasher::equals($token, (string) $grant->locator_token_hash));

        $locator = $this->get(route('reports.share.locator', ['token' => $token]));
        $locator->assertRedirect(route('reports.share.verify.form'));

        $this->get(route('reports.share.view'))->assertStatus(403);

        $code = '424242';
        ReportShareVerificationChallenge::query()->create([
            'share_grant_id' => (int) $grant->id,
            'code_hash' => SecretHasher::hash($code),
            'expires_at' => CarbonImmutable::now()->addMinutes(15),
            'created_at' => CarbonImmutable::now(),
        ]);

        $verified = $shares->verifyCode($grant, $code);
        $this->assertNotEmpty($verified['session_token']);
        $this->assertNull(
            ReportShareVerificationChallenge::query()->where('share_grant_id', $grant->id)->whereNull('consumed_at')->first()
        );

        $this->expectException(ValidationException::class);
        $shares->verifyCode($grant->fresh(), $code);
    }

    public function test_expired_and_revoked_share_denied(): void
    {
        [$user, $brand] = $this->seedBrandWithOutcomes();
        $snapshot = app(CreateReportSnapshotService::class)->create($brand, $user, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'idempotency_key' => 'share-exp',
        ]);
        $shares = app(ReportShareService::class);

        $expired = $shares->createGrant(
            $snapshot,
            'a@example.com',
            null,
            CarbonImmutable::now()->addMinute(),
            $user,
        );
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(2));
        try {
            $shares->resolveGrantByLocator($expired['locator_token']);
            $this->fail('Expired grant must be denied');
        } catch (ValidationException) {
            $this->assertTrue(true);
        } finally {
            CarbonImmutable::setTestNow();
        }

        $active = $shares->createGrant(
            $snapshot,
            'b@example.com',
            null,
            CarbonImmutable::now()->addDay(),
            $user,
        );
        $session = $shares->verifyCode(
            $active['grant'],
            $this->plantOtp($active['grant'], '111111'),
        );
        $shares->revokeGrant($active['grant'], $user);
        try {
            $shares->resolveSession($session['session_token']);
            $this->fail('Revoked session must be denied');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }

    public function test_cross_brand_share_and_internal_download_auth(): void
    {
        [$user, $brandA] = $this->seedBrandWithOutcomes();
        $brandB = Brand::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'name' => 'Other Brand',
        ]);
        $snapshot = app(CreateReportSnapshotService::class)->create($brandA, $user, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'idempotency_key' => 'cross-brand',
        ]);

        $this->expectException(ValidationException::class);
        app(ReportShareService::class)->createGrant(
            snapshot: $snapshot,
            recipientEmail: 'x@example.com',
            recipientName: null,
            expiresAt: CarbonImmutable::now()->addDay(),
            actor: $user,
            authorizedCustomerIds: [(int) $brandB->customer_id],
            authorizedBrandIds: [(int) $brandB->id],
        );
    }

    public function test_manual_delivery_idempotent_and_email_has_no_metrics(): void
    {
        [$user, $brand] = $this->seedBrandWithOutcomes(ql: 44);
        $snapshot = app(CreateReportSnapshotService::class)->create($brand, $user, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'idempotency_key' => 'del-1',
        ]);

        Storage::fake('local');
        Queue::fake();
        Mail::fake();

        $create = app(CreateReportDeliveryService::class);
        $d1 = $create->sendFromSnapshot($snapshot, [
            'recipient_email' => 'ops@client.com',
            'idempotency_key' => 'manual-send-1',
        ], $user, [(int) $brand->customer_id], [(int) $brand->id]);
        $d2 = $create->sendFromSnapshot($snapshot, [
            'recipient_email' => 'ops@client.com',
            'idempotency_key' => 'manual-send-1',
        ], $user, [(int) $brand->customer_id], [(int) $brand->id]);

        $this->assertSame((int) $d1->id, (int) $d2->id);
        $this->assertSame(1, ReportDelivery::query()->count());
        $this->assertSame(1, ReportShareGrant::query()->count());
        Queue::assertPushed(SendReportDeliveryJob::class, 1);

        app(SendReportDeliveryService::class)->send((int) $d1->id);
        $d1->refresh();
        $this->assertSame(ReportDeliveryStatus::Sent, $d1->status);
        $this->assertNull($d1->failure_category);
        Mail::assertSent(ReportDeliveryMail::class, function (ReportDeliveryMail $mail): bool {
            $html = $mail->render();
            $withoutShareUrl = str_replace($mail->shareLocatorUrl, '', $html);

            return ! str_contains($withoutShareUrl, '44')
                && ! str_contains(strtolower($withoutShareUrl), 'qualified')
                && str_contains($html, '/reports/share/');
        });

        // Automatic retry after SENT no-ops.
        app(SendReportDeliveryService::class)->send((int) $d1->id);
        $this->assertSame(1, $d1->attempts()->count());

        $this->assertFalse(enum_exists('App\\Enums\\ReportDeliveryStatus') && in_array('delivered', array_column(ReportDeliveryStatus::cases(), 'value'), true));
        $this->assertSame(
            ['queued', 'preparing', 'sending', 'sent', 'failed', 'cancelled'],
            array_map(static fn ($c) => $c->value, ReportDeliveryStatus::cases()),
        );
    }

    public function test_mail_not_configured_fails_truthfully(): void
    {
        [$user, $brand] = $this->seedBrandWithOutcomes();
        $snapshot = app(CreateReportSnapshotService::class)->create($brand, $user, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'idempotency_key' => 'mail-fail',
        ]);

        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp.host', '');

        $this->expectException(ValidationException::class);
        app(CreateReportDeliveryService::class)->sendFromSnapshot($snapshot, [
            'recipient_email' => 'ops@client.com',
            'idempotency_key' => 'mail-fail-1',
        ], $user);
    }

    public function test_schedule_one_snapshot_many_recipients_and_period_strategy(): void
    {
        [$user, $brand] = $this->seedBrandWithOutcomes();
        Storage::fake('local');
        Queue::fake();
        Mail::fake();

        $schedules = app(ReportDeliveryScheduleService::class);
        $schedule = $schedules->create($brand, [
            'timezone' => 'Europe/Istanbul',
            'day_of_month' => 5,
            'delivery_time' => '09:00',
            'recipients' => [
                ['email' => 'a@client.com'],
                ['email' => 'b@client.com'],
                ['email' => 'c@client.com'],
            ],
        ], $user, [(int) $brand->customer_id], [(int) $brand->id]);

        $now = CarbonImmutable::parse('2026-08-04 12:00:00', 'Europe/Istanbul');
        $preview = $schedules->previewNextOccurrence($schedule, $now);
        $this->assertSame('2026-07-01', $preview['period_start']);
        $this->assertSame('2026-07-31', $preview['period_end']);
        $this->assertStringContainsString('2026-08-05', $preview['scheduled_for']);

        $scheduledFor = CarbonImmutable::parse($preview['scheduled_for'])->setTimezone('UTC');
        $occurrence = ReportDeliveryOccurrence::query()->create([
            'schedule_id' => (int) $schedule->id,
            'scheduled_for' => $scheduledFor,
            'period_start' => $preview['period_start'],
            'period_end' => $preview['period_end'],
            'status' => ReportDeliveryOccurrenceStatus::Pending,
            'occurrence_key' => $schedules->occurrenceKey($schedule, $scheduledFor),
        ]);

        $dup = ReportDeliveryOccurrence::query()->where('occurrence_key', $occurrence->occurrence_key)->count();
        $this->assertSame(1, $dup);

        $executed = app(ExecuteReportDeliveryOccurrenceService::class)->execute((int) $occurrence->id);
        $this->assertSame(ReportDeliveryOccurrenceStatus::Completed, $executed->status);
        $this->assertNotNull($executed->report_snapshot_id);
        $this->assertSame(1, ReportSnapshot::query()->count());
        $this->assertSame(1, ReportArtifact::query()->count());
        $this->assertSame(3, ReportShareGrant::query()->count());
        $this->assertSame(3, ReportDelivery::query()->count());
        $this->assertSame(1, ReportDelivery::query()->distinct('report_snapshot_id')->count('report_snapshot_id'));

        // Retry reuses same Snapshot / Artifact / deliveries.
        $again = app(ExecuteReportDeliveryOccurrenceService::class)->execute((int) $occurrence->id);
        $this->assertSame((int) $executed->report_snapshot_id, (int) $again->report_snapshot_id);
        $this->assertSame(1, ReportSnapshot::query()->count());
        $this->assertSame(3, ReportDelivery::query()->count());
    }

    public function test_correction_after_send_keeps_old_pdf_and_share(): void
    {
        [$user, $brand] = $this->seedBrandWithOutcomes(ql: 10);
        Storage::fake('local');
        Queue::fake();
        Mail::fake();

        $snapA = app(CreateReportSnapshotService::class)->create($brand, $user, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'idempotency_key' => 'corr-a',
        ]);
        $artifact = app(GenerateReportPdfService::class)->generate($snapA, $user);
        $grant = app(ReportShareService::class)->createGrant(
            $snapA,
            'keep@client.com',
            null,
            CarbonImmutable::now()->addDays(3),
            $user,
        );

        $def = app(BusinessOutcomeReadService::class)->findActiveDefinitionByKind($brand, BusinessOutcomeKind::QualifiedLead);
        app(BusinessOutcomeObservationService::class)->record($brand, $def, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'value' => 77,
            'completeness' => 'complete',
            'correction_reason' => 'Later correction',
        ], $user, allowCorrection: true);

        $snapB = app(CreateReportSnapshotService::class)->create($brand, $user, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'idempotency_key' => 'corr-b',
        ]);

        $this->assertNotSame((int) $snapA->id, (int) $snapB->id);
        $resolved = app(ReportShareService::class)->resolveGrantByLocator($grant['locator_token']);
        $this->assertSame((int) $snapA->id, (int) $resolved->report_snapshot_id);
        $this->assertSame((int) $snapA->id, (int) $artifact->fresh()->report_snapshot_id);
        $live = app(ClientValueStoryReadService::class)->forBrand($brand, '2026-07-01', '2026-07-31');
        $this->assertSame('77', (string) ($live->toPresentationArray()['business_outcomes']['qualified_leads'] ?? ''));
        $frozenQl = collect($snapA->content_payload['business_outcomes'] ?? [])
            ->firstWhere('kind', BusinessOutcomeKind::QualifiedLead->value);
        $this->assertSame('10', (string) ($frozenQl['value'] ?? ''));
    }

    public function test_share_security_headers_and_no_public_anonymous_access(): void
    {
        [$user, $brand] = $this->seedBrandWithOutcomes();
        $snapshot = app(CreateReportSnapshotService::class)->create($brand, $user, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'idempotency_key' => 'hdr-1',
        ]);
        $created = app(ReportShareService::class)->createGrant(
            $snapshot,
            'hdr@client.com',
            null,
            CarbonImmutable::now()->addDay(),
            $user,
        );

        $response = $this->get(route('reports.share.locator', ['token' => $created['locator_token']]));
        $response->assertRedirect();
        $follow = $this->get(route('reports.share.verify.form'));
        $follow->assertOk();
        $follow->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $follow->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', (string) $follow->headers->get('Cache-Control'));
        $follow->assertHeader('X-Frame-Options', 'DENY');

        $this->get(route('reports.share.view'))->assertStatus(403);
        $this->get('/reports/share/not-a-real-token')->assertStatus(404);
    }

    public function test_no_ai_provider_side_effects_and_access_audit(): void
    {
        [$user, $brand] = $this->seedBrandWithOutcomes();
        Storage::fake('local');
        $beforeFindings = (int) DB::table('findings')->count();
        $beforeTasks = (int) DB::table('tasks')->count();

        $snapshot = app(CreateReportSnapshotService::class)->create($brand, $user, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'idempotency_key' => 'side-1',
        ]);
        app(GenerateReportPdfService::class)->generate($snapshot, $user);
        $grant = app(ReportShareService::class)->createGrant(
            $snapshot,
            'audit@client.com',
            null,
            CarbonImmutable::now()->addDay(),
            $user,
        );
        app(ReportShareService::class)->audit($grant['grant'], ReportShareAccessEventType::ReportViewed);

        $this->assertSame($beforeFindings, (int) DB::table('findings')->count());
        $this->assertSame($beforeTasks, (int) DB::table('tasks')->count());
        $this->assertSame(1, ReportShareAccessEvent::query()->where('event_type', ReportShareAccessEventType::ReportViewed->value)->count());
        $this->assertFalse(Schema::hasTable('report_open_pixels'));
        $this->assertFalse(class_exists('App\\Models\\GenericWorkflow'));
    }

    public function test_paused_schedule_cancels_unstarted_occurrence(): void
    {
        [$user, $brand] = $this->seedBrandWithOutcomes();
        $schedules = app(ReportDeliveryScheduleService::class);
        $schedule = $schedules->create($brand, [
            'recipients' => [['email' => 'p@client.com']],
        ], $user);
        $schedules->pause($schedule);

        $occurrence = ReportDeliveryOccurrence::query()->create([
            'schedule_id' => (int) $schedule->id,
            'scheduled_for' => CarbonImmutable::now('UTC'),
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'status' => ReportDeliveryOccurrenceStatus::Pending,
            'occurrence_key' => 'paused-occ-1',
        ]);

        $result = app(ExecuteReportDeliveryOccurrenceService::class)->execute((int) $occurrence->id);
        $this->assertSame(ReportDeliveryOccurrenceStatus::Cancelled, $result->status);
        $this->assertSame(0, ReportSnapshot::query()->count());
    }

    /**
     * @return array{0: User, 1: Brand}
     */
    private function seedBrandWithOutcomes(int $ql = 20): array
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create(['name' => 'Acme Diş Grubu']);
        $brand = Brand::factory()->create([
            'customer_id' => $customer->id,
            'sector' => 'dental',
            'name' => 'Atlas Diş Ankara',
        ]);
        app(BusinessOutcomeDefinitionService::class)->createStandardDefinitionsForBrand($brand, $user);
        $def = app(BusinessOutcomeReadService::class)->findActiveDefinitionByKind($brand, BusinessOutcomeKind::QualifiedLead);
        app(BusinessOutcomeObservationService::class)->record($brand, $def, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'value' => $ql,
            'completeness' => 'complete',
        ], $user);

        return [$user, $brand];
    }

    private function plantOtp(ReportShareGrant $grant, string $code): string
    {
        ReportShareVerificationChallenge::query()->create([
            'share_grant_id' => (int) $grant->id,
            'code_hash' => SecretHasher::hash($code),
            'expires_at' => CarbonImmutable::now()->addMinutes(15),
            'created_at' => CarbonImmutable::now(),
        ]);

        return $code;
    }
}
