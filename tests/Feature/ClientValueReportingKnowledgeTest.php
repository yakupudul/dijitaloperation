<?php

namespace Tests\Feature;

use App\Livewire\Demo\CaptureModal;
use App\Livewire\Demo\Dashboard;
use App\Livewire\Demo\GlobalSearch;
use App\Livewire\Demo\Operations\WorkShow;
use App\Livewire\Demo\Portfolio\BrandShow;
use App\Livewire\Demo\Portfolio\CustomerDetail;
use App\Livewire\Demo\Settings\PlaybookShow;
use App\Models\User;
use App\Support\Demo\ClientValueFixtures;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class ClientValueReportingKnowledgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole(Roles::ADMIN);
        $this->actingAs($user);

        DemoState::reset();
    }

    public function test_brand_value_sections_and_no_new_primary_tabs(): void
    {
        $this->get(route('demo.brand', ['brand' => DemoCatalog::BRAND_ID, 'tab' => 'value']))
            ->assertOk()
            ->assertSee(__('operator.value.title'))
            ->assertSee(__('operator.value.sections.overview'))
            ->assertSee(__('operator.value.sections.story'))
            ->assertSee(__('operator.value.sections.outcomes'))
            ->assertSee(__('operator.value.sections.decisions'))
            ->assertSee(__('operator.value.sections.reports'))
            ->assertSee(__('operator.value.no_magic_score'))
            ->assertDontSee('Client Success Score');

        Livewire::test(BrandShow::class, ['brand' => DemoCatalog::BRAND_ID])
            ->set('tab', 'value')
            ->set('valueSection', 'story')
            ->assertSee(__('operator.value.what_observed'))
            ->assertSee(__('operator.value.what_did'))
            ->assertSee(__('operator.value.observed_after'))
            ->assertDontSee('Our work caused');
    }

    public function test_value_story_reconciles_business_outcomes_and_period(): void
    {
        $story = ClientValueFixtures::valueStory('last_28');
        $summary = ClientValueFixtures::valueSummary('last_28');

        $this->assertSame(count($story['observations']), $summary['observed']);
        $this->assertSame(count($story['completed_work']), $summary['delivered']);
        $this->assertTrue($story['business_outcomes']['available']);
        $this->assertSame(38, (int) $story['business_outcomes']['qualified_leads']);
        $this->assertSame(21, (int) $story['business_outcomes']['consultations']);

        Livewire::test(BrandShow::class, ['brand' => DemoCatalog::BRAND_ID])
            ->call('setPeriod', 'this_month')
            ->call('setValueSection', 'story')
            ->assertOk();
    }

    public function test_report_preview_language_and_sections_without_dead_delivery(): void
    {
        Livewire::test(BrandShow::class, ['brand' => DemoCatalog::BRAND_ID])
            ->call('setValueSection', 'reports')
            ->call('setReportLanguage', 'tr')
            ->assertSee('Demo Rapor Önizleme')
            ->assertSee('İmplant talebi Markanın en güçlü büyüme teması olmayı sürdürdü.')
            ->assertDontSee('Download PDF')
            ->assertDontSee('Send Email')
            ->assertDontSee('Share Public Link')
            ->call('toggleReportSection', 'supporting_metrics')
            ->assertOk();

        $preview = ClientValueFixtures::reportPreview([
            'period' => 'last_28',
            'language' => 'en',
            'sections' => array_fill_keys(ClientValueFixtures::reportSectionKeys(), true),
        ]);
        $this->assertNotEmpty($preview['supporting_metrics']);
        $this->assertStringContainsString('future', mb_strtolower($preview['future_delivery_note']));
    }

    public function test_customer_reports_and_no_blind_aggregation(): void
    {
        $this->get(route('demo.customer', ['customerId' => DemoCatalog::CUSTOMER_ID, 'tab' => 'reports']))
            ->assertOk()
            ->assertSee(__('operator.reports.customer_title'))
            ->assertSee(__('operator.value.customer_no_blind_aggregation'));

        Livewire::test(CustomerDetail::class, ['customerId' => DemoCatalog::CUSTOMER_ID])
            ->call('setTab', 'reports')
            ->assertSee('Atlas Dental')
            ->assertSee(__('operator.reports.open_brand_report'));
    }

    public function test_decision_history_and_capture_decision_integration(): void
    {
        Livewire::test(CaptureModal::class)
            ->call('openCapture', 'note')
            ->set('title', 'Prefer German expansion after September')
            ->set('description', 'Client preference for DE market.')
            ->set('noteKind', 'decision')
            ->call('save')
            ->assertSet('open', false);

        $this->assertNotEmpty(DemoState::captureDecisions());

        Livewire::test(BrandShow::class, ['brand' => DemoCatalog::BRAND_ID])
            ->call('setValueSection', 'decisions')
            ->assertSee('Prefer German expansion after September')
            ->assertSee(__('operator.value.decision_history'));
    }

    public function test_playbook_knowledge_and_ai_skill_distinction(): void
    {
        Livewire::test(PlaybookShow::class, ['playbookId' => 'pb-weekly-gads'])
            ->assertOk()
            ->assertSee(__('operator.playbooks.when_to_use'))
            ->assertSee(__('operator.playbooks.when_not_to_use'))
            ->assertSee(__('operator.playbooks.methodology'))
            ->assertSee(__('operator.playbooks.qa_guidance'))
            ->assertSee('Search Query Analysis')
            ->assertSee(__('operator.playbooks.ai_skill_note'));
    }

    public function test_work_contextual_knowledge(): void
    {
        Livewire::test(WorkShow::class, ['workId' => 'rr-gads-aug13', 'type' => 'recurring_review'])
            ->assertOk()
            ->assertSee(__('operator.value.work_context'))
            ->assertSee(__('operator.value.work_context_playbook'));
    }

    public function test_dashboard_recent_value_and_search_types(): void
    {
        Livewire::test(Dashboard::class)
            ->assertOk()
            ->assertSee(__('operator.dashboard_exec.recent_value'))
            ->assertSee('Atlas Dental');

        Livewire::test(GlobalSearch::class)
            ->set('q', 'Weekly Google Ads')
            ->assertSee('Playbook')
            ->set('q', 'Expand implant organic')
            ->assertSee('Decision');
    }

    public function test_no_production_tables_for_value_entities(): void
    {
        foreach ([
            'client_value_stories',
            'reports',
            'report_sections',
            'knowledge_articles',
            'decision_logs',
            'monthly_reports',
            'narrative_snapshots',
        ] as $table) {
            $this->assertFalse(Schema::hasTable($table), "Unexpected table {$table}");
        }

        $migrationText = collect(File::files(database_path('migrations')))
            ->map(fn ($f) => File::get($f->getPathname()))
            ->implode("\n");

        foreach (['client_value_story', 'knowledge_articles', 'monthly_reports'] as $needle) {
            $this->assertStringNotContainsString($needle, $migrationText);
        }
    }

    public function test_operator_routes_remain_under_app(): void
    {
        $this->get(route('demo.brand', ['brand' => DemoCatalog::BRAND_ID, 'tab' => 'value', 'value' => 'reports']))
            ->assertOk()
            ->assertDontSee('href="/system"');
    }
}
