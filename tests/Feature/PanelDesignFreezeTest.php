<?php

namespace Tests\Feature;

use App\Livewire\Demo\NotificationBell;
use App\Livewire\Demo\Portfolio\BrandShow;
use App\Livewire\Demo\Portfolio\CustomerDetail;
use App\Livewire\Demo\Settings\AiAgentsPage;
use App\Livewire\Demo\Settings\AiSkillsPage;
use App\Livewire\Demo\SettingsPage;
use App\Models\User;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoMenu;
use App\Support\Demo\DemoState;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Milestone 5 — panel design freeze guards.
 */
class PanelDesignFreezeTest extends TestCase
{
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
    }

    public function test_final_operator_sidebar_is_locked(): void
    {
        $routes = collect(DemoMenu::groups())
            ->flatMap(fn (array $group): array => $group['items'])
            ->pluck('route')
            ->values()
            ->all();

        $this->assertSame([
            'demo.dashboard',
            'demo.customers',
            'demo.brands',
            'demo.assets',
            'demo.files',
            'demo.opportunities',
            'demo.findings',
            'demo.recommendations',
            'demo.tasks',
            'demo.activity',
            'demo.integrations',
            'demo.settings',
        ], $routes);

        $labels = collect(DemoMenu::groups())
            ->flatMap(fn (array $group): array => $group['items'])
            ->pluck('label')
            ->all();

        $this->assertNotContains('Modules', $labels);
        $this->assertNotContains(__('operator.nav.site_connectors'), $labels);

        $html = $this->get('/app')->assertOk()->getContent();
        $this->assertStringNotContainsString('href="/system', $html);
        $this->assertStringNotContainsString('href="/admin', $html);
        $this->assertStringNotContainsString('>Modules</', $html);
    }

    public function test_customer_primary_ia(): void
    {
        Livewire::test(CustomerDetail::class, ['customerId' => DemoCatalog::CUSTOMER_ID])
            ->assertSee(__('operator.customer.tabs.overview'))
            ->assertSee(__('operator.customer.tabs.brands'))
            ->assertSee(__('operator.customer.tabs.relationship'))
            ->assertSee(__('operator.customer.tabs.requests'))
            ->assertSee(__('operator.customer.tabs.reports'))
            ->assertSee(__('operator.customer.actions.open_files'))
            ->assertSee(__('operator.customer.actions.view_activity'))
            ->assertSee(__('operator.customer.actions.open_work'));
    }

    public function test_brand_primary_ia_exactly_six(): void
    {
        $html = Livewire::test(BrandShow::class, ['brand' => DemoCatalog::BRAND_ID])->html();

        foreach ([
            __('operator.brand.tabs.overview'),
            __('operator.brand.tabs.business'),
            __('operator.brand.tabs.estate'),
            __('operator.brand.tabs.growth'),
            __('operator.brand.tabs.operations'),
            __('operator.brand.tabs.value'),
        ] as $tab) {
            $this->assertStringContainsString('>'.$tab.'</button>', $html);
        }

        preg_match_all('/role="tab"[^>]*wire:click="setTab\\(\'([^\']+)\'\\)"/', $html, $matches);
        $this->assertSame(
            ['overview', 'business', 'estate', 'growth', 'operations', 'value'],
            $matches[1] ?? []
        );
        $this->assertStringNotContainsString('Domain (legacy)', $html);
        $this->assertStringNotContainsString('Hosting (legacy)', $html);
    }

    public function test_specialist_asset_primary_tab_counts(): void
    {
        $cases = [
            [route('demo.website'), [
                __('operator.website.tabs.overview'),
                __('operator.website.tabs.health'),
                __('operator.website.tabs.visibility'),
                __('operator.website.tabs.content'),
                __('operator.website.tabs.performance'),
                __('operator.website.tabs.infrastructure'),
                __('operator.website.tabs.operations'),
                __('operator.website.tabs.setup'),
            ]],
            [route('demo.gbp'), [
                __('operator.gbp.tabs.overview'),
                __('operator.gbp.tabs.profile'),
                __('operator.gbp.tabs.visibility'),
                __('operator.gbp.tabs.performance'),
                __('operator.gbp.tabs.reviews'),
                __('operator.gbp.tabs.competitors'),
                __('operator.gbp.tabs.operations'),
            ]],
            [route('demo.google-ads.overview'), [
                __('operator.google_ads.tabs.overview'),
                __('operator.google_ads.tabs.campaigns'),
                __('operator.google_ads.tabs.search_demand'),
                __('operator.google_ads.tabs.ads_assets'),
                __('operator.google_ads.tabs.landing_pages'),
                __('operator.google_ads.tabs.measurement'),
                __('operator.google_ads.tabs.operations'),
            ]],
            [route('demo.meta.overview'), [
                __('operator.meta_ads.tabs.overview'),
                __('operator.meta_ads.tabs.campaigns'),
                __('operator.meta_ads.tabs.creatives'),
                __('operator.meta_ads.tabs.audience'),
                __('operator.meta_ads.tabs.funnel'),
                __('operator.meta_ads.tabs.measurement'),
                __('operator.meta_ads.tabs.operations'),
            ]],
            [route('demo.analytics'), [
                __('operator.ga4.tabs.overview'),
                __('operator.ga4.tabs.measurement'),
                __('operator.ga4.tabs.acquisition'),
                __('operator.ga4.tabs.behavior'),
                __('operator.ga4.tabs.journeys'),
                __('operator.ga4.tabs.operations'),
            ]],
            [route('demo.search-console'), [
                __('operator.gsc.tabs.overview'),
                __('operator.gsc.tabs.performance'),
                __('operator.gsc.tabs.demand'),
                __('operator.gsc.tabs.pages'),
                __('operator.gsc.tabs.indexing'),
                __('operator.gsc.tabs.operations'),
            ]],
            [route('demo.instagram'), [
                __('operator.instagram.tabs.overview'),
                __('operator.instagram.tabs.profile'),
                __('operator.instagram.tabs.operations'),
                __('operator.instagram.tabs.setup'),
            ]],
        ];

        foreach ($cases as [$url, $tabs]) {
            $response = $this->get($url)->assertOk();
            foreach ($tabs as $tab) {
                $response->assertSee($tab);
            }
        }
    }

    public function test_ai_administration_is_self_sufficient_inside_app(): void
    {
        $this->get(route('demo.settings.ai.agents'))
            ->assertOk()
            ->assertSee(__('operator.settings.ai.agents_title'));

        $this->get(route('demo.settings.ai.skills'))
            ->assertOk()
            ->assertSee(__('operator.settings.ai.skills_title'));

        $agentsHtml = Livewire::test(AiAgentsPage::class)->html();
        $skillsHtml = Livewire::test(AiSkillsPage::class)->html();
        $settingsHtml = Livewire::test(SettingsPage::class, ['section' => 'ai'])->html();

        foreach ([$agentsHtml, $skillsHtml, $settingsHtml] as $html) {
            $this->assertStringNotContainsString('href="/system', $html);
            $this->assertStringNotContainsString('href="/admin', $html);
        }

        $this->assertStringContainsString('allowed', strtolower($agentsHtml));
        $this->assertStringContainsString('forbidden', strtolower($skillsHtml));
    }

    public function test_notification_bell_has_deterministic_demo_state(): void
    {
        Livewire::test(NotificationBell::class)
            ->assertSee(__('operator.notifications.demo.overdue_review_title'))
            ->assertSee(__('operator.notifications.demo.request_title'))
            ->call('markAllRead')
            ->assertDontSee(__('operator.notifications.empty'));
    }

    public function test_magic_score_labels_remain_absent(): void
    {
        $surfaces = [
            '/app',
            route('demo.brand', ['brand' => DemoCatalog::BRAND_ID]),
            route('demo.website'),
            route('demo.opportunities'),
            route('demo.tasks'),
        ];

        $forbidden = [
            'Website Health Score',
            'Brand Health Score',
            'Agency Score',
            'Growth Score',
            'Opportunity Score',
            'Value Score',
            'Lead Quality Score',
            'Capacity Score',
            'AI Confidence Score',
            'Client Success Score',
        ];

        foreach ($surfaces as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            foreach ($forbidden as $label) {
                $this->assertStringNotContainsString($label, $html, "Found {$label} on {$url}");
            }
        }
    }

    public function test_domain_and_hosting_are_not_standalone_operator_assets(): void
    {
        $this->get(route('demo.assets'))
            ->assertOk()
            ->assertDontSee('>Domain</')
            ->assertDontSee('>Hosting</');

        $this->get(route('demo.domain'))
            ->assertRedirect();

        $this->get(route('demo.hosting'))
            ->assertRedirect();
    }

    public function test_turkish_specialist_tabs_and_ai_settings(): void
    {
        $this->admin->update(['locale' => 'tr']);
        app()->setLocale('tr');

        $this->get(route('demo.gbp'))
            ->assertOk()
            ->assertSee(__('operator.gbp.tabs.competitors', [], 'tr'));

        $this->get(route('demo.settings.ai.agents'))
            ->assertOk()
            ->assertSee(__('operator.settings.ai.agents_title', [], 'tr'));
    }
}
