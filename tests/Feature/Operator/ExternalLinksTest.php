<?php

namespace Tests\Feature\Operator;

use App\Livewire\Operator\Portfolio\BrandShow;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Operator\ExternalLinks;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Hızlı giriş" / "Aç ↗" (yakup 2026-10-20): every panel link to a provider's own screen comes from ExternalLinks,
 * built from the stored ids; WordPress goes through the connector's one-click login.
 */
final class ExternalLinksTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->brand = Brand::factory()->create(['name' => 'Atlas Dental']);
    }

    public function test_each_bound_account_links_to_its_own_screen(): void
    {
        $ads = $this->asset('google_ads', 'google_ads', '123-456-7890', 'Atlas Ads');
        $meta = $this->asset('meta_ads', 'meta_ads', 'act_998877', 'Atlas Meta');
        $profile = $this->asset('google_business_profile', 'google_business_profile', 'accounts/1/locations/555', 'Atlas Bornova', ['place_id' => 'ChIJabc']);
        $links = app(ExternalLinks::class);

        $this->assertSame('https://ads.google.com/aw/overview?__e=1234567890', $links->forAsset($ads)[0]['url']);
        $this->assertSame('https://adsmanager.facebook.com/adsmanager/manage/campaigns?act=998877', $links->forAsset($meta)[0]['url']);
        $this->assertSame(['https://www.google.com/maps/place/?q=place_id:ChIJabc', ExternalLinks::GBP_MANAGER], array_column($links->forAsset($profile), 'url'));
        $this->assertSame('https://ads.google.com/aw/adgroups?campaignId=42&__e=1234567890', $links->googleAdsCampaign('123-456-7890', '42')['url']);
        $this->assertSame('https://ads.google.com/aw/keywords?campaignId=42&adGroupId=7&__e=1234567890', $links->googleAdsAdGroup('1234567890', '42', '7')['url']);
        $this->assertSame('https://search.google.com/search-console/performance/search-analytics?resource_id=sc-domain%3Aatlas.com&page=%21https%3A%2F%2Fatlas.com%2Fimplant%2F',
            $links->searchConsolePage('sc-domain:atlas.com', 'https://atlas.com/implant/')['url']);
        $this->assertNull($links->googleAdsAccount(''));
    }

    public function test_a_wordpress_site_signs_in_and_carries_its_search_console_and_analytics(): void
    {
        $site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'status' => 'active', 'module_id' => 'website',
            'name' => 'Atlas', 'domain' => 'atlas.com', 'primary_url' => 'https://www.atlas.com/tr/', 'cms' => 'wordpress']);
        $this->bind($site, 'search_console', 'sc-domain:atlas.com', 'atlas.com');
        $this->bind($site, 'ga4', 'properties/321', 'Atlas GA4');
        $links = app(ExternalLinks::class);

        $this->assertSame(['site:', 'wordpress:', 'search_console:', 'ga4:'], array_map(fn (array $l): string => explode(':', $l['key'])[0].':', $links->forAsset($site)));
        $post = $links->wordPressPost($site, 12);
        $this->assertSame(['site' => $site->id, 'to' => 'post.php?post=12&action=edit'], $post['login']);
        $this->assertSame('https://www.atlas.com/wp-admin/post.php?post=12&action=edit', $post['url']);
        $this->assertSame('https://analytics.google.com/analytics/web/#/p321/reports/intelligenthome', collect($links->forAsset($site))->firstWhere('key', 'ga4:321')['url']);
        $this->assertSame('sc-domain:atlas.com', $links->searchConsolePropertyFor($site->id));

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
        $html = Blade::render('<x-operator.open-link :link="$link" />', ['link' => $post]);
        $this->assertStringContainsString('method="POST"', $html);
        $this->assertStringContainsString('name="to" value="post.php?post=12&amp;action=edit"', $html);

        $member = User::factory()->create(['is_active' => true]);
        $member->assignRole(Roles::TEAM_MEMBER);
        $this->actingAs($member);
        $this->assertStringContainsString('href="https://www.atlas.com/wp-admin/post.php?post=12&amp;action=edit"', Blade::render('<x-operator.open-link :link="$link" />', ['link' => $post]));
    }

    public function test_the_brand_page_groups_links_and_folds_many_profiles_into_one(): void
    {
        $this->asset('google_ads', 'google_ads', '1234567890', 'Atlas Ads');
        foreach (['Bornova', 'Buca', 'Karşıyaka'] as $i => $branch) {
            $this->asset('google_business_profile', 'google_business_profile', 'locations/'.$i, 'Atlas '.$branch, ['place_id' => 'P'.$i]);
        }

        $groups = app(ExternalLinks::class)->forBrand($this->brand);
        $this->assertSame(['Google Ads', 'İşletme profili'], array_column($groups, 'group'));
        $this->assertSame([['İşletme profilleri (3)', ExternalLinks::GBP_MANAGER]], array_map(fn (array $l): array => [$l['label'], $l['url']], $groups[1]['links']));

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
        Livewire::test(BrandShow::class, ['brand' => $this->brand->id])->assertSee('Hızlı giriş')->assertSee('https://ads.google.com/aw/overview?__e=1234567890', false);
    }

    /** @param  array<string, mixed>  $metadata */
    private function asset(string $type, string $capability, string $externalId, string $name, array $metadata = []): DigitalAsset
    {
        $asset = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => $type, 'status' => 'active', 'module_id' => null, 'name' => $name]);
        $this->bind($asset, $capability, $externalId, $name, $metadata);

        return $asset;
    }

    /** @param  array<string, mixed>  $metadata */
    private function bind(DigitalAsset $asset, string $capability, string $externalId, string $name, array $metadata = []): void
    {
        $resource = CoreExternalResource::factory()->create(['resource_type' => $capability, 'external_id' => $externalId, 'display_name' => $name, 'metadata' => $metadata]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id, 'capability' => $capability]);
    }
}
