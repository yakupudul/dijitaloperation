<?php

namespace Tests\Feature\Queries;

use App\Ai\Agents\QueryPlanFiltersAgent;
use App\Ai\Agents\QueryPlanSectorsAgent;
use App\Ai\Agents\QueryPlanServicesAgent;
use App\Enums\NotificationKind;
use App\Livewire\Operator\Library\QueryPlanWizard;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\FilterTerm;
use App\Models\Query;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCatalogName;
use App\Models\ServiceCategory;
use App\Models\ServiceMatchingKeyword;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Catalog\ServiceCatalogService;
use App\Services\Catalog\ServiceKeywordService;
use App\Services\Queries\QueryPipeline;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/** Sorgular › "AI ile planla": sectors → services + matching keywords → filter basket → first import. */
final class QueryPlanWizardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ServiceCategory $dental;

    private ServiceCategory $hair;

    private ServiceCatalogItem $implant;

    private ServiceCatalogItem $zirkonyum;

    private Brand $brand;

    private DigitalAsset $site;

    private CoreExternalResource $gsc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        $this->dental = ServiceCategory::query()->firstOrCreate(['code' => 'dental'], ['name' => 'Diş sağlığı', 'normalized_key' => 'dis sagligi']);
        $this->hair = ServiceCategory::query()->firstOrCreate(['code' => 'hair'], ['name' => 'Saç ekimi', 'normalized_key' => 'sac ekimi']);
        $catalog = app(ServiceCatalogService::class);
        $this->implant = $catalog->resolveOrCreate('Diş İmplantı', 'dental', actor: $this->admin)['service'];
        $this->zirkonyum = $catalog->resolveOrCreate('Zirkonyum Kaplama', 'dental', actor: $this->admin)['service'];
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['name' => 'Panorama AŞ'])->id, 'name' => 'Panorama Ankara', 'sector_id' => $this->dental->id]);
        $this->site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'name' => 'panorama.com.tr']);
        $this->gsc = CoreExternalResource::factory()->searchConsole()->create(['external_id' => 'sc-domain:panorama.com.tr']);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $this->site->id, 'external_resource_id' => $this->gsc->id, 'capability' => 'search_console']);
        config(['moxdop.anthropic.api_key' => 'test-anthropic-value']);
        CoreIntegration::factory()->anthropic()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        Http::preventStrayRequests();
    }

    public function test_step_one_ai_fills_only_empty_sectors_in_one_call_and_saves_only_on_approve(): void
    {
        $empty = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Gülüş Kliniği', 'sector_id' => null]);
        $profile = DigitalAsset::factory()->create(['brand_id' => $empty->id, 'type' => 'google_business_profile', 'name' => 'Gülüş GBP']);
        $gbp = CoreExternalResource::factory()->create(['resource_type' => 'google_business_profile', 'external_id' => 'locations/77', 'metadata' => ['primary_category' => 'Saç ekimi kliniği']]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $profile->id, 'external_resource_id' => $gbp->id, 'capability' => 'google_business_profile']);
        $vet = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Pati Veteriner', 'sector_id' => null]);
        CoreExternalResource::factory()->create(['resource_type' => 'google_ads', 'external_id' => '555', 'display_name' => 'Serbest hesap']);
        $prompts = [];
        QueryPlanSectorsAgent::fake(function (string $prompt) use (&$prompts, $empty, $vet, $profile): array {
            $prompts[] = $prompt;

            return [
                'brands' => [
                    ['brand_id' => $empty->id, 'sector_id' => $this->dental->id, 'new_sector' => null, 'reason' => 'Site başlığı'],
                    ['brand_id' => $vet->id, 'sector_id' => null, 'new_sector' => 'Veteriner', 'reason' => 'Ad'],
                    ['brand_id' => $this->brand->id, 'sector_id' => $this->hair->id, 'new_sector' => null, 'reason' => 'dolu marka'],
                    ['brand_id' => 999999, 'sector_id' => $this->hair->id, 'new_sector' => null, 'reason' => 'bilinmeyen'],
                ],
                'assets' => [
                    ['asset_id' => $profile->id, 'sector_id' => $this->hair->id, 'new_sector' => null, 'reason' => 'İşletme Profili kategorisi'],
                    ['asset_id' => $this->site->id, 'sector_id' => $this->hair->id, 'new_sector' => null, 'reason' => 'gönderilmedi'],
                ],
                'prompt_version' => QueryPlanSectorsAgent::PROMPT_VERSION,
            ];
        });

        $page = Livewire::test(QueryPlanWizard::class)
            ->assertSee('Panorama Ankara')->assertSee('Panorama AŞ')->assertSee('sc-domain:panorama.com.tr')
            ->assertSee('Markaya bağlı değil')->assertSee('Serbest hesap')
            ->call('runAi')
            ->assertSet('brandSectors.'.$empty->id, (string) $this->dental->id)
            ->assertSet('brandSectors.'.$vet->id, 'new:Veteriner')
            ->assertSet('brandSectors.'.$this->brand->id, (string) $this->dental->id)
            ->assertSet('assetSectors.'.$profile->id, (string) $this->hair->id)
            ->assertSet('assetSectors.'.$this->site->id, '')
            ->assertSee('Yeni sektör: Veteriner');

        $this->assertCount(1, $prompts, 'one batched call');
        $data = json_decode(substr($prompts[0], strlen("DATA_JSON\n")), true);
        $this->assertSame([$empty->id, $vet->id], array_column($data['brands'], 'id'), 'only brands without a sector are sent');
        $this->assertSame('Saç ekimi kliniği', $data['brands'][0]['assets'][0]['gbp_category']);
        $this->assertNull($empty->fresh()->sector_id, 'nothing saved before approval');
        $this->assertFalse(ServiceCategory::query()->where('name', 'Veteriner')->exists());

        // Choosing the brand's own sector for an asset stores no override.
        $page->set('assetSectors.'.$this->site->id, (string) $this->dental->id)->call('approveSectors')->assertSet('step', 2);

        $this->assertSame($this->dental->id, $empty->fresh()->sector_id);
        $newSector = ServiceCategory::query()->where('name', 'Veteriner')->sole();
        $this->assertSame($newSector->id, $vet->fresh()->sector_id);
        $this->assertSame($this->hair->id, $profile->fresh()->sector_id);
        $this->assertSame($this->hair->id, $profile->fresh()->sectorId(), 'override wins');
        $this->assertNull($this->site->fresh()->sector_id);
        $this->assertSame($this->dental->id, $this->site->fresh()->sectorId(), 'inherits the brand');

        // Picking the brand's sector again clears an override.
        Livewire::test(QueryPlanWizard::class)->set('assetSectors.'.$profile->id, (string) $this->dental->id)->call('approveSectors');
        $this->assertNull($profile->fresh()->sector_id);
    }

    public function test_step_two_applies_ticked_service_and_keyword_changes_keeping_keywords_unique_per_sector(): void
    {
        $keywords = app(ServiceKeywordService::class);
        $keywords->replace($this->implant, "implant\nkaplama");
        $this->sources(['implant fiyatları' => 100, 'zirkonyum kaplama fiyatı' => 50, 'diş beyazlatma' => 30]);
        $kaplama = ServiceMatchingKeyword::query()->where('normalized_key', 'kaplama')->sole();
        QueryPlanServicesAgent::fake(fn (): array => [
            'new_services' => [
                ['sector_id' => $this->dental->id, 'name' => 'Diş Beyazlatma', 'keywords' => ['beyazlatma', 'bleaching', 'fiyat'], 'reason' => 'Sorgularda var'],
                ['sector_id' => $this->dental->id, 'name' => 'Diş implantı', 'keywords' => [], 'reason' => 'zaten var'],
                ['sector_id' => 424242, 'name' => 'Bilinmeyen', 'keywords' => [], 'reason' => '-'],
            ],
            'add_keywords' => [
                ['service_id' => $this->zirkonyum->id, 'keyword' => 'Zirkonyum', 'reason' => 'Hizmet adı'],
                ['service_id' => $this->zirkonyum->id, 'keyword' => 'implant', 'reason' => 'sektörde başka hizmette'],
                ['service_id' => $this->zirkonyum->id, 'keyword' => 'fiyat', 'reason' => 'genel'],
            ],
            'remove_keywords' => [['keyword_id' => 999999, 'reason' => 'bilinmeyen']],
            'move_keywords' => [['keyword_id' => $kaplama->id, 'to_service_id' => $this->zirkonyum->id, 'reason' => 'Kaplama zirkonyuma ait']],
            'prompt_version' => QueryPlanServicesAgent::PROMPT_VERSION,
        ]);

        $page = Livewire::test(QueryPlanWizard::class)->call('goTo', 2)
            ->assertSee('Diş İmplantı')->assertSee('Zirkonyum Kaplama')
            ->call('runAi')
            ->assertSee('Yeni hizmet: Diş Beyazlatma')->assertSee('(beyazlatma, bleaching)')->assertSee('+ zirkonyum')->assertDontSee('Bilinmeyen')
            ->assertSet('pick', [0 => true, 1 => true, 2 => true]);

        $this->assertSame(['implant', 'kaplama'], $this->implant->matchingKeywords()->orderBy('normalized_key')->pluck('normalized_key')->all(), 'nothing applied yet');

        $page->set('pick.1', false)->call('approveServices')->assertSet('step', 3);

        $whitening = ServiceCatalogName::query()->where('raw_label', 'Diş Beyazlatma')->sole()->service;
        $this->assertSame('dental', $whitening->sector);
        $this->assertSame(['beyazlatma', 'bleaching'], $whitening->matchingKeywords()->orderBy('normalized_key')->pluck('normalized_key')->all(), 'sector knowledge is kept, generic words dropped');
        $this->assertSame(['kaplama'], $this->zirkonyum->matchingKeywords()->pluck('normalized_key')->all(), 'unticked add skipped, move applied');
        $this->assertSame(['implant'], $this->implant->matchingKeywords()->pluck('normalized_key')->all());

        // Inline edit keeps the rule: a keyword belongs to one service per sector.
        Livewire::test(QueryPlanWizard::class)->call('goTo', 2)
            ->set('newKeyword.'.$this->implant->id, 'Kaplama')->call('addKeyword', $this->implant->id)->assertHasErrors('newKeyword.'.$this->implant->id)
            ->set('newKeyword.'.$this->implant->id, 'dental implant')->call('addKeyword', $this->implant->id)->assertHasNoErrors();
        $this->assertTrue($this->implant->matchingKeywords()->where('normalized_key', 'dental implant')->exists());
    }

    public function test_step_two_calls_ai_per_sector_and_fills_a_sector_without_services_or_data(): void
    {
        Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'sector_id' => $this->hair->id]);
        $prompts = [];
        QueryPlanServicesAgent::fake(function (string $prompt) use (&$prompts): array {
            $prompts[] = $prompt;
            $sector = json_decode(substr($prompt, strlen("DATA_JSON\n")), true)['sectors'][0];
            if ($sector['id'] === $this->dental->id) {
                throw new RuntimeException('sağlayıcı hatası');
            }

            return ['new_services' => [
                ['sector_id' => $sector['id'], 'name' => 'FUE Saç Ekimi', 'keywords' => ['fue', 'fue saç ekimi'], 'reason' => 'sektör bilgisi'],
                ['sector_id' => $sector['id'], 'name' => 'DHI Saç Ekimi', 'keywords' => ['dhi', 'kalem tekniği'], 'reason' => 'sektör bilgisi'],
            ], 'add_keywords' => [], 'remove_keywords' => [], 'move_keywords' => [], 'prompt_version' => QueryPlanServicesAgent::PROMPT_VERSION];
        });

        Livewire::test(QueryPlanWizard::class)->call('goTo', 2)->call('runAi')
            ->assertSee('Yeni hizmet: FUE Saç Ekimi')->assertSee('(dhi, kalem tekniği)')
            ->assertSee('Yanıt alınamayan sektörler: Diş sağlığı');

        $this->assertCount(2, $prompts, 'one call per used sector');
        $hairData = json_decode(substr($prompts[1], strlen("DATA_JSON\n")), true)['sectors'][0];
        $this->assertSame([[], []], [$hairData['services'], $hairData['samples']], 'a sector without services or collected queries is still asked');
    }

    public function test_step_three_proposes_valid_terms_and_the_first_import_filters_across_sectors_and_notifies(): void
    {
        app(ServiceKeywordService::class)->replace($this->implant, 'implant');
        $hairBrand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'sector_id' => $this->hair->id]);
        $hairSite = DigitalAsset::factory()->create(['brand_id' => $hairBrand->id, 'type' => 'website']);
        $hairGsc = CoreExternalResource::factory()->searchConsole()->create();
        CoreAssetBinding::factory()->create(['digital_asset_id' => $hairSite->id, 'external_resource_id' => $hairGsc->id, 'capability' => 'search_console']);
        $this->sources(['implant fiyatları' => 100, 'implant iş ilanı' => 40, 'diş hekimi maaşları' => 30, 'implant forum' => 20], run: false);
        $this->sources(['saç ekimi forum' => 50, 'saç ekimi fiyatları' => 60], $hairGsc, run: false);
        $prompts = [];
        QueryPlanFiltersAgent::fake(function (string $prompt) use (&$prompts): array {
            $prompts[] = $prompt;

            return ['terms' => [
                ['sector_id' => $this->dental->id, 'term' => 'İş İlanı', 'reason' => 'İş arayan'],
                ['sector_id' => $this->dental->id, 'term' => 'maaş', 'reason' => 'İş arayan'],
                ['sector_id' => $this->dental->id, 'term' => 'implant', 'reason' => 'hizmet kelimesi'],
                ['sector_id' => $this->dental->id, 'term' => 'uzay', 'reason' => 'örneklerde yok'],
                ['sector_id' => $this->hair->id, 'term' => 'forum', 'reason' => 'Forum'],
                ['sector_id' => 424242, 'term' => 'x', 'reason' => '-'],
            ], 'prompt_version' => QueryPlanFiltersAgent::PROMPT_VERSION];
        });

        $page = Livewire::test(QueryPlanWizard::class)->call('goTo', 3)->call('runAi')
            ->assertSee('iş ilanı')->assertSee('maaş')->assertSee('forum')->assertSee('uzay');
        $this->assertCount(2, $prompts, 'one call per used sector');
        $this->assertSame(0, FilterTerm::query()->count(), 'nothing saved before approval');
        $this->assertSame(0, Query::query()->count());

        $page->set('pick.1', false)->set('pick.2', false)->call('approveFilters')->assertRedirect(route('operator.library.queries'));

        $this->assertSame(['forum', 'iş ilanı'], FilterTerm::query()->orderBy('term')->pluck('term')->all());
        $this->assertNotNull(QueryPipeline::importedAt());
        $this->assertSame(['diş hekimi maaşları', 'implant fiyatları', 'saç ekimi fiyatları'], Query::query()->orderBy('text')->pluck('text')->all(),
            'containing queries deleted (the hair term also deletes the dental "implant forum"), unticked "maaş" not a filter');
        $this->assertSame($this->implant->id, Query::query()->where('text', 'implant fiyatları')->value('service_id'));
        $notice = UserNotification::query()->where('recipient_user_id', $this->admin->id)->where('notification_kind', NotificationKind::QueriesNotice->value)->sole();
        $this->assertSame('İlk içe aktarma tamamlandı: 3 sorgu', $notice->presentation['title']);
        $this->assertSame(route('operator.library.queries', [], false), $notice->presentation['url']);
    }

    public function test_a_failed_ai_step_is_reported_and_notified(): void
    {
        QueryPlanSectorsAgent::fake(fn () => throw new RuntimeException('sağlayıcı hatası'));
        Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'sector_id' => null]);

        Livewire::test(QueryPlanWizard::class)->call('runAi')->assertSee('AI adımı başarısız');

        $notice = UserNotification::query()->where('recipient_user_id', $this->admin->id)->sole();
        $this->assertSame('AI adımı başarısız: Sektör ata', $notice->presentation['title']);
        $this->get(route('operator.library.queries.plan'))->assertOk()->assertSee('AI ile sektör ata');
    }

    /** @param array<string, int> $rows raw query => impressions */
    private function sources(array $rows, ?CoreExternalResource $resource = null, bool $run = true): void
    {
        foreach ($rows as $raw => $impressions) {
            DB::table('query_sources')->insert([
                'external_resource_id' => ($resource ?? $this->gsc)->id, 'source' => 'gsc', 'raw_query' => $raw, 'month' => '2026-08-01',
                'impressions' => $impressions, 'clicks' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        if ($run) {
            app(QueryPipeline::class)->run(import: true);
        }
    }
}
