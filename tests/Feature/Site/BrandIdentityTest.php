<?php

namespace Tests\Feature\Site;

use App\Livewire\Operator\Portfolio\BrandIdentityCard;
use App\Models\BrandExpert;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\Run;
use App\Services\Brand\BrandIdentity;
use App\Services\Website\Pages\PageStore;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Kimlik tutarlılığı (yakup, 2026-10-07): the brand's Business Profiles are compared with its site by rules; each row
 * says what differs, the site schema's missing profile links are listed to paste into the SEO plugin.
 */
final class BrandIdentityTest extends SiteTestCase
{
    public function test_phone_address_link_and_profiles_are_compared_with_the_site(): void
    {
        $this->profile('Panorama Ankara Çankaya', '+90 312 444 55 66', ['addressLines' => ['Kızılırmak Mahallesi Ufuk Üniversitesi Caddesi No: 5'], 'locality' => 'Çankaya'], 'https://www.panorama.com.tr/?utm_source=gbp');
        $this->profile('Gülüş Kliniği', '0312 999 88 77', ['addressLines' => ['Atatürk Bulvarı No: 10'], 'locality' => 'Ankara'], 'https://baska-site.com/');
        DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'instagram', 'name' => 'panoramaankara', 'primary_url' => null, 'status' => 'active']);
        Page::query()->create(['website_asset_id' => $this->site->id, 'url' => 'https://panorama.com.tr/iletisim/', 'url_hash' => PageStore::urlHash('https://panorama.com.tr/iletisim/'), 'path' => '/iletisim/',
            'title' => 'İletişim', 'content_text' => 'Bize ulaşın: 0 (312) 444 55 66. Adres: Kızılırmak Mah. Ufuk Üniversitesi Cad. No:5 Çankaya / Ankara']);

        $rows = collect(app(BrandIdentity::class)->for($this->brand)['rows'])->keyBy('key');
        $this->assertSame('warn', $rows['name']['state'], 'a profile under another name');
        $this->assertStringContainsString('Gülüş Kliniği', $rows['name']['detail']);
        $this->assertSame('warn', $rows['phone']['state']);
        $this->assertStringContainsString('0312 999 88 77', $rows['phone']['detail']);
        $this->assertStringNotContainsString('444 55 66 sitede geçmiyor', $rows['phone']['detail'], 'the same number written differently matches');
        $this->assertStringContainsString('Atatürk Bulvarı', $rows['address']['detail']);
        $this->assertStringNotContainsString('Kızılırmak', $rows['address']['detail'], 'abbreviations do not hide a matching address');
        $this->assertStringContainsString('baska-site.com', $rows['website']['detail']);
        $this->assertStringNotContainsString('Çankaya (', $rows['website']['detail'], 'www and UTM are the same site');
        $this->assertSame('warn', $rows['same_as']['state']);
        $this->assertSame('warn', $rows['expert']['state']);

        BrandExpert::query()->create(['brand_id' => $this->brand->id, 'name' => 'Dt. Ayşe', 'wp_author' => 'ayse', 'profile_url' => 'https://panorama.com.tr/ekip/ayse/', 'is_default' => true]);
        Livewire::test(BrandIdentityCard::class, ['brandId' => $this->brand->id])
            ->assertSeeHtml('data-brand-identity')->assertSeeHtml('data-identity-row="expert" data-identity-state="warn"')
            ->call('recheck')->assertSeeHtml('data-identity-row="expert" data-identity-state="ok"')
            ->assertSeeHtml('data-identity-same-as')->assertSee('https://www.instagram.com/panoramaankara/')->assertSee('https://maps.google.com/?cid=1');
    }

    public function test_the_home_page_schema_and_tel_links_are_read(): void
    {
        $facts = BrandIdentity::fromHtml('<html><head><script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"Dentist","name":"Panorama Ankara","telephone":"+90 312 444 55 66",'
            .'"address":{"@type":"PostalAddress","streetAddress":"Kızılırmak Mah.","addressLocality":"Çankaya"},"sameAs":["https://www.instagram.com/panoramaankara/"]},{"@type":"WebPage","name":"Ana sayfa"}]}</script></head>'
            .'<body><a href="tel:4441234">Çağrı</a></body></html>');

        $this->assertSame(['Panorama Ankara'], $facts['names'], 'only business nodes give the name');
        $this->assertSame(['3124445566', '4441234'], $facts['phones']);
        $this->assertSame(['https://www.instagram.com/panoramaankara/'], $facts['same_as']);
        $this->assertStringContainsString('Çankaya', $facts['addresses'][0]);
    }

    /** @param  array<string, mixed>  $address */
    private function profile(string $title, string $phone, array $address, string $website): void
    {
        static $n = 0;
        $n++;
        $asset = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_business_profile', 'status' => 'active', 'name' => $title]);
        $resource = CoreExternalResource::factory()->create(['provider' => 'google', 'resource_type' => 'google_business_profile', 'external_id' => 'locations/'.$n]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id, 'capability' => 'google_business_profile', 'status' => CoreAssetBinding::STATUS_ACTIVE]);
        $binding = CoreAssetBinding::query()->where('digital_asset_id', $asset->id)->sole();
        $runId = Run::query()->create(['digital_asset_id' => $asset->id, 'core_asset_binding_id' => $binding->id, 'module_id' => 'google-business-profile', 'status' => 'completed', 'started_at' => now(), 'finished_at' => now()])->id;
        DB::table('gbp_location_snapshots')->insert(['digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id, 'run_id' => $runId, 'location_name' => 'locations/'.$n, 'title' => $title,
            'website_uri' => $website, 'maps_uri' => 'https://maps.google.com/?cid='.$n, 'phone_numbers' => json_encode(['primaryPhone' => $phone]), 'storefront_address' => json_encode($address),
            'captured_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
