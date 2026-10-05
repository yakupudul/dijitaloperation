<?php

namespace Tests\Feature\Portfolio;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Services\BrandSetup\BrandSetupMatcher;
use App\Services\Ownership\OwnershipGuard;
use App\Services\Portfolio\UnassignedWebsites;
use App\Support\Integrations\WordPress\WordPressConnectorUrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

/** WordPress sites installed in folders of one host (https://www.kralsoftware.com/newbyangn) are separate websites. */
final class SubfolderWebsiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_key_keeps_the_folder_and_ignores_scheme_www_and_trailing_slash(): void
    {
        $this->assertSame('kralsoftware.com/newbyangn', BrandSetupMatcher::siteKey('https://www.kralsoftware.com/newbyangn/'));
        $this->assertSame('kralsoftware.com/newbyangn', BrandSetupMatcher::siteKey('kralsoftware.com/NewByangn'));
        $this->assertSame('kralsoftware.com', BrandSetupMatcher::siteKey('https://www.kralsoftware.com/'));
        $this->assertSame('kralsoftware.com', BrandSetupMatcher::siteKey('https://kralsoftware.com/index.php?x=1'));
        $this->assertSame('', BrandSetupMatcher::basePath('https://kralsoftware.com'));
    }

    public function test_integrations_add_keeps_the_folder_and_allows_sites_side_by_side_on_one_host(): void
    {
        $websites = app(UnassignedWebsites::class);
        $root = $websites->add('https://www.kralsoftware.com');
        $first = $websites->add('https://www.kralsoftware.com/newbyangn');
        $second = $websites->add('www.kralsoftware.com/baska-site/');

        $this->assertSame('https://kralsoftware.com', $root->primary_url);
        $this->assertSame('https://www.kralsoftware.com/newbyangn', $first->primary_url);
        $this->assertSame('kralsoftware.com', $first->domain);
        $this->assertSame('kralsoftware.com/newbyangn', $first->name);
        $this->assertSame('https://www.kralsoftware.com/baska-site', $second->primary_url);

        $this->assertSame($first->id, $websites->findByHost('kralsoftware.com/newbyangn/')?->id);
        $this->assertSame($root->id, $websites->findByHost('https://www.kralsoftware.com/')?->id);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('kralsoftware.com/newbyangn zaten ekli');
        $websites->add('https://kralsoftware.com/newbyangn/');
    }

    public function test_the_same_folder_is_still_one_website_whatever_screen_saves_it(): void
    {
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        DigitalAsset::query()->create(['brand_id' => null, 'name' => 'kralsoftware.com/newbyangn', 'type' => 'website', 'status' => 'active',
            'module_id' => 'website', 'domain' => 'kralsoftware.com', 'primary_url' => 'https://www.kralsoftware.com/newbyangn']);

        $root = DigitalAsset::query()->create(['brand_id' => $brand->id, 'name' => 'Kral', 'type' => 'website', 'status' => 'active',
            'module_id' => 'website', 'domain' => 'kralsoftware.com', 'primary_url' => 'https://www.kralsoftware.com/']);
        $this->assertNotNull($root->id);
        $this->assertSame($root->id, app(OwnershipGuard::class)->existingWebsite('kralsoftware.com')?->id);

        $this->expectException(ValidationException::class);
        DigitalAsset::query()->create(['brand_id' => $brand->id, 'name' => 'Kopya', 'type' => 'website', 'status' => 'active',
            'module_id' => 'website', 'domain' => 'kralsoftware.com', 'primary_url' => 'https://kralsoftware.com/newbyangn/']);
    }

    public function test_brand_setup_proposes_the_site_with_its_folder(): void
    {
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id]);

        $item = collect(app(BrandSetupMatcher::class)->propose($brand, 'https://www.kralsoftware.com/newbyangn'))->firstWhere('key', 'asset:website');

        $this->assertSame('https://www.kralsoftware.com/newbyangn/', $item['url']);
        $this->assertSame('proposed', $item['status']);
    }

    public function test_connector_pairing_of_a_folder_site_accepts_only_the_wordpress_in_that_folder(): void
    {
        $site = DigitalAsset::query()->create(['brand_id' => null, 'name' => 'kralsoftware.com/newbyangn', 'type' => 'website', 'status' => 'active',
            'module_id' => 'website', 'domain' => 'kralsoftware.com', 'primary_url' => 'https://www.kralsoftware.com/newbyangn']);
        $guard = new WordPressConnectorUrlGuard;

        $guard->assertMatchesAsset($site, [
            'https://www.kralsoftware.com/newbyangn',
            'https://www.kralsoftware.com/newbyangn/',
            'https://www.kralsoftware.com/newbyangn/wp-json/moxdop/v1/status',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $guard->assertMatchesAsset($site, ['https://www.kralsoftware.com/', 'https://www.kralsoftware.com/wp-json/moxdop/v1/status']);
    }
}
