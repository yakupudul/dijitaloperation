<?php

namespace Tests\Feature\Gbp;

use App\Ai\Agents\GbpDescriptionAgent;
use App\Ai\Agents\GbpPostFromPageAgent;
use App\Ai\Agents\GbpServicesCompareAgent;
use App\Enums\CustomerStatus;
use App\Livewire\Demo\Gbp\OverviewPage;
use App\Models\AiProduction;
use App\Models\Brand;
use App\Models\BrandMemory;
use App\Models\BrandOffering;
use App\Models\BrandServiceArea;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\Page;
use App\Models\Run;
use App\Models\ServiceCategory;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\Catalog\ServiceCatalogService;
use App\Services\Gbp\GbpAssistant;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Faz 7 AI operations of the İşletme Profili screen (fake AI, no HTTP): services compare (names must come from the
 * offerings / categories, no business-name advice), description (≤ 750, compliance), post from a site page (CTA = page
 * URL, compliance) → scheduled ADR-073 write, and no AI for a non-operational brand.
 */
final class GbpAssistantTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Brand $brand;

    private DigitalAsset $asset;

    private Page $blog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        Http::preventStrayRequests();
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        config(['moxdop.anthropic.api_key' => 'test-anthropic-value']);
        CoreIntegration::factory()->anthropic()->create(['status' => CoreIntegration::STATUS_ACTIVE]);

        $dental = ServiceCategory::query()->firstOrCreate(['code' => 'dental'], ['name' => 'Diş sağlığı', 'normalized_key' => 'dis sagligi']);
        $customer = Customer::factory()->create(['status' => CustomerStatus::Active]);
        $this->brand = Brand::factory()->create(['customer_id' => $customer->id, 'name' => 'Panorama Ankara', 'sector_id' => $dental->id]);
        foreach ([['Diş İmplantı', 'main'], ['Zirkonyum Kaplama', 'main'], ['Ortodonti', 'secondary']] as [$name, $priority]) {
            $service = app(ServiceCatalogService::class)->resolveOrCreate($name, 'dental', actor: $this->admin)['service'];
            BrandOffering::query()->create(['brand_id' => $this->brand->id, 'service_catalog_item_id' => $service->id, 'status' => 'active', 'priority' => $priority, 'locked' => true]);
        }
        BrandServiceArea::query()->create(['brand_id' => $this->brand->id, 'name' => 'Çankaya şubesi', 'country_code' => 'TR', 'city_name' => 'Ankara', 'district_name' => 'Çankaya', 'normalized_key' => 'tr-ankara-cankaya', 'physical_branch' => true, 'status' => 'active']);
        BrandMemory::query()->create(['brand_id' => $this->brand->id, 'kind' => 'profile', 'ref_type' => 'manual_notes', 'data' => ['goals' => 'İmplant hastası artsın', 'constraints' => 'Fiyat yazılmaz'], 'updated_at' => now()]);

        $this->asset = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_business_profile', 'status' => 'active', 'name' => 'Panorama Çankaya']);
        $google = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $resource = CoreExternalResource::factory()->create(['integration_id' => $google->id, 'provider' => 'google', 'resource_type' => 'google_business_profile',
            'external_id' => 'locations/22', 'parent_external_id' => 'accounts/11', 'status' => CoreExternalResource::STATUS_AVAILABLE]);
        $binding = CoreAssetBinding::factory()->create(['digital_asset_id' => $this->asset->id, 'external_resource_id' => $resource->id, 'capability' => 'google_business_profile', 'status' => CoreAssetBinding::STATUS_ACTIVE]);
        $runId = (int) Run::query()->create(['digital_asset_id' => $this->asset->id, 'core_asset_binding_id' => $binding->id, 'module_id' => 'google-business-profile', 'status' => 'completed', 'started_at' => now(), 'finished_at' => now()])->id;
        $base = ['digital_asset_id' => $this->asset->id, 'external_resource_id' => $resource->id, 'run_id' => $runId, 'location_name' => 'locations/22', 'created_at' => now(), 'updated_at' => now()];
        DB::table('gbp_location_snapshots')->insert($base + ['title' => 'Panorama Ankara Diş Kliniği', 'primary_category' => 'Diş kliniği',
            'additional_categories' => json_encode(['additionalCategories' => [['displayName' => 'Ortodontist']]]), 'website_uri' => 'https://panorama.test/',
            'profile' => json_encode(['description' => 'Kısa açıklama.']), 'captured_at' => now()]);
        DB::table('gbp_service_snapshots')->insert($base + ['service_items' => json_encode([['freeFormServiceItem' => ['label' => ['displayName' => 'Diş İmplantı']]]]), 'captured_at' => now()]);

        $site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'name' => 'panorama.test', 'domain' => 'panorama.test', 'primary_url' => 'https://panorama.test/']);
        $this->blog = Page::query()->create(['website_asset_id' => $site->id, 'url' => 'https://panorama.test/implant-sonrasi-bakim/', 'url_hash' => hash('sha256', 'blog'), 'path' => '/implant-sonrasi-bakim/',
            'title' => 'İmplant sonrası bakım', 'category' => 'blog', 'language' => 'tr', 'is_indexable' => true,
            'content_text' => 'İmplant sonrası ilk günlerde sıcak içeceklerden kaçının, diş hekiminizin önerdiği ağız bakımını uygulayın ve kontrollere gelin.']);
        Page::query()->create(['website_asset_id' => $site->id, 'url' => 'https://panorama.test/iletisim/', 'url_hash' => hash('sha256', 'contact'), 'path' => '/iletisim/',
            'title' => 'İletişim', 'category' => 'kurumsal', 'language' => 'tr', 'is_indexable' => true]);
    }

    private function page(string $tab): Testable
    {
        return Livewire::actingAs($this->admin)->test(OverviewPage::class, ['assetId' => (string) $this->asset->id, 'tab' => $tab]);
    }

    public function test_services_compare_keeps_only_offering_names_missing_on_the_profile_and_valid_category_notes(): void
    {
        GbpServicesCompareAgent::fake([[
            'missing_services' => [
                ['name' => 'Zirkonyum Kaplama', 'reason' => 'Ana hizmet profilde yok.'],
                ['name' => 'Diş İmplantı', 'reason' => 'Zaten var.'],
                ['name' => 'Diş Beyazlatma', 'reason' => 'Uydurma hizmet.'],
                ['name' => 'Ortodonti', 'reason' => 'İkincil hizmet eksik.'],
            ],
            'category_notes' => [
                ['category' => 'diş kliniği', 'note' => 'Birincil kategori ana hizmetlerle uyumlu.'],
                ['category' => 'Kozmetik', 'note' => 'Bilinmeyen kategori.'],
                ['category' => '', 'note' => 'İşletme adına "implant" kelimesini ekleyin.'],
            ],
        ]]);

        $page = $this->page('todo')->call('compareServices');

        GbpServicesCompareAgent::assertPrompted(fn ($prompt): bool => str_contains((string) $prompt->prompt, 'Zirkonyum Kaplama') && str_contains((string) $prompt->prompt, 'Ortodontist'));
        $rows = Suggestion::query()->whereIn('action_type', ['gbp_add_service', 'gbp_category'])->orderBy('priority')->orderBy('id')->get();
        $this->assertSame(['Hizmet ekle: Zirkonyum Kaplama', 'Hizmet ekle: Ortodonti', 'Kategori: Diş kliniği'], $rows->pluck('title')->all());
        $this->assertSame([1, 2, 2], $rows->pluck('priority')->all());
        $this->assertTrue($rows->every(fn (Suggestion $s): bool => $s->prompt_version_id !== null && $s->channel === 'maps' && $s->target_id === $this->asset->id));
        $page->call('setTab', 'todo')->assertSee('Hizmet ekle: Zirkonyum Kaplama')->assertDontSee('Diş Beyazlatma')->assertDontSee('İşletme adına')
            ->assertSee('Hizmetleri karşılaştır: 2 eksik hizmet, 1 kategori notu.');
    }

    public function test_description_is_at_most_750_characters_and_shown_current_vs_proposed(): void
    {
        $sentence = 'Panorama Ankara, Çankaya şubesinde implant ve zirkonyum kaplama tedavilerinde deneyimli bir ekiple hizmet verir. ';
        GbpDescriptionAgent::fake([['description' => str_repeat($sentence, 9), 'reason' => 'Ana hizmetler ve şube eklendi.']]);

        $page = $this->page('todo')->call('proposeDescription');

        GbpDescriptionAgent::assertPrompted(fn ($prompt): bool => str_contains((string) $prompt->prompt, 'İmplant hastası artsın') && str_contains((string) $prompt->prompt, 'Çankaya şubesi'));
        $suggestion = Suggestion::query()->where('action_type', 'gbp_description')->sole();
        $proposed = (string) $suggestion->action['proposed'];
        $this->assertLessThanOrEqual(GbpAssistant::DESCRIPTION_MAX, mb_strlen($proposed));
        $this->assertStringEndsWith('.', $proposed);
        $this->assertSame('Kısa açıklama.', $suggestion->action['current']);
        $page->call('setTab', 'todo')->assertSee('Açıklamayı güncelle')->assertSee('Mevcut')->assertSee('Önerilen')->assertSee('Kopyala');
        $this->assertSame(0, ExternalWriteAction::query()->count(), 'the description is never written to Google');
    }

    public function test_description_breaking_sector_compliance_is_not_shown(): void
    {
        GbpDescriptionAgent::fake([['description' => 'Ankara’nın en iyi implant kliniği: garantili ve ağrısız tedavi, deneyimli ekip, modern cihazlar ve rahat ortam.', 'reason' => 'x']]);

        $this->page('todo')->call('proposeDescription')->call('setTab', 'todo')->assertSee('sektör uyum kuralına takıldı');

        $this->assertSame(0, Suggestion::query()->where('action_type', 'gbp_description')->count());
        $this->assertSame('failed', app(GbpAssistant::class)->state($this->asset->id, GbpAssistant::OP_DESCRIPTION)['status']);
    }

    public function test_post_from_a_site_page_links_to_the_page_and_is_scheduled_through_the_admin_write(): void
    {
        GbpPostFromPageAgent::fake([['text' => 'İmplant sonrası ilk günler iyileşme için önemlidir. Sıcak içeceklerden kaçının, önerilen ağız bakımını uygulayın ve kontrollerinizi aksatmayın. Ayrıntılar yazımızda.', 'action_type' => 'LEARN_MORE']]);

        $page = $this->page('posts')->assertSee('İmplant sonrası bakım')->assertDontSee('İletişim</option>', false)
            ->set('sharePageId', (string) $this->blog->id)->call('sharePage');

        GbpPostFromPageAgent::assertPrompted(fn ($prompt): bool => str_contains((string) $prompt->prompt, 'sıcak içeceklerden'));
        $draft = AiProduction::query()->where('kind', GbpAssistant::POST_KIND)->sole();
        $this->assertSame('https://panorama.test/implant-sonrasi-bakim/', $draft->content['url']);
        $page->call('setTab', 'posts')->assertSee('Düzenle ve yayınla')
            ->call('useAiDraft', $draft->id)->assertSet('postFormOpen', true)->assertSet('post.url', 'https://panorama.test/implant-sonrasi-bakim/')
            ->set('post.when', 'later')->set('post.publish_at', now()->addDay()->timezone('Europe/Istanbul')->format('Y-m-d\TH:i'))
            ->call('publishPost')->assertHasNoErrors();

        $action = ExternalWriteAction::query()->sole();
        $this->assertSame('scheduled', $action->status);
        $this->assertSame(ExternalWriteAction::ACTION_LOCAL_POST, $action->action);
        $this->assertSame('https://panorama.test/implant-sonrasi-bakim/', $action->request_payload['url']);
        $this->assertSame($this->admin->id, (int) $action->requested_by);
        $this->assertLessThanOrEqual(GbpAssistant::POST_MAX, mb_strlen((string) $action->request_payload['summary']));
        $this->assertSame(AiProduction::STATUS_USED, $draft->fresh()->status);
    }

    public function test_post_text_with_a_link_or_a_compliance_hit_is_rejected(): void
    {
        GbpPostFromPageAgent::fake([
            ['text' => 'Bakım önerilerimizi okuyun: https://panorama.test/implant-sonrasi-bakim/ ve randevunuzu hemen alın, kontrollerinizi aksatmayın.', 'action_type' => 'LEARN_MORE'],
            ['text' => 'Garantili implant sonuçları için kliniğimizdeyiz; ağrısız tedavi ve hızlı iyileşme için hemen randevu alın.', 'action_type' => 'BOOK'],
        ]);

        $page = $this->page('posts')->set('sharePageId', (string) $this->blog->id)->call('sharePage')->call('setTab', 'posts')->assertSee('bağlantı, telefon ya da e-posta');
        $page->call('sharePage')->call('setTab', 'posts')->assertSee('sektör uyum kuralına takıldı');

        $this->assertSame(0, AiProduction::query()->where('kind', GbpAssistant::POST_KIND)->count());
    }

    public function test_non_operational_brand_gets_no_ai(): void
    {
        $this->brand->customer->forceFill(['status' => CustomerStatus::Inactive])->save();
        GbpServicesCompareAgent::fake();
        GbpDescriptionAgent::fake();
        GbpPostFromPageAgent::fake();

        $this->page('todo')->call('compareServices')->call('proposeDescription')->call('setTab', 'posts')
            ->set('sharePageId', (string) $this->blog->id)->call('sharePage');
        app(GbpAssistant::class)->run($this->asset->id, GbpAssistant::OP_SERVICES);

        GbpServicesCompareAgent::assertNeverPrompted();
        GbpDescriptionAgent::assertNeverPrompted();
        GbpPostFromPageAgent::assertNeverPrompted();
        $this->assertSame(0, Suggestion::query()->where('action_type', '!=', 'gbp_standard')->count());
        $this->assertSame('Marka operasyonel değil; AI çalışmaz.', app(GbpAssistant::class)->state($this->asset->id, GbpAssistant::OP_SERVICES)['message']);
    }

    public function test_description_of_one_branch_names_only_its_own_area(): void
    {
        $address = fn (string $district): string => json_encode(['sublocality' => $district, 'locality' => 'Ankara', 'administrativeArea' => 'Ankara']);
        DB::table('gbp_location_snapshots')->where('digital_asset_id', $this->asset->id)->update(['storefront_address' => $address('Çankaya')]);
        $sibling = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_business_profile', 'status' => 'active', 'name' => 'Panorama Kızılay']);
        $resource = CoreExternalResource::factory()->create(['integration_id' => CoreIntegration::query()->where('provider', 'google')->value('id'), 'provider' => 'google',
            'resource_type' => 'google_business_profile', 'external_id' => 'locations/33', 'parent_external_id' => 'accounts/11', 'status' => CoreExternalResource::STATUS_AVAILABLE]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $sibling->id, 'external_resource_id' => $resource->id, 'capability' => 'google_business_profile', 'status' => CoreAssetBinding::STATUS_ACTIVE]);
        DB::table('gbp_location_snapshots')->insert(['digital_asset_id' => $sibling->id, 'external_resource_id' => $resource->id, 'run_id' => 2, 'location_name' => 'locations/33',
            'title' => 'Panorama Kızılay', 'storefront_address' => $address('Kızılay'), 'captured_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $sentence = 'Panorama Ankara, Çankaya ve Kızılay şubelerinde implant ve zirkonyum kaplama tedavilerinde deneyimli bir ekiple hizmet verir. ';
        GbpDescriptionAgent::fake([['description' => str_repeat($sentence, 3), 'reason' => 'x']]);

        $this->page('todo')->call('proposeDescription')->call('setTab', 'todo')->assertSee('başka bir şubenin bölgesini');

        GbpDescriptionAgent::assertPrompted(fn ($prompt): bool => str_contains((string) $prompt->prompt, 'Çankaya, Ankara') && ! str_contains((string) $prompt->prompt, 'Çankaya şubesi'));
        $this->assertSame(0, Suggestion::query()->where('action_type', 'gbp_description')->count());
    }
}
