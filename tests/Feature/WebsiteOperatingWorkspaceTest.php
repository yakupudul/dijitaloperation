<?php

namespace Tests\Feature;

use App\Livewire\Demo\Website\OverviewPage;
use App\Models\User;
use App\Support\Demo\DemoState;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WebsiteOperatingWorkspaceTest extends TestCase
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

    public function test_website_primary_tabs_render(): void
    {
        foreach (['overview', 'health', 'visibility', 'content', 'performance', 'connections', 'activity', 'settings'] as $tab) {
            $this->get(route('demo.website', ['tab' => $tab]))
                ->assertOk()
                ->assertSee('Atlas Dental Website');
        }
    }

    public function test_overview_prescription_surfaces(): void
    {
        Livewire::test(OverviewPage::class)
            ->assertSee('Needs attention')
            ->assertSee('Opportunities')
            ->assertSee('Site inventory')
            ->assertSee('Search & demand')
            ->assertSee('Conversion snapshot')
            ->assertSee('Recent outcomes')
            ->assertSee('AI guidance')
            ->assertDontSee('Website Health')
            ->assertDontSee('SEO Score')
            ->assertDontSee('How is organic search demand moving?');
    }

    public function test_health_finding_detail_and_actionability(): void
    {
        Livewire::test(OverviewPage::class)
            ->call('setTab', 'health')
            ->assertSee('Website health')
            ->assertSee('34 checks evaluated')
            ->assertSee('12 findings open')
            ->assertDontSee('88% Healthy')
            ->call('setSeverity', 'high')
            ->assertSee('27 service pages have no self-referencing canonical')
            ->assertDontSee('Missing Content-Security-Policy header')
            ->call('openFinding', 'wf-canonical-template')
            ->assertSee('Problem')
            ->assertSee('Suggested owner')
            ->assertSee('Developer required')
            ->assertSee('Affected scope')
            ->assertSee('27 pages')
            ->assertSee('Verification');
    }

    public function test_visibility_lenses_and_source_labels(): void
    {
        Livewire::test(OverviewPage::class)
            ->call('setVisLens', 'organic')
            ->assertSee('Search Console · measured')
            ->assertSee('Striking distance')
            ->assertSee('MoxDOP heuristic')
            ->assertSee('DataForSEO · estimated')
            ->assertSee('Potential query overlap')
            ->call('setVisLens', 'local')
            ->assertSee('Local service coverage')
            ->assertSee('not a ranking promise')
            ->assertSee('Open related GBP')
            ->call('setVisLens', 'ai')
            ->assertSee('AI readiness')
            ->assertSee('Observed AI visibility')
            ->assertSee('has not been measured in production')
            ->assertDontSee('AI Rank #');
    }

    public function test_content_roles_inventory_and_gaps(): void
    {
        Livewire::test(OverviewPage::class)
            ->call('setTab', 'content')
            ->assertSee('Treatments')
            ->assertSee('CPT · treatment')
            ->assertSee('Content role')
            ->assertSee('CMS type')
            ->assertSee('Service / Product')
            ->assertSee('page')
            ->assertSee('Content opportunity')
            ->assertSee('Implant recovery expectations')
            ->assertSee('No automatic publish')
            ->set('content_role', 'Blog / Article')
            ->assertSee('Implant care guide')
            ->assertDontSee('Post-Bariatric Dentistry')
            ->call('openContentPage', 'c-implant')
            ->assertSee('Dental Implants in Ankara')
            ->assertSee('Word count (context only)');
    }

    public function test_performance_conversion_mapping_and_no_fake_zero(): void
    {
        Livewire::test(OverviewPage::class)
            ->call('setPerfSub', 'conversions')
            ->assertSee('Conversion mapping')
            ->assertSee('WhatsApp click')
            ->assertSee('Not mapped')
            ->assertSee('Measurement debt')
            ->assertSee('Missing measurement ≠ poor conversion')
            ->call('setPerfSub', 'outcome')
            ->assertSee('Observed after change — not caused by change')
            ->assertSee('cannot prove which query caused');
    }

    public function test_connections_separate_sources_from_related_assets(): void
    {
        Livewire::test(OverviewPage::class)
            ->call('setTab', 'connections')
            ->assertSee('Website data sources')
            ->assertSee('WordPress')
            ->assertSee('Google Search Console')
            ->assertSee('Related digital assets')
            ->assertSee('Independent Brand Digital Assets')
            ->assertSee('Google Ads')
            ->assertSee('Google Business Profile')
            ->assertDontSee('act_demo_secret');
    }

    public function test_legacy_tabs_redirect(): void
    {
        Livewire::test(OverviewPage::class, ['tab' => 'technical'])
            ->assertSet('tab', 'health')
            ->assertSee('Website health');

        Livewire::test(OverviewPage::class, ['tab' => 'search'])
            ->assertSet('tab', 'visibility');

        Livewire::test(OverviewPage::class, ['tab' => 'conversions'])
            ->assertSet('tab', 'performance')
            ->assertSet('perf_sub', 'conversions');
    }

    public function test_header_actions_work(): void
    {
        Livewire::test(OverviewPage::class)
            ->assertSee('Refresh data')
            ->assertSee('Run diagnosis')
            ->call('refreshData')
            ->call('runDiagnosis')
            ->assertSet('tab', 'health');
    }
}
