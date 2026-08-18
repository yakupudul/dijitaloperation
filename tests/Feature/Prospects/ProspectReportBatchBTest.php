<?php

namespace Tests\Feature\Prospects;

use App\Enums\ProspectReportProjection;
use App\Enums\ProspectSalesIntelligenceStatus;
use App\Models\Prospect;
use App\Models\ProspectReportSnapshot;
use App\Models\ProspectSalesIntelligence;
use App\Models\User;
use App\Services\Prospects\CreateProspectReportSnapshotService;
use App\Services\Prospects\ProspectReportPdfRenderer;
use App\Services\Prospects\ProspectReportProjectionService;
use App\Services\Prospects\ProspectReportShareService;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProspectReportBatchBTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
    }

    public function test_client_projection_excludes_internal_fields(): void
    {
        $prospect = $this->prospectWithIntelligence();
        $projections = app(ProspectReportProjectionService::class);

        $internal = $projections->internal($prospect);
        $client = $projections->clientShareable($prospect);

        $this->assertSame(ProspectReportProjection::Internal->value, $internal['projection']);
        $this->assertSame(ProspectReportProjection::ClientShareable->value, $client['projection']);
        $this->assertArrayHasKey('first_meeting_focus', $internal);
        $this->assertArrayHasKey('not_recommended_services', $internal);
        $this->assertArrayHasKey('overall_confidence', $internal);

        foreach (ProspectReportProjectionService::CLIENT_FORBIDDEN_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $client, $key);
        }

        $projections->assertClientSafe($client);
    }

    public function test_client_snapshot_is_immutable_and_does_not_mutate_later(): void
    {
        $prospect = $this->prospectWithIntelligence();
        $service = app(CreateProspectReportSnapshotService::class);

        $snapshot = $service->generate($prospect, ProspectReportProjection::ClientShareable, $this->admin, 'en');
        $original = $snapshot->content_payload;

        try {
            $snapshot->update(['title' => 'mutated']);
            $this->fail('Expected immutable snapshot');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('IMMUTABLE', json_encode($exception->errors()) ?: '');
        }

        $prospect->inquiry = 'Changed after snapshot';
        $prospect->save();

        $fresh = ProspectReportSnapshot::query()->find($snapshot->id);
        $this->assertSame($original, $fresh?->content_payload);
    }

    public function test_client_pdf_html_does_not_leak_internal_copy(): void
    {
        $prospect = $this->prospectWithIntelligence();
        $snapshot = app(CreateProspectReportSnapshotService::class)->generate(
            $prospect,
            ProspectReportProjection::ClientShareable,
            $this->admin,
            'en',
        );

        $rendered = app(ProspectReportPdfRenderer::class)->render($snapshot);
        $this->assertStringStartsWith('%PDF', $rendered['bytes']);
        $this->assertStringNotContainsString('Do not share this sales strategy', $rendered['html']);
        $this->assertStringNotContainsString('first_meeting_focus', $rendered['html']);
        $this->assertStringNotContainsString('how to sell', strtolower($rendered['html']));
    }

    public function test_share_token_serves_only_client_snapshot(): void
    {
        $prospect = $this->prospectWithIntelligence();
        $service = app(CreateProspectReportSnapshotService::class);
        $internal = $service->generate($prospect, ProspectReportProjection::Internal, $this->admin, 'en', internalNotes: 'Do not share this sales strategy');
        $client = $service->generate($prospect, ProspectReportProjection::ClientShareable, $this->admin, 'en');

        try {
            app(ProspectReportShareService::class)->createGrant($internal, $this->admin);
            $this->fail('Internal snapshot must not be shareable');
        } catch (ValidationException) {
            // expected
        }

        $grant = app(ProspectReportShareService::class)->createGrant($client, $this->admin);
        $token = $grant['locator_token'];

        $this->get(route('prospect-reports.share.locator', ['token' => $token, 'snapshot' => $internal->id]))
            ->assertOk()
            ->assertSee($prospect->company_name)
            ->assertDontSee('Do not share this sales strategy')
            ->assertDontSee('first_meeting_focus');

        $this->get(route('prospect-reports.share.locator', ['token' => 'not-a-real-token']))
            ->assertNotFound();
    }

    public function test_guest_cannot_open_operator_report_or_convert_routes(): void
    {
        $prospect = Prospect::factory()->create();

        $this->get('/app/prospects/'.$prospect->id.'/convert')->assertRedirect('/app/login');
        $this->get('/app/prospects/'.$prospect->id.'/reports/1/download')->assertRedirect('/app/login');
    }

    public function test_client_report_requires_research_or_intelligence(): void
    {
        $prospect = Prospect::factory()->create();

        $this->expectException(ValidationException::class);
        app(CreateProspectReportSnapshotService::class)->generate(
            $prospect,
            ProspectReportProjection::ClientShareable,
            $this->admin,
        );
    }

    public function test_authenticated_operator_can_open_report_tab(): void
    {
        $prospect = $this->prospectWithIntelligence();

        $this->actingAs($this->admin)
            ->get('/app/prospects/'.$prospect->id.'?tab=report')
            ->assertOk()
            ->assertSee(__('operator.prospects.reports.internal'))
            ->assertSee(__('operator.prospects.reports.client'));
    }

    private function prospectWithIntelligence(): Prospect
    {
        $prospect = Prospect::factory()->create([
            'company_name' => 'Northwind Clinics',
            'inquiry' => 'Need ads help',
        ]);

        ProspectSalesIntelligence::query()->create([
            'prospect_id' => $prospect->id,
            'summary' => 'Public clinic website with weak conversion.',
            'detected_needs' => ['Website conversion'],
            'recommended_services' => [[
                'service_definition_code' => 'website_design',
                'rationale' => 'Public site needs conversion work.',
            ]],
            'not_recommended_services' => [[
                'service_definition_code' => 'meta_ads',
                'rationale' => 'No paid social signals.',
            ]],
            'sales_priorities' => ['Clarify lead flow'],
            'first_meeting_focus' => 'Do not share this sales strategy',
            'diagnostic_questions' => ['What is your monthly Google Ads budget?'],
            'suggested_positioning' => 'how to sell them measurement-first',
            'uncertainties' => ['Ads account health unknown'],
            'overall_confidence' => 'moderate',
            'status' => ProspectSalesIntelligenceStatus::Available,
            'metadata' => ['source' => 'test'],
        ]);

        return $prospect->fresh(['latestSalesIntelligence', 'evidence']) ?? $prospect;
    }
}
