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
            'operator.flow',
            'operator.repair',
            'operator.content',
            'operator.gbp-desk',
            'operator.google-ads-desk',
            'operator.meta-desk',
            'operator.portfolio',
            'operator.brands',
            'operator.websites',
            'operator.whatsapp',
            'operator.integrations',
            'operator.settings',
        ], $routes, 'WhatsApp shows for admins only (2026-12-01); İşletme gönderileri added with ADR-078, grown into İşletme profilleri with ADR-079; Meta reklamları (Meta masası) and Kazananlar added with the Meta plan; Google Ads menu added 2026-10-19; Stratejiler added 2026-10-12; Onarım masası added with the repair plan 2026-10-08 and, since 2026-10-10, the one work list for everyone (Genel işler is its Diğer işler section); Müşteriler merged into Markalar 2026-10-19; Sorgular under Büyüme and WhatsApp under Portföy with the menu prototype 2026-10-20; İçerik added and Dijital varlıklar moved under Entegrasyonlar 2026-10-20 (yönetici turu); Akış added 2026-10-22 (Aşama 4); Portföy added and Bugün became Komuta 2026-10-22 (Aşama 5); Kazananlar, Stratejiler and Sorgular left the menu 2026-10-22 (Aşama 11)');

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
        $this->assertSame(['/integrations', '/integrations/connection-health', '/integrations/discovered', '/integrations/wordpress-sites', '/assets'], array_column($tabs, 'url'), 'Bağlantı sağlığı added with the repair plan 2026-10-08; Dijital varlıklar moved here from the sidebar 2026-10-20; Veri merkezi left 2026-10-22 (Aşama 11)');
        $this->assertSame([false, false, true, false, false], array_column($tabs, 'active'));
        $this->assertSame(['/settings', '/settings/ai-operations', '/library/website-standards', '/library/services', '/settings/query-rules', '/settings/users', '/settings/mcp', '/settings/system-health', '/settings/improvements', '/settings/releases'], array_column(OperatorMenu::sectionTabs('operator.settings.users'), 'url'), 'v2: Ayarlar carries AI işlemleri, Standartlar, Sektör ve hizmet kataloğu, Sorgu kuralları, Kullanıcılar, MCP anahtarları, Sistem, Geliştirme havuzu, Sürümler');
        $this->assertNull(OperatorMenu::sectionTabs('operator.customers'));

        $this->get(route('operator.integrations.discovered'))->assertOk()->assertSee('aria-current="page"', false)->assertDontSee(route('operator.data-center', absolute: false).'"', false);
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

        // Aşama 6 (2026-10-22): no tab row; Marka ayarları in the head, the other views under "Diğer".
        $this->assertStringNotContainsString('role="tablist"', $html);
        $this->assertStringContainsString('data-brand-settings-link', $html);
        preg_match_all('/role="menuitem" wire:click="setTab\\(\'([^\']+)\'\\)"/', $html, $matches);
        $this->assertSame(['varliklar', 'karne', 'dosya'], $matches[1] ?? [], 'Dijital varlıklar, Hizmet karnesi and Epikriz open from Diğer');
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
