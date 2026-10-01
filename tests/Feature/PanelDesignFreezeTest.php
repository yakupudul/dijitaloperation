<?php

namespace Tests\Feature;

use App\Livewire\Demo\NotificationBell;
use App\Livewire\Demo\Portfolio\CustomerDetail;
use App\Livewire\Operator\Portfolio\BrandShow;
use App\Livewire\Operator\Website\V2\WebsiteScreen;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Support\Demo\DemoMenu;
use App\Support\Demo\DemoState;
use App\Support\OperatorMenu;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesCanonicalPortfolio;
use Tests\TestCase;

/**
 * Milestone 5 — panel design freeze guards.
 */
class PanelDesignFreezeTest extends TestCase
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
        $this->seedCanonicalPortfolio();
        $this->createPortfolioAsset('website', 'Northwind Website');
        $this->createPortfolioAsset('google_business_profile', 'Northwind GBP');
        $this->createPortfolioAsset('google_ads', 'Northwind Ads', ['module_id' => 'google-ads']);
        $this->createPortfolioAsset('meta_ads', 'Northwind Meta', ['module_id' => 'meta-ads']);
        $this->createPortfolioAsset('ga4', 'Northwind GA4', ['module_id' => 'analytics']);
        $this->createPortfolioAsset('gsc', 'Northwind GSC', ['module_id' => 'search-console']);
        $this->createPortfolioAsset('instagram', 'Northwind Instagram');
    }

    public function test_final_operator_sidebar_is_locked(): void
    {
        $routes = collect(DemoMenu::groups())
            ->flatMap(fn (array $group): array => $group['items'])
            ->pluck('route')
            ->values()
            ->all();

        $this->assertSame([
            'operator.dashboard',
            'operator.customers',
            'operator.brands',
            'operator.websites',
            'operator.library.queries',
            'operator.integrations',
            'operator.settings',
        ], $routes);

        $labels = collect(DemoMenu::groups())
            ->flatMap(fn (array $group): array => $group['items'])
            ->pluck('label')
            ->all();

        $this->assertNotContains('Modules', $labels);
        $this->assertNotContains(__('operator.nav.site_connectors'), $labels);

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('href="/system', $html);
        $this->assertStringNotContainsString('href="/admin', $html);
        $this->assertStringNotContainsString('>Modules</', $html);
    }

    public function test_hidden_screens_are_tabs_of_their_sidebar_entry(): void
    {
        $tabs = OperatorMenu::sectionTabs('operator.integrations.discovered');
        $this->assertSame(['/integrations', '/integrations/discovered', '/integrations/wordpress-sites', '/data-center'], array_column($tabs, 'url'));
        $this->assertSame([false, true, false, false], array_column($tabs, 'active'));
        $this->assertSame(['/settings', '/settings/ai-operations', '/library/website-standards', '/library/services', '/settings/users', '/settings/system-health'], array_column(OperatorMenu::sectionTabs('operator.settings.users'), 'url'), 'v2: Ayarlar carries AI işlemleri, Standartlar, Sektör ve hizmet kataloğu, Kullanıcılar, Sistem');
        $this->assertNull(OperatorMenu::sectionTabs('operator.customers'));

        $this->get(route('operator.integrations.discovered'))->assertOk()->assertSee('aria-current="page"', false)->assertSee(route('operator.data-center', absolute: false), false);
        $this->get(route('operator.settings.users'))->assertOk();
        $this->get(route('operator.settings.ai-operations'))->assertOk();
        $this->get(route('operator.library.queries'))->assertOk();
    }

    public function test_customer_primary_ia(): void
    {
        Livewire::test(CustomerDetail::class, ['customerId' => (string) $this->portfolioCustomer->id])
            ->assertSee(__('operator.customer.tabs.overview'))
            ->assertDontSee('Müşteri İlişkisi')
            ->assertSee(__('operator.customer.actions.add_brand'))
            ->assertSee(__('operator.customer.actions.open_files'))
            ->assertDontSee(__('operator.customer.actions.view_activity'));
    }

    public function test_brand_primary_ia_is_locked(): void
    {
        $html = Livewire::withQueryParams(['tab' => 'overview'])->test(BrandShow::class, ['brand' => (string) $this->portfolioBrand->id])->html();

        foreach (['Özet', 'Kurulum', 'İşletme', 'Dijital varlıklar', 'Dosyalar'] as $tab) {
            $this->assertMatchesRegularExpression('/role="tab"[^>]*>'.preg_quote($tab, '/').'(<| )/u', $html);
        }

        preg_match_all('/role="tab"[^>]*wire:click="setTab\\(\'([^\']+)\'\\)"/', $html, $matches);
        $this->assertSame(
            ['ozet', 'arama', 'harita', 'google_ads', 'meta', 'dosya', 'ayarlar', 'settings', 'overview', 'business', 'assets', 'files'],
            $matches[1] ?? [],
            'Faz 11c: Dosyalar moved from the menu to the brand page'
        );
        $this->assertStringNotContainsString('Domain (legacy)', $html);
        $this->assertStringNotContainsString('Hosting (legacy)', $html);
    }

    public function test_specialist_asset_primary_tab_counts(): void
    {
        $byType = DigitalAsset::query()->where('brand_id', $this->portfolioBrand->id)->get()->keyBy('type');

        $cases = [
            // v2 Faz 4a: website screen tabs.
            [route('operator.website', ['assetId' => $byType['website']->id]), array_values(WebsiteScreen::TABS)],
            [route('operator.gbp', ['assetId' => $byType['google_business_profile']->id]), [
                'Genel Bakış',
                'Yapılacaklar',
                'Yorumlar',
                'Gönderiler',
                'Analiz',
                'Ayarlar',
            ]],
            [route('operator.google-ads.overview', ['assetId' => $byType['google_ads']->id]), [
                'Genel Bakış',
                'Yapılacaklar',
                'Arama Terimleri',
                'Kampanya Stratejisi',
                'Ölçümleme',
                'Analiz',
                'Ayarlar',
            ]],
            [route('operator.meta.overview', ['assetId' => $byType['meta_ads']->id]), [
                'Genel Bakış',
                'Yapılacaklar',
                'Kreatifler',
                'Kampanya Stratejisi',
                'Ölçümleme',
                'Analiz',
                'Ayarlar',
            ]],
            [route('operator.analytics', ['assetId' => $byType['ga4']->id]), [
                __('operator.ga4.tabs.overview'),
                __('operator.ga4.tabs.measurement'),
                __('operator.ga4.tabs.acquisition'),
                __('operator.ga4.tabs.behavior'),
                __('operator.ga4.tabs.journeys'),
                __('operator.ga4.tabs.operations'),
            ]],
            [route('operator.search-console', ['assetId' => $byType['gsc']->id]), [
                __('operator.gsc.tabs.overview'),
                __('operator.gsc.tabs.performance'),
                __('operator.gsc.tabs.demand'),
                __('operator.gsc.tabs.pages'),
                __('operator.gsc.tabs.indexing'),
                __('operator.gsc.tabs.operations'),
            ]],
        ];

        foreach ($cases as [$url, $tabs]) {
            $response = $this->get($url)->assertOk();
            foreach ($tabs as $tab) {
                $response->assertSee($tab);
            }
        }
    }

    public function test_notification_bell_has_empty_production_state_without_demo_fallback(): void
    {
        Livewire::test(NotificationBell::class)
            ->assertSee(__('operator.notifications.empty'))
            ->assertDontSee(__('operator.notifications.demo.overdue_review_title'))
            ->assertDontSee(__('operator.notifications.demo.request_title'))
            ->call('markAllRead')
            ->assertSee(__('operator.notifications.empty'));
    }

    public function test_magic_score_labels_remain_absent(): void
    {
        $surfaces = [
            '/',
            route('operator.brand', ['brand' => $this->portfolioBrand->id]),
            route('operator.website', ['assetId' => DigitalAsset::query()->where('type', 'website')->value('id')]),
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
        $this->get(route('operator.assets'))
            ->assertOk()
            ->assertDontSee('>Domain</')
            ->assertDontSee('>Hosting</');

        $this->get(route('operator.domain'))
            ->assertRedirect();

        $this->get(route('operator.hosting'))
            ->assertRedirect();
    }

    public function test_turkish_specialist_tabs_and_ai_settings(): void
    {
        $this->admin->update(['locale' => 'tr']);
        app()->setLocale('tr');

        $this->get(route('operator.gbp', ['assetId' => DigitalAsset::query()->where('type', 'google_business_profile')->value('id')]))
            ->assertOk()
            ->assertSee(__('operator_gbp.page_tabs.reviews', [], 'tr'));

        $this->get(route('operator.settings.ai-operations'))->assertOk();
    }
}
