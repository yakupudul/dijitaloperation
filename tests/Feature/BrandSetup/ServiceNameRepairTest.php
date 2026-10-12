<?php

namespace Tests\Feature\BrandSetup;

use App\Models\Brand;
use App\Models\BrandContextActivity;
use App\Models\BrandOffering;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\OfferingPage;
use App\Models\Page;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\BrandIntelligence\BrandOfferingService;
use App\Services\BrandSetup\BrandAutofill;
use App\Services\BrandSetup\LanguageServices;
use App\Services\Catalog\ServiceCatalogService;
use App\Services\Website\Pages\PageStore;
use App\Support\Options\LocationOptions;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Hizmet adları sitenin ana dilinde ve düzgün (yakup, 2026-10-12): the nightly language round links main-language pages,
 * renames or archives services named in another language and repairs broken names; a locked service is never touched and
 * a ★ service is never archived.
 */
final class ServiceNameRepairTest extends TestCase
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
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Avrupadent']);
        $this->site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'name' => 'avrupadent.com.tr',
            'primary_url' => 'https://avrupadent.com.tr/', 'module_id' => 'website']);
        ServiceCategory::query()->create(['code' => 'saglik', 'name' => 'Sağlık', 'normalized_key' => 'saglik']);
        $this->page('/', 'Avrupadent Ağız ve Diş Sağlığı');
    }

    public function test_a_wrong_language_service_another_service_covers_is_archived_and_its_own_turkish_links_move_over(): void
    {
        $ortodonti = $this->offering('Ortodonti');
        $orthodontics = $this->offering('Orthodontics');
        $hub = $this->page('/tedaviler/ortodonti/', 'Ortodonti | Avrupadent');
        $plak = $this->page('/tedaviler/ortodonti/seffaf-plak/', 'Şeffaf Plak Tedavisi');
        $en = $this->page('/en/orthodontics/', 'Orthodontics | Avrupadent', 'en');
        $this->link($ortodonti, $hub);
        $this->link($orthodontics, $hub);
        $this->link($orthodontics, $plak);
        $this->link($orthodontics, $en);

        $out = app(LanguageServices::class)->run($this->brand->id);

        $this->assertSame(1, $out['archived']);
        $this->assertSame('archived', $this->offeringStatus($orthodontics));
        $this->assertSame('active', $this->offeringStatus($ortodonti));
        $this->assertTrue(OfferingPage::query()->where('brand_offering_id', $ortodonti->id)->where('page_id', $plak->id)->exists(), 'its own Turkish page moves to the kept service');
        $this->assertTrue(OfferingPage::query()->where('brand_offering_id', $orthodontics->id)->where('page_id', $en->id)->exists(), 'nothing is deleted');
        $trace = BrandContextActivity::query()->where('event', 'OFFERING_ARCHIVED')->where('subject_id', $orthodontics->id)->first();
        $this->assertNotNull($trace);
        $this->assertSame('language_duplicate', $trace->payload['reason']);
        $this->assertSame($ortodonti->id, $trace->payload['kept_offering_id']);
        $this->assertSame(BrandAutofill::systemUser()->id, $trace->actor_user_id);
    }

    public function test_a_wrong_language_service_without_a_twin_takes_its_turkish_page_title_and_a_locked_one_stays(): void
    {
        $aesthetic = $this->offering('Aesthetic Dentistry');
        $locked = $this->offering('Restorative Dental Treatment', ['locked' => true]);
        $this->link($aesthetic, $this->page('/en/aesthetic-dentistry/', 'Aesthetic Dentistry | Avrupadent', 'en'));
        $this->link($aesthetic, $this->page('/tedaviler/estetik-dis-hekimligi/', 'Estetik Diş Hekimliği | Avrupadent'));
        $this->link($locked, $this->page('/en/restorative-dental-treatment/', 'Restorative Dental Treatment', 'en'));
        $this->link($locked, $this->page('/tedaviler/restoratif-dis-tedavisi/', 'Restoratif Diş Tedavisi'));

        $out = app(LanguageServices::class)->run($this->brand->id);

        $this->assertSame(1, $out['renamed']);
        $this->assertSame('Estetik Diş Hekimliği', $this->offeringName($aesthetic));
        $this->assertSame('active', $this->offeringStatus($aesthetic));
        $this->assertSame('Restorative Dental Treatment', $this->offeringName($locked), 'an operator-locked service is never touched');
        $this->assertTrue(BrandContextActivity::query()->where('event', 'OFFERING_RENAMED')->where('subject_id', $aesthetic->id)->exists());
    }

    public function test_an_english_service_without_pages_whose_catalog_has_the_turkish_name_of_another_service_is_archived(): void
    {
        $turkish = $this->offering('Diş Hassasiyeti Tedavisi');
        $english = $this->offering('Treatment Dental Sensitivity');
        app(ServiceCatalogService::class)->addAlias($english->catalogItem, 'Diş Hassasiyeti Tedavisi Uygulaması');
        $this->link($turkish, $this->page('/tedaviler/dis-hassasiyeti-tedavisi/', 'Diş Hassasiyeti Tedavisi'));
        // A Turkish name the site's pages carry is never read as English, even with an English-looking word in it.
        $botox = $this->offering('Dental Botox');
        $this->link($botox, $this->page('/tedaviler/dental-botox/', 'Dental Botox Uygulaması'));

        app(LanguageServices::class)->run($this->brand->id);

        $this->assertSame('active', $this->offeringStatus($turkish));
        $this->assertSame('Dental Botox', $this->offeringName($botox));
        $this->assertSame('active', $this->offeringStatus($english), 'the Turkish catalog alias is free: the English service is renamed to it, not archived');
        $this->assertSame('Diş Hassasiyeti Tedavisi Uygulaması', $this->offeringName($english));

        // A Turkish brand name over another catalog service, and an English service whose catalog service is named "Diş Beyazlatma".
        $whitening = $this->offering('Beyazlatma');
        app(BrandOfferingService::class)->renameLocal($whitening, 'Diş Beyazlatma');
        $other = $this->offering('Teeth Whitening');
        DB::table('service_catalog_names')->where('service_catalog_item_id', $other->service_catalog_item_id)->where('is_primary', true)
            ->update(['raw_label' => 'Diş Beyazlatma', 'normalized_key' => 'diş beyazlatma']);

        app(LanguageServices::class)->run($this->brand->id);

        $this->assertSame('archived', $this->offeringStatus($other), 'its catalog service (Turkish name) is another active service of the brand: a duplicate');
    }

    public function test_a_main_service_is_only_renamed_never_archived(): void
    {
        $gum = $this->offering('Diş Eti Tedavisi');
        $main = $this->offering('Gum Treatments', ['priority' => 'main']);
        $page = $this->page('/tedaviler/dis-eti-tedavisi/', 'Diş Eti Tedavisi');
        $this->link($gum, $page);
        $this->link($main, $page);
        $this->link($main, $this->page('/en/gum-treatments/', 'Gum Treatments', 'en'));

        app(LanguageServices::class)->run($this->brand->id);

        $this->assertSame('active', $this->offeringStatus($main));
        $this->assertTrue($main->fresh()->isMain());
        $this->assertSame('Gum Treatments', $this->offeringName($main), 'its Turkish name belongs to another service: nothing to rename to');

        $periodontoloji = $this->offering('Periodontology', ['priority' => 'main']);
        $this->link($periodontoloji, $this->page('/en/periodontology/', 'Periodontology', 'en'));
        $this->link($periodontoloji, $this->page('/tedaviler/periodontoloji/', 'Periodontoloji'));

        app(LanguageServices::class)->run($this->brand->id);

        $this->assertSame('Periodontoloji', $this->offeringName($periodontoloji));
        $this->assertSame('active', $this->offeringStatus($periodontoloji));
    }

    public function test_services_with_only_translated_pages_get_the_turkish_page_from_the_translation_link_or_their_name(): void
    {
        $implant = $this->offering('İmplant Tedavisi', ['priority' => 'main']);
        $kanal = $this->offering('Kanal Tedavisi');
        $locked = $this->offering('Zirkonyum Kaplama', ['locked' => true]);
        $tr = $this->page('/kadikoy-tek-dis-implant-tedavisi/', 'Kadıköy Tek Diş İmplant Tedavisi', 'tr', 11);
        $this->page('/kadikoy-zygoma-implant/', 'Kadıköy Zygoma İmplant Tedavisi', 'tr', 12);
        $en = $this->page('/en/single-tooth-implant-treatment-in-kadikoy/', 'Single Tooth Implant Treatment in Kadıköy', 'en', 21);
        $de = $this->page('/de/kadikoey-zygoma-implantate/', 'Zygoma Implantate', 'de', 22);
        $this->link($implant, $en);
        $this->link($implant, $de);
        $this->snapshot(11, '/kadikoy-tek-dis-implant-tedavisi/', 'tr', ['tr' => 11, 'en' => 21]);
        $this->snapshot(22, '/de/kadikoey-zygoma-implantate/', 'de', ['tr' => 12, 'de' => 22]);
        $kanalTr = $this->page('/tedaviler/kanal-tedavisi/', 'Kanal Tedavisi | OPC');
        $this->link($kanal, $this->page('/en/root-canal-treatment-in-kadikoy/', 'Root Canal Treatment', 'en'));
        $zirkonyumTr = $this->page('/tedaviler/zirkonyum-kaplama/', 'Zirkonyum Kaplama');
        $this->link($locked, $this->page('/en/zirconium-crowns/', 'Zirconium Crowns', 'en'));

        $out = app(LanguageServices::class)->run($this->brand->id);

        $linked = fn (BrandOffering $o): array => OfferingPage::query()->where('brand_offering_id', $o->id)->orderBy('page_id')->pluck('page_id')->all();
        $zygoma = Page::query()->where('wp_post_id', 12)->value('id');
        $this->assertEqualsCanonicalizing([$tr->id, $zygoma, $en->id, $de->id], $linked($implant), 'Polylang translations (both directions) are added, foreign links stay');
        $this->assertContains($kanalTr->id, $linked($kanal), 'no translation link: the Turkish service page with the service\'s own name');
        $this->assertSame('active', $this->offeringStatus($kanal), 'with its Turkish page the service is no longer archived as translation-only');
        $this->assertNotContains($zirkonyumTr->id, $linked($locked), 'a locked service is never touched');
        $this->assertSame(3, $out['linked']);
    }

    public function test_broken_and_lowercase_names_are_repaired_and_locked_ones_stay(): void
    {
        $cam = $this->offering('/CAM Diş Tedavileri');
        $this->link($cam, $this->page('/tedaviler/cad-cam-dis-tedavileri/', 'CAD/CAM Diş Tedavileri | Dentocare'));
        $cekim = $this->offering('Diş Çekimi Lower');
        $this->rename($cekim, 'diş çekimi', catalog: true);
        $lockedLower = $this->offering('Dolgu Lower', ['locked' => true]);
        $this->rename($lockedLower, 'dolgu');
        $implant = $this->offering('İmplant Lower');
        $this->rename($implant, 'implant');

        app(LanguageServices::class)->run($this->brand->id);

        $this->assertSame('CAD/CAM Diş Tedavileri', $this->offeringName($cam));
        $this->assertSame('Diş çekimi', $this->offeringName($cekim));
        $this->assertSame('Diş çekimi', (string) ServiceCatalogItem::query()->find($cekim->service_catalog_item_id)->primaryName->raw_label, 'the shared catalog name is capitalised too');
        $this->assertSame('İmplant', $this->offeringName($implant), 'Turkish i → İ');
        $this->assertSame('dolgu', $this->offeringName($lockedLower));
    }

    public function test_new_names_are_never_split_on_a_slash_or_created_lowercase(): void
    {
        $this->assertSame('CAD/CAM Diş Tedavileri', LocationOptions::strip('CAD/CAM Diş Tedavileri')['text'], '"CAD" glued to "CAM" is not Chad');
        $this->assertSame('implant', LocationOptions::strip('Ankara implant')['text']);
        $service = app(ServiceCatalogService::class)->resolveOrCreate('diş çekimi', 'saglik')['service'];
        $this->assertSame('Diş çekimi', $service->primaryName->raw_label);
        $this->assertSame($service->id, app(ServiceCatalogService::class)->resolveOrCreate('Diş Çekimi', 'saglik')['service']->id, 'same name key');
    }

    /** @param array<string, mixed> $extra */
    private function offering(string $name, array $extra = []): BrandOffering
    {
        $catalog = app(ServiceCatalogService::class)->resolveOrCreate($name, 'saglik', actor: $this->admin)['service'];
        $offering = app(BrandOfferingService::class)->resolveOrCreate($this->brand, $name, actor: $this->admin, catalog: $catalog)['offering'];
        if ($extra !== []) {
            $offering->forceFill($extra)->save();
        }

        return $offering->fresh();
    }

    /** A legacy name written before the creation path capitalised it (same row, raw label only). */
    private function rename(BrandOffering $offering, string $label, bool $catalog = false): void
    {
        DB::table('brand_offering_names')->where('brand_offering_id', $offering->id)->where('is_primary', true)
            ->update(['raw_label' => $label, 'normalized_key' => mb_strtolower(strtr($label, ['İ' => 'i']))]);
        if ($catalog) {
            DB::table('service_catalog_names')->where('service_catalog_item_id', $offering->service_catalog_item_id)->where('is_primary', true)
                ->update(['raw_label' => $label, 'normalized_key' => mb_strtolower(strtr($label, ['İ' => 'i']))]);
        }
    }

    private function offeringName(BrandOffering $offering): string
    {
        return (string) BrandOffering::query()->with('primaryName')->find($offering->id)->primaryName?->raw_label;
    }

    private function offeringStatus(BrandOffering $offering): string
    {
        $status = $offering->fresh()->status;

        return $status instanceof \BackedEnum ? $status->value : (string) $status;
    }

    private function link(BrandOffering $offering, Page $page): void
    {
        OfferingPage::query()->create(['brand_offering_id' => $offering->id, 'page_id' => $page->id, 'source' => 'rule']);
    }

    private function page(string $path, string $title, string $language = 'tr', ?int $wpId = null): Page
    {
        $url = 'https://avrupadent.com.tr'.$path;

        return Page::query()->create(['website_asset_id' => $this->site->id, 'url' => $url, 'url_hash' => PageStore::urlHash($url), 'path' => $path,
            'title' => $title, 'language' => $language, 'is_indexable' => true, 'content_hash' => hash('sha256', $path), 'wp_post_id' => $wpId,
            'category' => str_starts_with($path, '/tedaviler/') ? 'hizmet' : 'diger']);
    }

    /** @param array<string, int> $translations */
    private function snapshot(int $id, string $path, string $language, array $translations): void
    {
        DB::table('website_cms_object_snapshot')->insert(['digital_asset_id' => $this->site->id, 'cms' => 'wordpress', 'object_type' => 'page', 'object_id' => (string) $id,
            'status' => 'publish', 'title' => 'p'.$id, 'permalink' => 'https://avrupadent.com.tr'.$path, 'observed_at' => now(), 'contract_version' => 1,
            'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => hash('sha256', 'p'.$id), 'created_at' => now(), 'updated_at' => now(),
            'metadata' => json_encode(['language' => $language, 'translations' => $translations])]);
    }
}
