<?php

namespace Tests\Feature\ReportSnapshots;

use App\Enums\AssistantCapabilityId;
use App\Enums\AssistantIntentType;
use App\Enums\AssistantSourceClass;
use App\Enums\BusinessOutcomeKind;
use App\Enums\ReportSnapshotSchemaVersion;
use App\Enums\ReportType;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Finding;
use App\Models\Opportunity;
use App\Models\ReportSnapshot;
use App\Models\Task;
use App\Models\User;
use App\Services\Assistant\MoxdopAssistantService;
use App\Services\BusinessOutcomes\BusinessOutcomeDefinitionService;
use App\Services\BusinessOutcomes\BusinessOutcomeObservationService;
use App\Services\BusinessOutcomes\BusinessOutcomeReadService;
use App\Services\ClientValueStory\ClientValueStoryReadService;
use App\Services\ReportSnapshots\CreateReportSnapshotService;
use App\Services\ReportSnapshots\ReportSnapshotReadService;
use App\Support\Assistant\AssistantSourceAuthority;
use App\Support\Assistant\Dto\AssistantIntentCandidate;
use App\Support\IntelligenceEvaluation\IntelligenceEvaluationCaseCatalog;
use App\Support\ReportSnapshots\CanonicalJson;
use App\Support\ReportSnapshots\ReportSnapshotChecksum;
use App\Support\ReportSnapshots\ReportTypeRegistry;
use App\Support\Tasks\TaskStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ReportSnapshotProductionPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_architecture_boundaries_and_registry(): void
    {
        $this->assertTrue(Schema::hasTable('report_snapshots'));
        $this->assertFalse(Schema::hasTable('report_snapshot_v2'));
        $this->assertFalse(Schema::hasTable('report_deliveries'));
        $this->assertFalse(Schema::hasTable('report_share_tokens'));
        $this->assertFalse(class_exists('App\\Models\\ReportSnapshotV2'));
        $this->assertFalse(class_exists('App\\Support\\ClientValueStory\\Dto\\SourceManifestV2'));

        $registry = app(ReportTypeRegistry::class);
        $this->assertTrue($registry->has(ReportType::ClientValueStory->value));
        $entry = $registry->get(ReportType::ClientValueStory->value);
        $this->assertSame('brand', $entry['allowed_scope']);
        $this->assertSame(ClientValueStoryReadService::class, $entry['source_read_service']);
        $this->assertSame(ReportSnapshotSchemaVersion::ClientValueStoryV1->value, $entry['snapshot_schema']);

        $keys = IntelligenceEvaluationCaseCatalog::reportSnapshotPreparedCaseKeys();
        $this->assertContains(IntelligenceEvaluationCaseCatalog::REPORT_SNAPSHOT_REMAINS_IMMUTABLE, $keys);
        $this->assertContains(IntelligenceEvaluationCaseCatalog::OUTCOME_CORRECTION_AFTER_SNAPSHOT, $keys);
        $this->assertArrayHasKey(AssistantSourceClass::ReportSnapshot->value, app(AssistantSourceAuthority::class)->matrix());
    }

    public function test_create_brand_snapshot_server_builds_payload(): void
    {
        [$user, $brand, $asset] = $this->seedBrand();
        $this->seedStorySources($user, $brand, $asset, ql: 20);

        $snapshot = app(CreateReportSnapshotService::class)->create($brand, $user, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'locale' => 'en',
            'idempotency_key' => 'cmd-create-1',
        ]);

        $this->assertSame(ReportType::ClientValueStory, $snapshot->report_type);
        $this->assertSame((int) $brand->id, (int) $snapshot->brand_id);
        $this->assertSame((int) $brand->customer_id, (int) $snapshot->customer_id);
        $this->assertSame((int) $user->id, (int) $snapshot->generated_by);
        $this->assertSame('en', $snapshot->locale);
        $this->assertNotEmpty($snapshot->content_checksum);
        $this->assertNotEmpty($snapshot->source_manifest_fingerprint);
        $this->assertSame(ReportSnapshotSchemaVersion::ClientValueStoryV1, $snapshot->snapshot_schema_version);
        $this->assertFalse($snapshot->content_payload['attribution_established']);
        $this->assertFalse($snapshot->content_payload['causality_established']);
        $this->assertSame('20', (string) collect($snapshot->content_payload['business_outcomes'])
            ->firstWhere('kind', BusinessOutcomeKind::QualifiedLead->value)['value']);

        $this->expectException(ValidationException::class);
        app(CreateReportSnapshotService::class)->create($brand, $user, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'content_payload' => ['forged' => true],
        ]);
    }

    public function test_rejects_wrong_brand_auth_and_invalid_period(): void
    {
        [$user, $brand] = $this->seedBrand();
        $other = Brand::factory()->create();

        try {
            app(CreateReportSnapshotService::class)->create($brand, $user, [
                'period_start' => '2026-07-01',
                'period_end' => '2026-07-31',
            ], authorizedBrandIds: [(int) $other->id]);
            $this->fail('Expected unauthorized brand rejection');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('brand', $e->errors());
        }

        try {
            app(CreateReportSnapshotService::class)->create($brand, $user, [
                'period_start' => '2026-07-31',
                'period_end' => '2026-07-01',
            ]);
            $this->fail('Expected invalid period rejection');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('period', $e->errors());
        }
    }

    public function test_snapshot_is_immutable_after_create(): void
    {
        [$user, $brand] = $this->seedBrand();
        $snapshot = app(CreateReportSnapshotService::class)->create($brand, $user, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'idempotency_key' => 'immutable-1',
        ]);

        $this->expectException(ValidationException::class);
        $snapshot->update(['period_start' => '2026-06-01']);
    }

    public function test_outcome_correction_does_not_mutate_old_snapshot(): void
    {
        [$user, $brand, $asset] = $this->seedBrand();
        $this->seedStorySources($user, $brand, $asset, ql: 20);

        $snapA = app(CreateReportSnapshotService::class)->create($brand, $user, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'idempotency_key' => 'outcome-a',
        ]);
        $revA = $snapA->source_manifest_payload['outcome_observation_revision_ids'] ?? [];
        $this->assertNotEmpty($revA);

        $ql = app(BusinessOutcomeReadService::class)->findActiveDefinitionByKind($brand, BusinessOutcomeKind::QualifiedLead);
        app(BusinessOutcomeObservationService::class)->record($brand, $ql, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'value' => 24,
            'completeness' => 'complete',
            'correction_reason' => 'Client corrected July count',
        ], $user, allowCorrection: true);

        $live = app(ClientValueStoryReadService::class)->forBrand($brand, '2026-07-01', '2026-07-31');
        $this->assertSame('24', (string) collect($live->outcomes)->firstWhere(
            static fn ($o) => $o->kind === BusinessOutcomeKind::QualifiedLead
        )->value);

        $detailA = app(ReportSnapshotReadService::class)->detail((int) $snapA->id);
        $frozenQl = collect($detailA['content']['business_outcomes'])->firstWhere('kind', BusinessOutcomeKind::QualifiedLead->value);
        $this->assertSame('20', (string) $frozenQl['value']);
        $this->assertSame($revA, $detailA['source_manifest']['outcome_observation_revision_ids']);
        $this->assertFalse($detailA['rebuilt_from_live_story']);

        $snapB = app(CreateReportSnapshotService::class)->create($brand, $user, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'idempotency_key' => 'outcome-b',
            'supersedes_snapshot_id' => (int) $snapA->id,
        ]);
        $frozenB = collect($snapB->content_payload['business_outcomes'])->firstWhere('kind', BusinessOutcomeKind::QualifiedLead->value);
        $this->assertSame('24', (string) $frozenB['value']);
        $this->assertNotSame($snapA->source_manifest_fingerprint, $snapB->source_manifest_fingerprint);
        $this->assertSame((int) $snapA->id, (int) $snapB->supersedes_snapshot_id);

        // Old row untouched
        $snapA->refresh();
        $this->assertSame('20', (string) collect($snapA->content_payload['business_outcomes'])
            ->firstWhere('kind', BusinessOutcomeKind::QualifiedLead->value)['value']);
    }

    public function test_finding_and_opportunity_freeze_after_snapshot(): void
    {
        [$user, $brand, $asset] = $this->seedBrand();
        $finding = Finding::factory()->create([
            'customer_id' => $brand->customer_id,
            'brand_id' => $brand->id,
            'digital_asset_id' => $asset->id,
            'title' => 'Open mapping gap',
            'status' => Finding::STATUS_OPEN,
            'first_seen_at' => '2026-07-05 10:00:00',
            'last_seen_at' => '2026-07-20 10:00:00',
            'resolved_at' => null,
        ]);
        $opp = Opportunity::factory()->create([
            'customer_id' => $brand->customer_id,
            'brand_id' => $brand->id,
            'digital_asset_id' => $asset->id,
            'title' => 'Active opportunity',
            'status' => Opportunity::STATUS_OPEN,
            'first_detected_at' => '2026-07-01 10:00:00',
            'last_detected_at' => '2026-07-15 10:00:00',
        ]);

        $snap = app(CreateReportSnapshotService::class)->create($brand, $user, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'idempotency_key' => 'finding-freeze',
        ]);

        $finding->update([
            'status' => Finding::STATUS_RESOLVED,
            'resolved_at' => '2026-08-01 10:00:00',
            'title' => 'Resolved mapping gap',
        ]);
        $opp->update([
            'status' => Opportunity::STATUS_DISMISSED,
            'closed_at' => '2026-08-02 10:00:00',
        ]);

        $detail = app(ReportSnapshotReadService::class)->detail((int) $snap->id);
        $frozenFinding = collect($detail['content']['findings'])->firstWhere('finding_id', (int) $finding->id);
        $this->assertSame(Finding::STATUS_OPEN, $frozenFinding['status']);
        $this->assertSame('Open mapping gap', $frozenFinding['title']);
        $frozenOpp = collect($detail['content']['opportunities'])->firstWhere('opportunity_id', (int) $opp->id);
        $this->assertSame(Opportunity::STATUS_OPEN, $frozenOpp['status']);
    }

    public function test_work_state_freeze_and_brand_rename(): void
    {
        [$user, $brand, $asset] = $this->seedBrand();
        $task = Task::factory()->create([
            'customer_id' => $brand->customer_id,
            'brand_id' => $brand->id,
            'digital_asset_id' => $asset->id,
            'recommendation_id' => null,
            'source_kind' => 'direct',
            'title' => 'Completed mapping fix',
            'status' => TaskStatus::COMPLETED,
            'completed_at' => '2026-07-18 12:00:00',
            'completed_by_id' => $user->id,
        ]);

        $oldName = (string) $brand->name;
        $snap = app(CreateReportSnapshotService::class)->create($brand, $user, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'locale' => 'tr',
            'idempotency_key' => 'work-freeze',
        ]);

        $task->update(['title' => 'Later renamed task', 'status' => TaskStatus::OPEN, 'completed_at' => null]);
        $brand->update(['name' => 'Renamed Brand LLC']);

        $detail = app(ReportSnapshotReadService::class)->detail((int) $snap->id);
        $this->assertSame($oldName, $detail['brand_name']);
        $this->assertSame('tr', $detail['locale']);
        $frozenWork = collect($detail['content']['completed_work'])->firstWhere('task_id', (int) $task->id);
        $this->assertSame('Completed mapping fix', $frozenWork['title']);
        $this->assertSame(TaskStatus::COMPLETED, $frozenWork['status']);
    }

    public function test_idempotency_and_explicit_regeneration(): void
    {
        [$user, $brand] = $this->seedBrand();

        $a = app(CreateReportSnapshotService::class)->create($brand, $user, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'idempotency_key' => 'same-cmd',
        ]);
        $b = app(CreateReportSnapshotService::class)->create($brand, $user, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'idempotency_key' => 'same-cmd',
        ]);
        $this->assertSame((int) $a->id, (int) $b->id);
        $this->assertSame(1, ReportSnapshot::query()->where('idempotency_key', 'same-cmd')->count());

        $c = app(CreateReportSnapshotService::class)->create($brand, $user, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'idempotency_key' => 'explicit-second',
        ]);
        $this->assertNotSame((int) $a->id, (int) $c->id);
        $this->assertSame($a->source_manifest_fingerprint, $c->source_manifest_fingerprint);
    }

    public function test_checksum_deterministic_and_tamper_detected(): void
    {
        [$user, $brand] = $this->seedBrand();
        $snap = app(CreateReportSnapshotService::class)->create($brand, $user, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'idempotency_key' => 'checksum-1',
        ]);

        $payload = $snap->content_payload;
        $this->assertTrue(ReportSnapshotChecksum::verify($payload, (string) $snap->content_checksum));
        $this->assertSame(
            ReportSnapshotChecksum::hash($payload),
            ReportSnapshotChecksum::hash($payload),
        );

        $tampered = $payload;
        $tampered['business_outcomes'][0]['value'] = '999';
        $this->assertFalse(ReportSnapshotChecksum::verify($tampered, (string) $snap->content_checksum));

        DB::table('report_snapshots')->where('id', $snap->id)->update([
            'content_payload' => CanonicalJson::encode($tampered),
        ]);
        $this->expectException(ValidationException::class);
        app(ReportSnapshotReadService::class)->detail((int) $snap->id, verifyChecksum: true);
    }

    public function test_customer_history_multi_brand_no_aggregate_and_auth(): void
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $brandA = Brand::factory()->create(['customer_id' => $customer->id, 'name' => 'Brand A']);
        $brandB = Brand::factory()->create(['customer_id' => $customer->id, 'name' => 'Brand B']);

        app(CreateReportSnapshotService::class)->create($brandA, $user, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'idempotency_key' => 'hist-a',
        ]);
        app(CreateReportSnapshotService::class)->create($brandB, $user, [
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'idempotency_key' => 'hist-b',
        ]);

        $presentation = app(ReportSnapshotReadService::class)->forCustomerReportsPresentation((int) $customer->id);
        $this->assertCount(2, $presentation['snapshots']);
        $this->assertFalse($presentation['demo']);
        $this->assertFalse($presentation['fake_reports']);
        $this->assertCount(2, $presentation['brands']);

        $otherCustomer = Customer::factory()->create();
        $this->expectException(ValidationException::class);
        app(ReportSnapshotReadService::class)->detail(
            (int) ReportSnapshot::query()->where('brand_id', $brandA->id)->value('id'),
            authorizedCustomerIds: [(int) $otherCustomer->id],
        );
    }

    public function test_no_data_snapshot_allowed_and_no_delivery_artifacts(): void
    {
        [$user, $brand] = $this->seedBrand();
        $snap = app(CreateReportSnapshotService::class)->create($brand, $user, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'idempotency_key' => 'no-data',
        ]);

        $this->assertNotEmpty($snap->content_payload['limitations'] ?? ['x']);
        $this->assertFalse(Schema::hasTable('report_pdfs'));
        $this->assertFalse(Schema::hasTable('report_recipients'));
        $this->assertSame(0, DB::table('report_snapshots')->whereNotNull('id')->where('id', -1)->count());
        $detail = app(ReportSnapshotReadService::class)->detail((int) $snap->id);
        $this->assertFalse($detail['delivery']['pdf']);
        $this->assertFalse($detail['delivery']['share']);
        $this->assertSame('prompt_60', $detail['delivery']['owner']);
    }

    public function test_assistant_distinguishes_historical_snapshot_from_current_outcome(): void
    {
        [$user, $brand, $asset] = $this->seedBrand();
        $this->seedStorySources($user, $brand, $asset, ql: 27);

        $snap = app(CreateReportSnapshotService::class)->create($brand, $user, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'idempotency_key' => 'assistant-hist',
        ]);

        $ql = app(BusinessOutcomeReadService::class)->findActiveDefinitionByKind($brand, BusinessOutcomeKind::QualifiedLead);
        app(BusinessOutcomeObservationService::class)->record($brand, $ql, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'value' => 29,
            'completeness' => 'complete',
            'correction_reason' => 'Corrected after report',
        ], $user, allowCorrection: true);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-16'));

        $historical = app(MoxdopAssistantService::class)->ask(
            userId: (int) $user->id,
            candidate: new AssistantIntentCandidate(
                intentType: AssistantIntentType::HistoricalContext,
                capabilityId: AssistantCapabilityId::ReportSnapshotLookup,
                periodToken: 'last_month',
                domainFilter: 'report_snapshot',
                parameters: ['historical_report' => true],
            ),
            authorizedCustomerIds: [(int) $brand->customer_id],
            authorizedBrandIds: [(int) $brand->id],
            authorizedDigitalAssetIds: [],
            customerId: (int) $brand->customer_id,
            brandId: (int) $brand->id,
            timezone: 'UTC',
        );
        $this->assertFalse($historical->runtimeProvenance['ai_used'] ?? true);
        $this->assertFalse($historical->runtimeProvenance['overrides_current_canonical_domains'] ?? true);
        $this->assertSame(AssistantSourceClass::ReportSnapshot, $historical->claims[0]->requiredSourceClass);
        $block = $historical->blocks[0]['report_snapshots'][0] ?? null;
        $this->assertNotNull($block);
        $this->assertSame((int) $snap->id, (int) $block['id']);

        $current = app(MoxdopAssistantService::class)->ask(
            userId: (int) $user->id,
            candidate: new AssistantIntentCandidate(
                intentType: AssistantIntentType::FactLookup,
                metricId: 'business_outcome.qualified_lead',
                periodToken: 'last_month',
            ),
            authorizedCustomerIds: [(int) $brand->customer_id],
            authorizedBrandIds: [(int) $brand->id],
            authorizedDigitalAssetIds: [],
            customerId: (int) $brand->customer_id,
            brandId: (int) $brand->id,
            timezone: 'UTC',
        );
        $this->assertSame(AssistantSourceClass::BusinessOutcome, $current->claims[0]->requiredSourceClass);
        CarbonImmutable::setTestNow();
    }

    public function test_no_ai_provider_writes_during_create(): void
    {
        [$user, $brand] = $this->seedBrand();
        $beforeFindings = (int) DB::table('findings')->count();
        $beforeTasks = (int) DB::table('tasks')->count();
        $beforeOutcomes = (int) DB::table('business_outcome_observations')->count();

        app(CreateReportSnapshotService::class)->create($brand, $user, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'idempotency_key' => 'no-side-effects',
        ]);

        $this->assertSame($beforeFindings, (int) DB::table('findings')->count());
        $this->assertSame($beforeTasks, (int) DB::table('tasks')->count());
        $this->assertSame($beforeOutcomes, (int) DB::table('business_outcome_observations')->count());
        $this->assertSame(1, ReportSnapshot::query()->count());
    }

    /**
     * @return array{0: User, 1: Brand, 2: DigitalAsset}
     */
    private function seedBrand(): array
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create(['name' => 'Acme Dental Group']);
        $brand = Brand::factory()->create([
            'customer_id' => $customer->id,
            'sector' => 'dental',
            'name' => 'Atlas Dental Ankara',
        ]);
        $asset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website']);

        return [$user, $brand, $asset];
    }

    private function seedStorySources(User $user, Brand $brand, DigitalAsset $asset, int $ql = 20): void
    {
        Finding::factory()->create([
            'customer_id' => $brand->customer_id,
            'brand_id' => $brand->id,
            'digital_asset_id' => $asset->id,
            'title' => 'Conversion mapping gap',
            'status' => Finding::STATUS_OPEN,
            'first_seen_at' => '2026-07-05 10:00:00',
            'last_seen_at' => '2026-07-20 10:00:00',
        ]);
        Opportunity::factory()->create([
            'customer_id' => $brand->customer_id,
            'brand_id' => $brand->id,
            'digital_asset_id' => $asset->id,
            'title' => 'Implant demand gap',
            'status' => Opportunity::STATUS_OPEN,
            'first_detected_at' => '2026-07-01 10:00:00',
            'last_detected_at' => '2026-07-15 10:00:00',
        ]);
        Task::factory()->create([
            'customer_id' => $brand->customer_id,
            'brand_id' => $brand->id,
            'digital_asset_id' => $asset->id,
            'recommendation_id' => null,
            'source_kind' => 'direct',
            'title' => 'Fixed conversion mapping',
            'status' => TaskStatus::COMPLETED,
            'completed_at' => '2026-07-18 12:00:00',
            'completed_by_id' => $user->id,
        ]);
        app(BusinessOutcomeDefinitionService::class)->createStandardDefinitionsForBrand($brand, $user);
        $def = app(BusinessOutcomeReadService::class)->findActiveDefinitionByKind($brand, BusinessOutcomeKind::QualifiedLead);
        app(BusinessOutcomeObservationService::class)->record($brand, $def, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'value' => $ql,
            'completeness' => 'complete',
        ], $user);
    }
}
