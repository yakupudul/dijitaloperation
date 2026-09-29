<?php

namespace Tests\Feature\Ownership;

use App\Ai\Agents\BrandServiceAgent;
use App\Enums\CustomerStatus;
use App\Livewire\Demo\Dashboard;
use App\Livewire\Operator\Portfolio\BrandSettings;
use App\Livewire\Operator\Portfolio\BrandShow;
use App\Models\Brand;
use App\Models\BrandMemory;
use App\Models\BrandOffering;
use App\Models\BrandServiceArea;
use App\Models\BrandServiceCandidate;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\Catalog\ServiceCatalogService;
use App\Services\Portfolio\BrandServiceExtractor;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Faz 2: brand settings (sector, service areas with physical branch, offerings priority, notes, assets) and
 * "Sayfalardan hizmet çıkar" (exclusions, one AI call, catalog link, approval → locked offering), Bugün list.
 */
final class BrandServicesAndSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Brand $brand;

    private DigitalAsset $site;

    private ServiceCategory $dental;

    private ServiceCatalogItem $implant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        $this->dental = ServiceCategory::query()->firstOrCreate(['code' => 'dental'], ['name' => 'Diş sağlığı', 'normalized_key' => 'dis sagligi']);
        $this->implant = app(ServiceCatalogService::class)->resolveOrCreate('Diş İmplantı', 'dental', actor: $this->admin)['service'];
        $customer = Customer::factory()->create(['status' => CustomerStatus::Active]);
        $this->brand = Brand::factory()->create(['customer_id' => $customer->id, 'name' => 'Panorama Ankara', 'sector_id' => $this->dental->id]);
        $this->site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'name' => 'panorama.com.tr', 'domain' => 'panorama.com.tr', 'primary_url' => 'https://panorama.com.tr/']);
        Http::preventStrayRequests();
    }

    public function test_service_pages_exclude_home_about_blog_contact_legal_and_category_pages(): void
    {
        $pages = $this->pages();

        $kept = app(BrandServiceExtractor::class)->candidatePages($this->brand)->pluck('path')->all();

        $this->assertEqualsCanonicalizing(['/implant/', '/en/dental-implant/', '/zirkonyum-kaplama/', '/hizmetlerimiz/gulus-tasarimi/'], $kept);
        $this->assertNotContains($pages['blog']->path, $kept);
    }

    public function test_extraction_proposes_services_with_catalog_link_and_approval_creates_locked_offerings(): void
    {
        $this->enableAi();
        $pages = $this->pages();
        $prompts = [];
        BrandServiceAgent::fake(function (string $prompt) use (&$prompts, $pages): array {
            $prompts[] = $prompt;

            return [
                'services' => [
                    ['name' => 'Diş İmplantı', 'catalog_item_id' => $this->implant->id, 'page_ids' => [$pages['implant']->id, $pages['implantEn']->id]],
                    ['name' => 'Zirkonyum Kaplama', 'catalog_item_id' => 987654, 'page_ids' => [$pages['zirkonyum']->id]],
                    ['name' => 'Gülüş Tasarımı', 'catalog_item_id' => null, 'page_ids' => [$pages['gulus']->id]],
                    ['name' => 'Uydurma Hizmet', 'catalog_item_id' => null, 'page_ids' => [$pages['blog']->id, 424242]],
                ],
                'prompt_version' => BrandServiceAgent::PROMPT_VERSION,
            ];
        });

        $result = app(BrandServiceExtractor::class)->extract($this->brand);

        $this->assertSame(['status' => 'ready', 'added' => 3], $result);
        $this->assertCount(1, $prompts, 'one AI call');
        $this->assertStringNotContainsString('/blog/', $prompts[0], 'excluded pages are not sent');
        $proposals = BrandServiceCandidate::query()->get()->keyBy('name');
        $this->assertSame($this->implant->id, $proposals['Diş İmplantı']->service_catalog_item_id);
        $this->assertTrue($proposals['Zirkonyum Kaplama']->new_catalog_item, 'an unknown catalog id is not trusted');
        $this->assertArrayNotHasKey('Uydurma Hizmet', $proposals->all(), 'a service no given page shows is dropped');

        $settings = Livewire::test(BrandSettings::class, ['brandId' => $this->brand->id])
            ->assertSee('Diş İmplantı')->assertSee('Yeni katalog')
            ->set('proposalPriority.'.$proposals['Diş İmplantı']->id, 'main')
            ->call('approveProposal', $proposals['Diş İmplantı']->id)
            ->set('proposalNames.'.$proposals['Gülüş Tasarımı']->id, 'Gülüş Tasarımı (Smile Design)')
            ->call('approveProposal', $proposals['Gülüş Tasarımı']->id)
            ->call('skipProposal', $proposals['Zirkonyum Kaplama']->id);

        $implant = BrandOffering::query()->where('brand_id', $this->brand->id)->where('service_catalog_item_id', $this->implant->id)->firstOrFail();
        $this->assertSame('main', $implant->priority);
        $this->assertTrue($implant->locked, 'approved offerings are locked');
        $smile = BrandServiceCandidate::query()->find($proposals['Gülüş Tasarımı']->id)->offering;
        $this->assertSame('Gülüş Tasarımı (Smile Design)', $smile->displayName(), 'rename creates the catalog item under the brand sector');
        $this->assertSame('dental', $smile->catalogItem->sector);
        $this->assertSame('secondary', $smile->priority);
        $this->assertSame(BrandServiceCandidate::SKIPPED, $proposals['Zirkonyum Kaplama']->fresh()->status);

        // Re-run only adds new proposals; locked offerings are never renamed.
        BrandServiceAgent::fake([[
            'services' => [
                ['name' => 'Implant Tedavisi', 'catalog_item_id' => $this->implant->id, 'page_ids' => [$pages['implant']->id]],
                ['name' => 'Zirkonyum Kaplama', 'catalog_item_id' => null, 'page_ids' => [$pages['zirkonyum']->id]],
                ['name' => 'Diş Beyazlatma', 'catalog_item_id' => null, 'page_ids' => [$pages['gulus']->id]],
            ],
            'prompt_version' => BrandServiceAgent::PROMPT_VERSION,
        ]]);
        $this->assertSame(['status' => 'ready', 'added' => 1], app(BrandServiceExtractor::class)->extract($this->brand));
        $this->assertSame('Diş İmplantı', $implant->fresh()->displayName());
        $settings->call('$refresh')->assertSee('Diş Beyazlatma');
    }

    public function test_merge_combines_source_pages(): void
    {
        $a = BrandServiceCandidate::query()->create(['brand_id' => $this->brand->id, 'name' => 'İmplant', 'normalized_key' => 'implant', 'page_ids' => [1], 'status' => 'proposed']);
        $b = BrandServiceCandidate::query()->create(['brand_id' => $this->brand->id, 'name' => 'Implant EN', 'normalized_key' => 'implant en', 'page_ids' => [2], 'status' => 'proposed']);

        Livewire::test(BrandSettings::class, ['brandId' => $this->brand->id])
            ->set('mergePick.'.$a->id, true)->set('mergePick.'.$b->id, true)->call('mergeProposals');

        $this->assertSame([1, 2], $a->fresh()->page_ids);
        $this->assertSame(BrandServiceCandidate::SKIPPED, $b->fresh()->status);
    }

    public function test_extraction_needs_sector_pages_and_an_operational_brand(): void
    {
        $extractor = app(BrandServiceExtractor::class);
        $this->assertSame('no_pages', $extractor->extract($this->brand)['status']);
        $this->brand->update(['sector_id' => null]);
        $this->assertSame('no_sector', $extractor->extract($this->brand->fresh())['status']);
        $this->brand->customer->update(['status' => CustomerStatus::Inactive]);
        $this->assertSame('not_operational', $extractor->extract($this->brand->fresh())['status']);
    }

    public function test_brand_settings_sector_areas_priority_notes(): void
    {
        $legal = ServiceCategory::query()->firstOrCreate(['code' => 'legal'], ['name' => 'Hukuk', 'normalized_key' => 'hukuk']);
        $offering = BrandOffering::query()->create(['brand_id' => $this->brand->id, 'service_catalog_item_id' => $this->implant->id, 'status' => 'active']);

        $page = Livewire::test(BrandSettings::class, ['brandId' => $this->brand->id])
            ->set('sectorId', (string) $legal->id)->call('saveSector')
            ->set('areaName', 'Çankaya şubesi')->set('areaCity', 'Ankara')->set('areaDistrict', 'Çankaya')->set('areaPhysical', true)->call('addArea')
            ->set('areaCity', 'Eskişehir')->call('addArea')
            ->call('setPriority', $offering->id, 'main')
            ->set('goals', 'Ayda 40 implant randevusu')->set('constraints', 'Fiyat yazılmaz')->call('saveNotes')
            ->assertHasNoErrors();

        $this->assertSame($legal->id, $this->brand->fresh()->sector_id);
        $this->assertSame('legal', $this->brand->fresh()->sector);
        $this->assertSame($legal->id, $this->site->fresh()->sector()?->id, 'asset inherits the brand sector');
        $areas = BrandServiceArea::query()->where('brand_id', $this->brand->id)->orderBy('id')->get();
        $this->assertSame(['Çankaya şubesi', 'Eskişehir, Türkiye'], $areas->map->displayName()->all());
        $this->assertSame([true, false], $areas->pluck('physical_branch')->all());
        $this->assertSame('main', $offering->fresh()->priority);
        $notes = BrandMemory::query()->where('brand_id', $this->brand->id)->where('kind', 'profile')->firstOrFail();
        $this->assertSame(['goals' => 'Ayda 40 implant randevusu', 'constraints' => 'Fiyat yazılmaz'], $notes->data);

        $page->call('togglePhysical', $areas[1]->id)->call('removeArea', $areas[0]->id);
        $this->assertTrue($areas[1]->fresh()->physical_branch);
        $this->assertNull($areas[0]->fresh());

        // Sayfa: Marka › Ayarlar opens the settings; the asset list shows the website.
        Livewire::withQueryParams(['tab' => 'ayarlar'])->test(BrandShow::class, ['brand' => (string) $this->brand->id])
            ->assertSee('Hizmet bölgeleri')->assertSee('Sayfalardan hizmet çıkar')->assertSee('panorama.com.tr');
    }

    public function test_asset_moves_to_another_brand_keep_one_brand_one_customer(): void
    {
        $other = Brand::factory()->create(['customer_id' => $this->brand->customer_id, 'name' => 'Panorama İzmir']);
        $ads = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_ads', 'name' => 'Panorama Ads']);

        Livewire::test(BrandSettings::class, ['brandId' => $this->brand->id])
            ->set('moveTo.'.$ads->id, (string) $other->id)->call('moveAsset', $ads->id);

        $this->assertSame($other->id, $ads->fresh()->brand_id, 'asset ↔ one brand');
        $this->assertSame($this->brand->customer_id, $other->fresh()->customer_id, 'brand ↔ one customer');

        $member = User::factory()->create(['is_active' => true]);
        $member->assignRole(Roles::TEAM_MEMBER);
        $this->actingAs($member);
        Livewire::test(BrandSettings::class, ['brandId' => $this->brand->id])->call('moveAsset', $this->site->id)->assertForbidden();
    }

    public function test_today_lists_operational_brands_with_sector_counts_asset_types_and_last_data(): void
    {
        BrandOffering::query()->create(['brand_id' => $this->brand->id, 'service_catalog_item_id' => $this->implant->id, 'status' => 'active']);
        BrandServiceArea::query()->create(['brand_id' => $this->brand->id, 'country_code' => 'TR', 'city_name' => 'Ankara', 'normalized_key' => 'tr|ankara|', 'status' => 'active']);
        $passive = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Inactive])->id, 'name' => 'Pasif Marka']);

        Livewire::test(Dashboard::class)
            ->assertSee('Panorama Ankara')->assertSee('Diş sağlığı')->assertSee('1 hizmet')->assertSee('1 bölge')
            ->assertSee('Web sitesi · —')->assertDontSee($passive->name);
    }

    private function enableAi(): void
    {
        config(['moxdop.anthropic.api_key' => 'test-anthropic-value']);
        CoreIntegration::factory()->anthropic()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
    }

    /** @return array<string, Page> */
    private function pages(): array
    {
        $make = fn (string $path, string $title, array $extra = []): Page => Page::query()->create(array_merge([
            'website_asset_id' => $this->site->id, 'url' => 'https://panorama.com.tr'.$path, 'url_hash' => hash('sha256', $path),
            'path' => $path, 'title' => $title, 'h1' => $title, 'language' => 'tr', 'category' => 'diger', 'is_indexable' => true,
        ], $extra));

        return [
            'home' => $make('/', 'Panorama Ankara Diş Kliniği'),
            'about' => $make('/hakkimizda/', 'Hakkımızda'),
            'contact' => $make('/iletisim/', 'İletişim'),
            'kvkk' => $make('/kvkk-aydinlatma-metni/', 'KVKK Aydınlatma Metni'),
            'blog' => $make('/blog/implant-sonrasi-agri/', 'İmplant sonrası ağrı', ['category' => 'blog']),
            'post' => $make('/implant-mi-kopru-mu/', 'İmplant mı köprü mü?', ['wp_post_type' => 'post']),
            'category' => $make('/kategori/tedaviler/', 'Tedaviler'),
            'dated' => $make('/2024/05/kampanya/', 'Kampanya'),
            'implant' => $make('/implant/', 'Ankara İmplant Tedavisi', ['category' => 'hizmet']),
            'implantEn' => $make('/en/dental-implant/', 'Dental Implant', ['language' => 'en']),
            'zirkonyum' => $make('/zirkonyum-kaplama/', 'Zirkonyum Kaplama'),
            'gulus' => $make('/hizmetlerimiz/gulus-tasarimi/', 'Gülüş Tasarımı'),
            'noindex' => $make('/eski-hizmet/', 'Eski Hizmet', ['is_indexable' => false]),
        ];
    }
}
