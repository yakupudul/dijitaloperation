<?php

namespace Tests\Feature;

use App\Enums\RecurringReviewOccurrenceKind;
use App\Livewire\Demo\CaptureModal;
use App\Livewire\Demo\Dashboard;
use App\Livewire\Demo\GlobalSearch;
use App\Livewire\Demo\Operations\WorkShow;
use App\Livewire\Demo\Portfolio\BrandShow;
use App\Livewire\Demo\Portfolio\CustomerDetail;
use App\Livewire\Demo\Settings\PlaybookShow;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Playbook;
use App\Models\User;
use App\Services\Playbooks\SeedDefaultPlaybooks;
use App\Services\RecurringReviews\MaterializeRecurringReviewOccurrence;
use App\Services\RecurringReviews\RecurringReviewScheduleService;
use App\Support\Demo\ClientValueFixtures;
use App\Support\Demo\DemoState;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\CreatesCanonicalPortfolio;
use Tests\TestCase;

class ClientValueReportingKnowledgeTest extends TestCase
{
    use CreatesCanonicalPortfolio;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole(Roles::ADMIN);
        $this->actingAs($user);

        DemoState::reset();
        app(SeedDefaultPlaybooks::class)->seed($user);
        $this->seedCanonicalPortfolio();
    }

    public function test_brand_value_sections_and_no_new_primary_tabs(): void
    {
        $this->get(route('operator.brand', ['brand' => $this->portfolioBrand->id, 'tab' => 'value']))
            ->assertOk()
            ->assertSee(__('operator.value.title'))
            ->assertSee(__('operator.value.sections.overview'))
            ->assertSee(__('operator.value.sections.story'))
            ->assertSee(__('operator.value.sections.outcomes'))
            ->assertSee(__('operator.value.sections.decisions'))
            ->assertSee(__('operator.value.sections.reports'))
            ->assertSee(__('operator.value.no_magic_score'))
            ->assertDontSee('Client Success Score');

        Livewire::test(BrandShow::class, ['brand' => (string) $this->portfolioBrand->id])
            ->set('tab', 'value')
            ->set('valueSection', 'story')
            ->assertSee(__('operator.value.what_observed'))
            ->assertSee(__('operator.value.what_did'))
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

        Livewire::test(BrandShow::class, ['brand' => (string) $this->portfolioBrand->id])
            ->call('setPeriod', 'this_month')
            ->call('setValueSection', 'story')
            ->assertOk();
    }

    public function test_report_preview_language_and_sections_without_dead_delivery(): void
    {
        Livewire::test(BrandShow::class, ['brand' => (string) $this->portfolioBrand->id])
            ->call('setValueSection', 'reports')
            ->call('setReportLanguage', 'tr')
            ->assertDontSee('Demo Rapor Önizleme')
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
        $this->get(route('operator.customer', ['customerId' => $this->portfolioCustomer->id, 'tab' => 'reports']))
            ->assertOk()
            ->assertSee(__('operator.reports.customer_title'))
            ->assertSee(__('operator.reports.no_blind_aggregation'));

        Livewire::test(CustomerDetail::class, ['customerId' => (string) $this->portfolioCustomer->id])
            ->call('setTab', 'reports')
            ->assertSee($this->portfolioBrand->name)
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
            ->assertSet('open', true);

        $this->assertSame([], DemoState::captureDecisions());

        Livewire::test(BrandShow::class, ['brand' => (string) $this->portfolioBrand->id])
            ->call('setValueSection', 'decisions')
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
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $asset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'google_ads']);
        $playbook = Playbook::query()->where('stable_key', 'pb-weekly-gads')->firstOrFail();

        $schedule = app(RecurringReviewScheduleService::class)->create([
            'customer_id' => $customer->id,
            'scope_kind' => 'digital_asset',
            'brand_id' => $brand->id,
            'digital_asset_id' => $asset->id,
            'playbook_id' => $playbook->id,
            'cadence' => 'weekly',
            'timezone' => 'UTC',
            'starts_at' => now()->toDateTimeString(),
            'checks' => [['title' => 'Confirm conversion signal']],
        ], auth()->user(), 'cv-rr-sched');

        $run = app(MaterializeRecurringReviewOccurrence::class)->materialize(
            $schedule,
            'manual:cv-knowledge',
            now(),
            RecurringReviewOccurrenceKind::Manual,
            auth()->user(),
        );

        Livewire::test(WorkShow::class, ['workId' => (string) $run->id, 'type' => 'recurring_review'])
            ->assertOk()
            ->assertSee(__('operator.value.work_context'))
            ->assertSee(__('operator.value.work_context_playbook'));
    }

    public function test_dashboard_recent_value_and_search_types(): void
    {
        // Prompt 67 cleared Demo recentValue on the executive dashboard — section hidden when empty.
        Livewire::test(Dashboard::class)
            ->assertOk()
            ->assertDontSee('Atlas Dental')
            ->assertDontSee(__('operator.dashboard_exec.open_value'));

        Livewire::test(GlobalSearch::class)
            ->set('q', 'Weekly Google Ads')
            ->assertSee('Playbook');
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
        $this->get(route('operator.brand', ['brand' => $this->portfolioBrand->id, 'tab' => 'value', 'value' => 'reports']))
            ->assertOk()
            ->assertDontSee('href="/system"');
    }
}
