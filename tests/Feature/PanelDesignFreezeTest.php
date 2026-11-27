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
use Illuminate\Support\Facades\Cache;
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

        // Arayüz kararı (yakup 2026-11-17): the menu is four entries, Bugün · Markalar · Moximu · Sistem. Every former entry
        // (Takvim, Finans, Gelen kutusu, Pazarlama, Onarım masası, İçerik, Bağlantılar, the three channel desks, Ayarlar) is
        // a tab of one of the four; no screen was deleted.
        $this->assertSame(['operator.dashboard', 'operator.brands', 'operator.sales', 'operator.integrations'], $routes);
        $children = collect(DemoMenu::groups())->flatMap(fn (array $group): array => $group['items'])->flatMap(fn (array $item): array => array_column($item['children'], 'route'))->all();
        foreach (['operator.komuta', 'operator.calendar', 'operator.finance', 'operator.inbox', 'operator.marketing', 'operator.social', 'operator.repair', 'operator.content', 'operator.links',
            'operator.gbp-desk', 'operator.google-ads-desk', 'operator.meta-desk', 'operator.settings', 'operator.settings.jobs'] as $route) {
            $this->assertContains($route, $children, $route.' stays reachable as a tab');
        }

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

    public function test_moximu_group_links_the_agency_brand_and_its_site(): void
    {
        app()->setLocale('tr');
        $this->portfolioBrand->update(['name' => 'Moximu']);
        Cache::forget('demo-menu.agency-brand');
        $site = DigitalAsset::query()->where('type', 'website')->where('brand_id', $this->portfolioBrand->id)->value('id');

        // Arayüz kararı 2026-11-17: Moximu is one entry; Satış, Finans, Gelen kutusu, Pazarlama, Sosyal medya and the
        // agency's own site and brand card are its tabs.
        $tabs = OperatorMenu::sectionTabs('operator.sales');
        $this->assertSame(['Satış', 'Finans', 'Gelen kutusu', 'Pazarlama', 'Sosyal medya', 'Web sitemiz', 'Marka kartımız'], array_column($tabs, 'label'));
        $this->assertSame('/assets/website/'.$site, $tabs[5]['url']);
        $this->assertSame('/brands/'.$this->portfolioBrand->id, $tabs[6]['url']);

        $active = fn (string $path, string $route): ?string => collect(OperatorMenu::groups($path, $route))->flatMap(fn (array $g): array => $g['items'])->firstWhere('active', true)['name'] ?? null;
        $this->assertSame('Moximu', $active('/finans', 'operator.finance'));
        $this->assertSame('Markalar', $active('/assets/website/999', 'operator.website'));
        $this->assertSame('Bugün', $active('/onay', 'operator.komuta'));
        $this->get(route('operator.sales'))->assertOk()->assertSee('/brands/'.$this->portfolioBrand->id, false);
    }

    public function test_hidden_screens_are_tabs_of_their_sidebar_entry(): void
    {
        $tabs = OperatorMenu::sectionTabs('operator.integrations.accounts');
        $this->assertSame(['/integrations', '/integrations/accounts', '/integrations/connection-health', '/assets'], array_slice(array_column($tabs, 'url'), 0, 4), 'Bağlantı sağlığı added with the repair plan 2026-10-08; Dijital varlıklar moved here from the sidebar 2026-10-20; Veri merkezi left 2026-10-22 (Aşama 11); Hesaplar added 2026-10-22 (Aşama 6); Keşfedilen varlıklar and WordPress siteleri folded into Hesaplar and Web siteleri 2026-11-01');
        $this->assertSame([false, true, false, false], array_slice(array_column($tabs, 'active'), 0, 4));
        $settingsTabs = collect(OperatorMenu::sectionTabs('operator.settings.users'));
        $this->assertSame([
            ['/settings', '/settings/users', '/settings/roles', '/settings/panel-users'],
            ['/library/website-standards', '/library/services', '/settings/query-rules', '/settings/books'],
            ['/settings/ai', '/settings/ai-operations', '/settings/mcp', '/settings/security', '/settings/system-health', '/settings/jobs', '/settings/changes', '/settings/improvements', '/settings/releases'],
        ], $settingsTabs->groupBy('group')->map(fn ($tabs): array => $tabs->pluck('url')->all())->values()->slice(1)->values()->all(), 'Bağlantı Düzeni 2.3 (2026-11-11): Ayarlar in three groups, Ajans / Kurallar / Makine; Roller and Müşteri paneli joined Ajans with the client panel (2026-11-20)');
        // Müşteriler is a Markalar tab again (yakup 2026-11-27: "müşteriler alanına menüden ulaşamıyorum").
        $customerTabs = OperatorMenu::sectionTabs('operator.customers');
        $this->assertSame('/customers', collect($customerTabs)->firstWhere('active', true)['url'] ?? null);
        $this->assertSame(['/brands', '/portfoy', '/websites'], array_slice(array_column(OperatorMenu::sectionTabs('operator.portfolio'), 'url'), 0, 3), 'Portföy and Web siteleri are tabs of Markalar since 2026-11-09');

        $this->get(route('operator.integrations.accounts'))->assertOk()->assertSee('aria-current="page"', false)->assertDontSee('/data-center"', false);
        $this->get(route('operator.settings.users'))->assertOk();
        $this->get(route('operator.settings.ai-operations'))->assertOk();
        $this->get(route('operator.library.queries'))->assertOk();
    }

    public function test_customer_primary_ia(): void
    {
        Livewire::test(CustomerDetail::class, ['customerId' => (string) $this->portfolioCustomer->id])
            ->assertDontSee('role="tablist"', false)
            ->assertSee('Markalar')
            ->assertDontSee('Müşteri İlişkisi')
            ->assertSee('Marka ekle')
            ->assertSee('Dosyalar')
            ->assertDontSee(__('operator.customer.actions.view_activity'));
    }

    public function test_brand_primary_ia_is_locked(): void
    {
        $html = Livewire::withQueryParams(['tab' => 'overview'])->test(BrandShow::class, ['brand' => (string) $this->portfolioBrand->id])->html();

        // Admin audit 2026-11-07 (T1): one flat tab row, Genel · Varlıklar · Hizmetler · Strateji · Epikriz · Ayarlar; no Diğer menu.
        $this->assertStringContainsString('role="tablist"', $html);
        $this->assertStringContainsString('data-brand-settings-link', $html);
        $this->assertStringNotContainsString('data-brand-more', $html);
        preg_match_all('/role="tab" wire:click="setTab\\(\'([^\']+)\'\\)"/', $html, $matches);
        $this->assertSame(['ozet', 'varliklar', 'karne', 'strateji', 'dosya', 'ayarlar'], $matches[1] ?? [], 'the brand page tab row');
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
                'Kampanyalar',
                'Analiz',
                'Yapılacaklar',
                'Ölçümleme',
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
