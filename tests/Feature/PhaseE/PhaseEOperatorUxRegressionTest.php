<?php

namespace Tests\Feature\PhaseE;

use App\Livewire\Demo\CaptureModal;
use App\Livewire\Demo\Operations\ActivityIndex;
use App\Livewire\Demo\Operations\FindingsIndex;
use App\Livewire\Demo\Operations\RecommendationsIndex;
use App\Livewire\Demo\SettingsPage;
use App\Livewire\Demo\Website\OverviewPage as WebsiteOverviewPage;
use App\Livewire\Operator\Assets\AnalyticsPage;
use App\Livewire\Operator\GoogleAds\OverviewPage as GoogleAdsOverviewPage;
use App\Models\Brand;
use App\Models\ClientRequest;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\Run;
use App\Models\Task;
use App\Models\User;
use App\Services\Activity\ActivityReadService;
use App\Services\Async\AsyncOperationService;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoPeriod;
use App\Support\Demo\DemoState;
use App\Support\Operator\OperatorPeriod;
use App\Support\Roles;
use App\Support\Tasks\TaskOutcomeStatus;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesCanonicalPortfolio;
use Tests\TestCase;

class PhaseEOperatorUxRegressionTest extends TestCase
{
    use CreatesCanonicalPortfolio;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['locale' => 'en']);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        DemoState::reset();
        $this->travelTo('2026-08-20 10:00:00');
    }

    #[Test]
    public function canonical_routes_render_and_retired_surfaces_are_gone(): void
    {
        $this->get('/')->assertOk();
        $this->get('/customers')->assertOk();
        $this->get('/brands')->assertOk();
        $this->get('/assets')->assertOk();
        $this->get('/integrations')->assertOk();
        $this->get('/activity')->assertOk();
        $this->get('/findings')->assertOk();
        $this->get('/recommendations')->assertOk();
        $this->get('/tasks')->assertOk();
        $this->get('/settings')->assertOk();
        $this->get('/profile')->assertOk();
        $this->get('/prospects')->assertOk();

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('Toggle Mobile Menu', $html);
        $this->assertStringNotContainsString('Atlas Dental Ankara', $html);

        $this->get('/app/customers')->assertStatus(410);
        $this->get('/system/login')->assertStatus(410);
        $this->get(route('operator.website', ['assetId' => DemoCatalog::WEBSITE_ASSET_ID]))->assertNotFound();
        $this->get(route('operator.analytics', ['assetId' => DemoCatalog::GA4_ASSET_ID]))->assertNotFound();
    }

    #[Test]
    public function capture_note_and_opportunity_do_not_persist(): void
    {
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);

        Livewire::test(CaptureModal::class)
            ->call('openCapture', 'note', (string) $brand->id, (string) $customer->id)
            ->set('title', 'Should not persist as a note')
            ->set('captureType', 'note')
            ->call('save')
            ->assertSet('open', true);

        $this->assertSame([], DemoState::all()['capture_notes'] ?? []);
        $this->assertSame([], DemoState::captureDecisions());
        $this->assertSame(0, Task::query()->count());
        $this->assertSame(0, ClientRequest::query()->count());

        Livewire::test(CaptureModal::class)
            ->call('openCapture', 'opportunity_hypothesis', (string) $brand->id, (string) $customer->id)
            ->set('title', 'Should not persist as an opportunity')
            ->set('captureType', 'opportunity_hypothesis')
            ->call('save')
            ->assertSet('open', true);

        Livewire::test(CaptureModal::class)
            ->call('openCapture', 'client_request', (string) $brand->id, (string) $customer->id)
            ->set('title', 'Real client request')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(ClientRequest::query()->where('title', 'Real client request')->exists());
    }

    #[Test]
    public function google_ads_create_recommendation_does_not_invent_rows(): void
    {
        $asset = $this->createPortfolioAsset('google_ads', 'Northwind Ads');

        Livewire::test(GoogleAdsOverviewPage::class, ['assetId' => (string) $asset->id])
            ->call('createRecommendation', 'brand query')
            ->assertHasNoErrors();

        $this->assertSame(0, Recommendation::query()->count());
    }

    #[Test]
    public function production_period_uses_operator_clock_not_demo_anchor(): void
    {
        $asset = $this->createPortfolioAsset('ga4', 'Northwind GA4');

        $component = Livewire::test(AnalyticsPage::class, ['assetId' => (string) $asset->id])
            ->call('setPeriod', 'last_7');

        $operator = OperatorPeriod::bounds('last_7');
        $demo = DemoPeriod::bounds('last_7');

        $component
            ->assertSet('periodStart', $operator['start']->toDateString())
            ->assertSet('periodEnd', $operator['end']->toDateString());

        $this->assertNotSame($demo['end']->toDateString(), $operator['end']->toDateString());
        $this->assertSame(DemoPeriod::ANCHOR_DATE, $demo['end']->toDateString());
        $this->assertSame('2026-08-20', $operator['end']->toDateString());
        $this->assertSame('2026-08-14', $operator['start']->toDateString());
    }

    #[Test]
    public function website_custom_period_hides_non_overlapping_kpis_instead_of_stale_or_zero(): void
    {
        $asset = $this->createPortfolioAsset('website', 'Northwind Website');
        $run = Run::factory()->create([
            'digital_asset_id' => $asset->id,
            'module_id' => 'website',
            'status' => 'completed',
            'finished_at' => now(),
        ]);
        Evidence::factory()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $asset->id,
            'source_module' => 'website',
            'type' => 'gsc_performance_summary',
            'observed_at' => now(),
            'payload' => [
                'response_ok' => true,
                'requested_period' => ['start' => '2026-08-01', 'end' => '2026-08-10'],
                'current' => ['clicks' => 4242, 'impressions' => 9000, 'ctr' => 0.1, 'position' => 4.2],
                'previous' => ['clicks' => 4000, 'impressions' => 8000, 'ctr' => 0.1, 'position' => 4.4],
                'deltas' => ['clicks' => ['percent' => 0.06]],
            ],
        ]);

        Livewire::test(WebsiteOverviewPage::class, ['assetId' => (string) $asset->id])
            ->set('draftPeriodStart', '2026-08-01')
            ->set('draftPeriodEnd', '2026-08-10')
            ->call('applyCustomPeriod')
            ->assertSee('4,242');

        Livewire::test(WebsiteOverviewPage::class, ['assetId' => (string) $asset->id])
            ->call('setPeriod', 'last_7')
            ->assertDontSee('4,242')
            ->assertSee(__('operator.website.period.no_overlap_title'));
    }

    #[Test]
    public function findings_and_recommendations_honor_asset_query_filter(): void
    {
        $one = $this->createPortfolioAsset('website', 'Asset One');
        $two = DigitalAsset::factory()->create([
            'brand_id' => $one->brand_id,
            'type' => 'website',
            'name' => 'Asset Two',
        ]);
        $findingOne = Finding::factory()->create(['digital_asset_id' => $one->id, 'title' => 'Finding One Only']);
        Finding::factory()->create(['digital_asset_id' => $two->id, 'title' => 'Finding Two Only']);
        Recommendation::factory()->create([
            'finding_id' => $findingOne->id,
            'digital_asset_id' => $one->id,
            'title' => 'Rec One Only',
        ]);
        Recommendation::factory()->create([
            'digital_asset_id' => $two->id,
            'title' => 'Rec Two Only',
        ]);

        Livewire::test(FindingsIndex::class, ['asset' => (string) $one->id])
            ->assertSee('Finding One Only')
            ->assertDontSee('Finding Two Only');

        Livewire::test(RecommendationsIndex::class, ['asset' => (string) $one->id])
            ->assertSee('Rec One Only')
            ->assertDontSee('Rec Two Only');
    }

    #[Test]
    public function activity_includes_async_runs_and_team_member_cannot_mutate_settings(): void
    {
        $asset = $this->createPortfolioAsset('website', 'Activity Website');
        $queued = app(AsyncOperationService::class)->queueFindingEvaluation($asset, $this->admin);
        $this->assertTrue($queued['ok'] ?? false);

        $rows = app(ActivityReadService::class)->forList([
            'digital_asset_id' => $asset->id,
            'period' => 'last_7',
        ]);
        $this->assertTrue(collect($rows)->contains(
            fn (array $row): bool => str_contains((string) ($row['title'] ?? ''), 'Finding evaluation')
                || ($row['event'] ?? '') === 'async.finding_evaluation'
        ));

        Livewire::test(ActivityIndex::class, ['asset' => (string) $asset->id])
            ->assertSee(__('operator.activity.title'));

        $member = User::factory()->create(['locale' => 'en']);
        $member->assignRole(Roles::TEAM_MEMBER);
        $this->actingAs($member);

        Livewire::test(SettingsPage::class)
            ->set('agency_name', 'Hijacked Phase E')
            ->call('saveGeneral')
            ->assertForbidden();

        $finding = Finding::factory()->create(['digital_asset_id' => $asset->id]);
        $recommendation = Recommendation::factory()->forFinding($finding)->create([
            'title' => 'Member can convert',
            'status' => 'open',
        ]);
        Livewire::test(RecommendationsIndex::class)
            ->call('createTask', (string) $recommendation->id)
            ->assertHasNoErrors();
        $this->assertTrue(Task::query()->where('recommendation_id', $recommendation->id)->exists());
        $task = Task::query()->where('recommendation_id', $recommendation->id)->firstOrFail();
        $this->assertNull($task->assignee_id);
        $this->assertNotSame(TaskOutcomeStatus::IMPROVEMENT_OBSERVED, $task->outcome_status);
    }

    #[Test]
    public function turkish_locale_localizes_period_and_inbox_chrome(): void
    {
        $this->admin->forceFill(['locale' => 'tr'])->save();
        $this->actingAs($this->admin->fresh());

        $this->get('/recommendations')
            ->assertOk()
            ->assertSee(__('operator.inbox.recommendations_title', [], 'tr'))
            ->assertDontSee('Decision inbox — what MoxDOP thinks is worth considering', false);

        $this->get('/activity')
            ->assertOk()
            ->assertSee(__('operator.activity.title', [], 'tr'));
    }

    #[Test]
    public function production_website_does_not_show_atlas_fixtures(): void
    {
        $asset = $this->createPortfolioAsset('website', 'Northwind Website');

        Livewire::test(WebsiteOverviewPage::class, ['assetId' => (string) $asset->id])
            ->assertSee('Northwind Website')
            ->assertDontSee('Atlas Dental Website')
            ->assertDontSee('Atlas Dental Ankara');
    }
}
