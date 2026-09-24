<?php

namespace Tests\Feature;

use App\Livewire\Demo\CaptureModal;
use App\Livewire\Demo\Dashboard;
use App\Livewire\Demo\Portfolio\CustomerDetail;
use App\Livewire\Operator\Portfolio\BrandShow;
use App\Models\User;
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
        $this->seedCanonicalPortfolio();
    }

    public function test_brand_value_sections_and_no_new_primary_tabs(): void
    {
        $this->get(route('operator.brand', ['brand' => $this->portfolioBrand->id, 'tab' => 'value']))
            ->assertOk()
            ->assertSee('Raporlar')
            ->assertSee(__('operator.reports.empty_schedules'))
            ->assertDontSee('Client Success Score');

        Livewire::test(BrandShow::class, ['brand' => (string) $this->portfolioBrand->id])
            ->call('setTab', 'reports')
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
            ->call('setTab', 'reports')
            ->call('setPeriod', 'this_month')
            ->assertOk();
    }

    public function test_report_preview_language_and_sections_without_dead_delivery(): void
    {
        Livewire::test(BrandShow::class, ['brand' => (string) $this->portfolioBrand->id])
            ->call('setTab', 'reports')
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
            ->call('setTab', 'history')
            ->assertSet('tab', 'reports')
            ->assertDontSee('Prefer German expansion after September');
    }

    public function test_dashboard_recent_value_and_search_types(): void
    {
        // Prompt 67 cleared Demo recentValue on the executive dashboard — section hidden when empty.
        Livewire::test(Dashboard::class)
            ->assertOk()
            ->assertDontSee('Atlas Dental')
            ->assertDontSee(__('operator.dashboard_exec.open_value'));
    }

    public function test_no_production_tables_for_value_entities(): void
    {
        foreach ([
            'client_value_stories',
            'reports',
            'report_sections',
            'knowledge_articles',
            'decision_logs',
            'narrative_snapshots',
        ] as $table) {
            $this->assertFalse(Schema::hasTable($table), "Unexpected table {$table}");
        }

        $migrationText = collect(File::files(database_path('migrations')))
            ->map(fn ($f) => File::get($f->getPathname()))
            ->implode("\n");

        // ADR-069: Faz 9 report v2 persists `monthly_reports` (frozen numbers + editable commentary) on purpose.
        foreach (['client_value_story', 'knowledge_articles'] as $needle) {
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
