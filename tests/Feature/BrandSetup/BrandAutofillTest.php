<?php

namespace Tests\Feature\BrandSetup;

use App\Jobs\BuildBrandSetupProposalJob;
use App\Livewire\Operator\Portfolio\BrandShow;
use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\BrandSetupProposal;
use App\Models\Collection\CollectionRun;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\OfferingPage;
use App\Models\Page;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\BrandSetup\BrandAutofill;
use App\Services\BrandSetup\LanguageServices;
use App\Services\Catalog\ServiceCatalogService;
use App\Services\Operator\BrandWorkspaceReadService;
use App\Services\Website\Pages\PageStore;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Marka tamamlama (yakup, 2026-10-07 "Hemen kullan"): a brand missing services, places or context gets an "Otomatik kur"
 * run by itself; the ready proposal is applied at once with its default picks, one service becomes ★, and the card
 * asks for a look until the operator says "Kontrol ettim".
 */
final class BrandAutofillTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Brand $brand;

    private DigitalAsset $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Adadent']);
        $this->site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'name' => 'adadent.com.tr', 'primary_url' => 'https://adadent.com.tr/', 'module_id' => 'website']);
        ServiceCategory::query()->create(['code' => 'saglik', 'name' => 'Sağlık', 'normalized_key' => 'saglik']);
    }

    public function test_a_brand_without_services_gets_an_automatic_run_and_a_site_never_read_is_crawled_first(): void
    {
        Queue::fake();
        $this->assertSame(['queued' => 0, 'applied' => 0, 'crawl' => 1, 'waiting' => 0, 'complete' => 0, 'no_site' => 0, 'archived' => 0], app(BrandAutofill::class)->run());
        $this->assertSame(1, CollectionRun::query()->where('digital_asset_id', $this->site->id)->count());
        $this->assertSame('crawl', app(BrandAutofill::class)->forBrand($this->brand), 'still no pages: the brand keeps waiting for the crawl');
        $this->assertSame(1, CollectionRun::query()->where('digital_asset_id', $this->site->id)->count(), 'the crawl is not started again within a day');
        $this->assertStringContainsString('okunamadı', (string) (BrandAutofill::note($this->brand->id)['text'] ?? ''));

        $this->page('/implant/', 'İmplant');
        $this->assertSame('queued', app(BrandAutofill::class)->forBrand($this->brand));
        $proposal = BrandSetupProposal::query()->where('brand_id', $this->brand->id)->sole();
        $this->assertTrue($proposal->auto_apply);
        $this->assertNull($proposal->created_by);
        Queue::assertPushed(BuildBrandSetupProposalJob::class);
        $this->assertSame('waiting', app(BrandAutofill::class)->forBrand($this->brand));
    }

    public function test_a_ready_automatic_proposal_is_applied_with_its_picks_a_main_service_is_set_and_the_card_asks_for_a_look(): void
    {
        $proposal = BrandSetupProposal::query()->create([
            'brand_id' => $this->brand->id, 'status' => BrandSetupProposal::STATUS_READY, 'website_url' => 'https://adadent.com.tr/', 'auto_apply' => true,
            'items' => [], 'services_status' => 'ready', 'summary' => ['areas' => [['city_name' => 'İstanbul', 'district_name' => 'Kadıköy', 'label' => 'Kadıköy', 'physical' => true, 'selected' => true]]],
            'services' => [
                ['name' => 'Diş Beyazlatma', 'sector_code' => 'saglik', 'is_new' => true, 'status' => 'proposed', 'selected' => true, 'keywords' => [['query' => 'diş beyazlatma', 'impressions' => 40]]],
                ['name' => 'İmplant Tedavisi', 'sector_code' => 'saglik', 'is_new' => true, 'status' => 'proposed', 'selected' => true, 'keywords' => [['query' => 'implant fiyatları', 'impressions' => 900]]],
                ['name' => 'Emin olunmayan', 'sector_code' => 'saglik', 'is_new' => true, 'status' => 'proposed', 'selected' => false],
            ],
        ]);

        $this->assertSame('applied', app(BrandAutofill::class)->forBrand($this->brand));

        $proposal->refresh();
        $this->assertSame(BrandSetupProposal::STATUS_APPLIED, $proposal->status);
        $this->assertTrue(BrandAutofill::isSystem($proposal->applied_by));
        $this->assertFalse(User::query()->where('email', BrandAutofill::SYSTEM_EMAIL)->value('is_active'), 'the automatic user cannot log in');
        $offerings = BrandOffering::query()->with('primaryName')->where('brand_id', $this->brand->id)->get()->keyBy(fn (BrandOffering $o): string => $o->displayName());
        $this->assertSame(['Diş Beyazlatma', 'İmplant Tedavisi'], $offerings->keys()->sort()->values()->all(), 'only confident rows');
        $this->assertTrue($offerings['İmplant Tedavisi']->isMain(), 'the most searched service becomes ★');
        $this->assertFalse($offerings['Diş Beyazlatma']->isMain());

        $workspace = app(BrandWorkspaceReadService::class);
        $items = collect($workspace->checklist($this->brand->fresh(), [], $workspace->services($this->brand))['items'])->keyBy('key');
        $this->assertTrue($items['services']['review'] ?? false);
        $this->assertStringContainsString('Claude doldurdu', $items['services']['detail']);

        Livewire::test(BrandShow::class, ['brand' => (string) $this->brand->id])
            ->assertSeeHtml('data-confirm-checked="services"')
            ->call('confirmChecked', 'services')
            ->assertDontSeeHtml('data-confirm-checked="services"');
        $this->assertNotNull(data_get($proposal->fresh()->summary, 'checked_at'));
    }

    public function test_an_operator_proposal_is_never_applied_by_the_night_run(): void
    {
        BrandSetupProposal::query()->create([
            'brand_id' => $this->brand->id, 'status' => BrandSetupProposal::STATUS_READY, 'website_url' => 'https://adadent.com.tr/',
            'items' => [], 'services' => [['name' => 'İmplant Tedavisi', 'sector_code' => 'saglik', 'is_new' => true, 'status' => 'proposed', 'selected' => true]],
            'services_status' => 'ready', 'summary' => [], 'created_by' => $this->admin->id,
        ]);
        $this->page('/', 'Adadent');
        Queue::fake();

        $this->assertSame('waiting', app(BrandAutofill::class)->forBrand($this->brand), 'the operator\'s ready proposal waits for the operator');
        $this->assertSame(1, BrandSetupProposal::query()->where('brand_id', $this->brand->id)->count());
        $this->assertSame(0, BrandOffering::query()->where('brand_id', $this->brand->id)->count());
    }

    public function test_services_made_only_from_translated_pages_are_archived_and_translated_pages_never_become_services(): void
    {
        $catalog = app(ServiceCatalogService::class);
        $offering = fn (string $name, array $extra = []): BrandOffering => BrandOffering::query()->create(['brand_id' => $this->brand->id,
            'service_catalog_item_id' => $catalog->resolveOrCreate($name, 'saglik', actor: $this->admin)['service']->id, 'status' => 'active', 'priority' => 'secondary'] + $extra);
        $foreign = $offering('Einzelzahnimplantat');
        $locked = $offering('Otturazione', ['locked' => true]);
        $turkish = $offering('İmplant Tedavisi');
        $mixed = $offering('Dolgu');
        $de = $this->page('/de/einzelzahnimplantat/', 'Einzelzahnimplantat');
        $de->forceFill(['language' => 'de'])->save();
        $it = $this->page('/it/otturazione/', 'Otturazione');
        $it->forceFill(['language' => null])->save();
        $tr = $this->page('/implant/', 'İmplant');
        $dolgu = $this->page('/dolgu/', 'Dolgu');
        foreach ([[$foreign, $de], [$locked, $it], [$turkish, $tr], [$mixed, $dolgu], [$mixed, $de]] as [$o, $p]) {
            OfferingPage::query()->create(['brand_offering_id' => $o->id, 'page_id' => $p->id, 'source' => 'rule']);
        }

        $this->assertSame(1, app(LanguageServices::class)->cleanup($this->brand->id));
        $this->assertSame('archived', $foreign->fresh()->status instanceof \BackedEnum ? $foreign->fresh()->status->value : $foreign->fresh()->status);
        $this->assertSame(0, OfferingPage::query()->where('brand_offering_id', $foreign->id)->count());
        foreach ([$locked, $turkish, $mixed] as $kept) {
            $status = $kept->fresh()->status;
            $this->assertSame('active', $status instanceof \BackedEnum ? $status->value : $status);
        }
        $this->assertTrue(LanguageServices::foreign(null, '/en/implant/', 'tr'));
        $this->assertFalse(LanguageServices::foreign(null, '/implant/', 'tr'));
        $this->assertTrue(LanguageServices::foreign('de-DE', '/implant/', 'tr'));
    }

    private function page(string $path, string $title): Page
    {
        $url = 'https://adadent.com.tr'.$path;

        return Page::query()->create(['website_asset_id' => $this->site->id, 'url' => $url, 'url_hash' => PageStore::urlHash($url), 'path' => $path,
            'title' => $title, 'language' => 'tr', 'is_indexable' => true, 'content_hash' => hash('sha256', $path)]);
    }
}
