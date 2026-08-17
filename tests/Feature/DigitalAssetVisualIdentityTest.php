<?php

namespace Tests\Feature;

use App\Livewire\Demo\Assets\AnalyticsPage;
use App\Livewire\Demo\Assets\SearchConsolePage;
use App\Livewire\Demo\Gbp\OverviewPage as GbpOverviewPage;
use App\Livewire\Demo\GoogleAds\OverviewPage as GoogleAdsOverviewPage;
use App\Livewire\Demo\Meta\OverviewPage as MetaOverviewPage;
use App\Livewire\Demo\Portfolio\AssetsIndex;
use App\Livewire\Demo\Portfolio\BrandShow;
use App\Livewire\Demo\Website\OverviewPage as WebsiteOverviewPage;
use App\Models\User;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use App\Support\DigitalAssetVisualCatalog;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\Support\CreatesCanonicalPortfolio;
use Tests\TestCase;

class DigitalAssetVisualIdentityTest extends TestCase
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
    }

    public function test_catalog_maps_canonical_types_to_local_marks(): void
    {
        $pairs = [
            'website' => 'website-atlas',
            'google_ads' => 'google-ads',
            'meta_ads' => 'meta',
            'gbp' => 'gbp',
            'ga4' => 'ga4',
            'gsc' => 'gsc',
            'instagram' => 'instagram',
            'domain' => 'globe',
            'hosting' => 'server',
        ];

        foreach ($pairs as $type => $mark) {
            $entry = DigitalAssetVisualCatalog::forType($type);
            $this->assertSame(DigitalAssetVisualCatalog::normalizeType($type), $entry['type']);
            $this->assertSame($mark, $entry['mark']);
            $this->assertStringContainsString('/images/digital-assets/'.$mark.'.svg', $entry['asset_path']);
            $this->assertTrue(File::exists(public_path('images/digital-assets/'.$mark.'.svg')), $mark.' SVG missing');
            $this->assertStringNotContainsString('https://cdn.', $entry['asset_path']);
        }

        $this->assertSame('ga4', DigitalAssetVisualCatalog::normalizeType('google_analytics'));
        $this->assertSame('ga4', DigitalAssetVisualCatalog::normalizeType('analytics'));
        $this->assertSame('Google Analytics', DigitalAssetVisualCatalog::forType('ga4')['label']);
        $this->assertSame('Google Ads', DigitalAssetVisualCatalog::forType('google_ads')['label']);
        $this->assertNotSame(
            DigitalAssetVisualCatalog::forType('ga4')['mark'],
            DigitalAssetVisualCatalog::forType('google_ads')['mark'],
        );
        $this->assertNotSame(
            DigitalAssetVisualCatalog::forType('meta_ads')['mark'],
            DigitalAssetVisualCatalog::forType('instagram')['mark'],
        );
    }

    public function test_website_resolve_prefers_brand_fixture_mark_on_catalog_payload(): void
    {
        $website = DemoCatalog::asset(DemoCatalog::WEBSITE_ASSET_ID);
        $this->assertNotNull($website);
        $visual = DigitalAssetVisualCatalog::resolve($website);
        $this->assertSame('website-atlas', $visual['mark']);
        $this->assertSame('brand_logo_fixture', $visual['source']);
        $this->assertStringContainsString('website-atlas.svg', $visual['asset_path']);
    }

    public function test_provider_marks_render_on_real_asset_headers_and_directory(): void
    {
        $this->seedCanonicalPortfolio();
        $ga4 = $this->createPortfolioAsset('ga4', 'Northwind GA4', ['module_id' => 'analytics']);
        $gsc = $this->createPortfolioAsset('gsc', 'Northwind GSC', ['module_id' => 'search-console']);
        $gads = $this->createPortfolioAsset('google_ads', 'Northwind Ads', ['module_id' => 'google-ads']);
        $meta = $this->createPortfolioAsset('meta_ads', 'Northwind Meta', ['module_id' => 'meta-ads']);
        $gbp = $this->createPortfolioAsset('google_business_profile', 'Northwind GBP');
        $website = $this->createPortfolioAsset('website', 'Northwind Website');

        Livewire::test(AnalyticsPage::class, ['assetId' => (string) $ga4->id])
            ->assertSee('data-asset-mark="ga4"', false)
            ->assertSee('images/digital-assets/ga4.svg', false);

        Livewire::test(SearchConsolePage::class, ['assetId' => (string) $gsc->id])
            ->assertSee('data-asset-mark="gsc"', false)
            ->assertSee('images/digital-assets/gsc.svg', false);

        Livewire::test(GoogleAdsOverviewPage::class, ['assetId' => (string) $gads->id])
            ->assertSee('data-asset-mark="google_ads"', false)
            ->assertSee('images/digital-assets/google-ads.svg', false);

        Livewire::test(MetaOverviewPage::class, ['assetId' => (string) $meta->id])
            ->assertSee('data-asset-mark="meta_ads"', false)
            ->assertSee('images/digital-assets/meta.svg', false);

        Livewire::test(GbpOverviewPage::class, ['assetId' => (string) $gbp->id])
            ->assertSee('data-asset-mark="gbp"', false)
            ->assertSee('images/digital-assets/gbp.svg', false);

        Livewire::test(WebsiteOverviewPage::class, ['assetId' => (string) $website->id])
            ->assertSee('data-asset-mark="website"', false);

        Livewire::test(AssetsIndex::class)
            ->assertSee('data-asset-mark="ga4"', false)
            ->assertSee('data-asset-mark="gsc"', false)
            ->assertSee('data-asset-mark="google_ads"', false)
            ->assertSee('data-asset-mark="meta_ads"', false)
            ->assertSee('data-asset-mark="website"', false);

        Livewire::test(BrandShow::class, ['brand' => (string) $this->portfolioBrand->id])
            ->assertSee('Digital estate')
            ->call('setTab', 'assets')
            ->assertSee('Northwind GA4')
            ->assertSee('Northwind GSC')
            ->assertDontSee('Atlas Dental — GA4');
    }

    public function test_broken_logo_fallback_markup_is_present(): void
    {
        $html = view('components.demo.digital-asset-mark', [
            'type' => 'website',
            'size' => 'md',
        ])->render();

        $this->assertStringContainsString('onerror=', $html);
        $this->assertStringContainsString('WEB', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
        $this->assertStringContainsString('h-8 w-8', $html);

        $lg = view('components.demo.digital-asset-mark', [
            'type' => 'ga4',
            'size' => 'lg',
            'decorative' => false,
        ])->render();
        $this->assertStringContainsString('h-11 w-11', $lg);
        $this->assertStringContainsString('aria-label="Google Analytics"', $lg);
        $this->assertStringContainsString('dark:bg-white/95', $lg);
    }
}
