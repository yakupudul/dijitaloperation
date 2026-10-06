<?php

namespace Tests\Feature\Gbp;

use App\Ai\Agents\GbpProfilePlanAgent;
use App\Enums\CustomerStatus;
use App\Livewire\Demo\Gbp\OverviewPage;
use App\Models\AiProduction;
use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\Catalog\ServiceCatalogService;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\Gbp\GbpAssistant;
use App\Services\Gbp\GbpCategoryCatalog;
use App\Services\Gbp\GbpProfilePlanner;
use App\Services\Integrations\Google\GoogleApiClient;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * ADR-077 "Kategori ve hizmetler": the operator's list → Google categories (only from Google's list) and service items
 * (predefined type or free-form), checked against the data; the Admin's "Gönder" adds only the chosen rows to the live
 * profile (existing ones kept, primary unchanged) and undo removes exactly those.
 */
final class GbpProfilePlanTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private DigitalAsset $asset;

    /** Live profile as the fake Google holds it. @var array<string, mixed> */
    private array $location;

    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
    private array $patches = [];

    /** Google refuses categories:batchGet and category searches with "&" (seen on a live account). */
    private bool $googleRefuses = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        config(['moxdop.anthropic.api_key' => 'test-anthropic-value', 'moxdop.google.client_id' => 'cid', 'moxdop.google.client_secret' => 'csecret']);
        CoreIntegration::factory()->anthropic()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);

        $dental = ServiceCategory::query()->firstOrCreate(['code' => 'dental'], ['name' => 'Diş sağlığı', 'normalized_key' => 'dis sagligi']);
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id, 'name' => 'Panorama Ankara', 'sector_id' => $dental->id]);
        foreach (['Diş İmplantı', 'Zirkonyum Kaplama'] as $name) {
            $service = app(ServiceCatalogService::class)->resolveOrCreate($name, 'dental', actor: $this->admin)['service'];
            BrandOffering::query()->create(['brand_id' => $brand->id, 'service_catalog_item_id' => $service->id, 'status' => 'active', 'priority' => 'main', 'locked' => true]);
        }
        $this->asset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'google_business_profile', 'status' => 'active', 'name' => 'Panorama Çankaya']);
        $google = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $google->id, 'encrypted_payload' => ['client_id' => 'cid', 'client_secret' => 'csecret']]);
        CoreIntegrationCredential::factory()->authorization()->create(['integration_id' => $google->id, 'encrypted_payload' => ['access_token' => 'a', 'refresh_token' => 'r'], 'expires_at' => now()->addHour()]);
        $resource = CoreExternalResource::factory()->create(['integration_id' => $google->id, 'provider' => 'google', 'resource_type' => 'google_business_profile',
            'external_id' => 'accounts/11/locations/22', 'status' => CoreExternalResource::STATUS_AVAILABLE]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $this->asset->id, 'external_resource_id' => $resource->id, 'capability' => 'google_business_profile', 'status' => CoreAssetBinding::STATUS_ACTIVE]);

        $this->location = [
            'categories' => [
                'primaryCategory' => ['name' => 'categories/gcid:dental_clinic', 'displayName' => 'Diş kliniği'],
                'additionalCategories' => [['name' => 'categories/gcid:orthodontist', 'displayName' => 'Ortodontist']],
            ],
            'serviceItems' => [['freeFormServiceItem' => ['category' => 'categories/gcid:dental_clinic', 'label' => ['displayName' => 'Diş İmplantı', 'languageCode' => 'tr']]]],
        ];
        Http::fake(function (Request $request) {
            $url = $request->url();
            if ($request->method() === 'PATCH') {
                $this->patches[] = [$request->method(), $url, $request->data()];
                foreach ($request->data() as $field => $value) {
                    $this->location[$field] = $value;
                }

                return Http::response($this->location);
            }
            if (str_contains($url, 'categories:batchGet')) {
                if ($this->googleRefuses || ! str_contains($url, 'names=categories%2Fgcid%3Adental_clinic') || ! str_contains($url, 'view=FULL')) {
                    return Http::response(['error' => ['message' => 'Request contains an invalid argument.']], 400);
                }

                return Http::response(['categories' => [
                    ['name' => 'categories/gcid:dental_clinic', 'displayName' => 'Diş kliniği', 'serviceTypes' => [['serviceTypeId' => 'job_type_id:teeth_whitening', 'displayName' => 'Diş beyazlatma']]],
                    ['name' => 'categories/gcid:orthodontist', 'displayName' => 'Ortodontist', 'serviceTypes' => []],
                ]]);
            }
            if (str_contains($url, '/v1/categories?')) {
                if ($this->googleRefuses && preg_match('/filter=[^&]*(%20|\+)/', $url) === 1) {
                    return Http::response(['error' => ['message' => 'Request contains an invalid argument.']], 400);
                }

                return Http::response(['categories' => str_contains(urldecode($url), 'Pedodontist')
                    ? [['name' => 'categories/gcid:pediatric_dentist', 'displayName' => 'Pedodontist', 'serviceTypes' => []]] : []]);
            }
            if (str_contains($url, '/v1/locations/22')) {
                return Http::response($this->location);
            }

            return Http::response(['error' => ['message' => 'unexpected '.$url]], 500);
        });
    }

    private function page(): Testable
    {
        return Livewire::actingAs($this->admin)->test(OverviewPage::class, ['assetId' => (string) $this->asset->id, 'tab' => 'services']);
    }

    private function fakePlan(): void
    {
        GbpProfilePlanAgent::fake([[
            'categories' => [
                ['line' => 'Pedodontist', 'category_id' => 'categories/gcid:pediatric_dentist', 'reason' => 'Çocuk diş hekimliği için.'],
                ['line' => 'Uçan halı', 'category_id' => 'categories/gcid:made_up', 'reason' => 'x'],
            ],
            'services' => [
                ['line' => 'diş beyazlatma', 'category_id' => 'categories/gcid:dental_clinic', 'service_type_id' => 'job_type_id:teeth_whitening', 'name' => 'Beyazlatma yap', 'description' => 'Dişlerin rengini açan işlem.', 'reason' => 'Hazır hizmet.'],
                ['line' => 'Zirkonyum Kaplama', 'category_id' => 'categories/gcid:dental_clinic', 'service_type_id' => '', 'name' => 'Zirkonyum kaplama', 'description' => 'Metal desteksiz, doğal görünümlü diş kaplaması. Bizi 0312 555 55 55 numarasından arayın.', 'reason' => 'Özel hizmet.'],
                ['line' => 'Çocuk diş hekimliği', 'category_id' => 'categories/gcid:pediatric_dentist', 'service_type_id' => 'job_type_id:invented', 'name' => 'Çocuk diş hekimliği', 'description' => 'Çocuklara yönelik diş tedavileri.', 'reason' => 'Yeni kategori altında.'],
                ['line' => 'Diş İmplantı', 'category_id' => 'categories/gcid:dental_clinic', 'service_type_id' => '', 'name' => 'Diş İmplantı', 'description' => 'Eksik diş yerine yapay kök.', 'reason' => 'Zaten var.'],
                ['line' => 'Botoks', 'category_id' => 'categories/gcid:dermatologist', 'service_type_id' => '', 'name' => 'Botoks', 'description' => 'x', 'reason' => 'Uydurma kategori.'],
            ],
        ]]);
    }

    public function test_plan_keeps_only_google_categories_and_service_types_and_marks_what_the_profile_has(): void
    {
        $this->fakePlan();

        $page = $this->page()->set('wantCategories', "Pedodontist\nUçan halı")
            ->set('wantServices', "diş beyazlatma\nZirkonyum Kaplama\nÇocuk diş hekimliği\nDiş İmplantı\nBotoks")->call('preparePlan');

        GbpProfilePlanAgent::assertPrompted(fn ($prompt): bool => str_contains((string) $prompt->prompt, 'gcid:pediatric_dentist') && str_contains((string) $prompt->prompt, 'job_type_id:teeth_whitening'));
        $plan = AiProduction::query()->where('kind', GbpProfilePlanner::KIND)->sole();
        $content = (array) $plan->content;
        $this->assertSame([['categories/gcid:pediatric_dentist', 'new']], array_map(fn (array $c): array => [$c['id'], $c['status']], $content['categories']));
        $services = collect($content['services'])->keyBy('line');
        $this->assertSame('Diş beyazlatma', $services['diş beyazlatma']['name'], 'the predefined type keeps Google’s own name');
        $this->assertSame('job_type_id:teeth_whitening', $services['diş beyazlatma']['service_type_id']);
        $this->assertNull($services['Zirkonyum Kaplama']['service_type_id']);
        $this->assertSame('', $services['Zirkonyum Kaplama']['description'], 'a description with a phone number is dropped');
        $this->assertNull($services['Çocuk diş hekimliği']['service_type_id'], 'an invented service type falls back to free-form');
        $this->assertSame('exists', $services['Diş İmplantı']['status']);
        $this->assertSame(['Uçan halı', 'Botoks'], array_column($content['skipped'], 'line'));
        $page->call('setTab', 'services')->assertSee('Pedodontist')->assertSee('Profilde var')->assertSee('Seçilenleri gönder (4)')
            ->assertSee('1 kategori, 3 hizmet eklenmeye hazır; 2 satır atlandı.');
    }

    public function test_a_refused_category_read_does_not_stop_the_plan(): void
    {
        $this->googleRefuses = true;
        $this->fakePlan();

        $this->page()->set('wantCategories', "Pedodontist\nAğız & diş")->set('wantServices', "diş beyazlatma\nZirkonyum Kaplama")->call('preparePlan');

        $content = (array) AiProduction::query()->where('kind', GbpProfilePlanner::KIND)->sole()->content;
        $this->assertSame(['categories/gcid:pediatric_dentist'], array_column($content['categories'], 'id'));
        $services = collect($content['services'])->keyBy('line');
        $this->assertNull($services['diş beyazlatma']['service_type_id'], 'without Google’s service types the service is free-form');
        $this->assertContains('Ağız & diş', array_column($content['skipped'], 'line'));
    }

    public function test_the_tab_reads_the_profile_live_and_an_old_error_can_be_closed(): void
    {
        Cache::put(GbpAssistant::stateKey((int) $this->asset->id, GbpAssistant::OP_PROFILE),
            ['status' => 'failed', 'message' => 'Google kategori listesi okunamadı: Request contains an invalid argument.', 'at' => now()->subHours(3)->toIso8601String()], now()->addDay());

        $page = $this->page()
            ->assertSee('Google’dan canlı okundu')
            ->assertSee('Ek (1/9):')
            ->assertSee('Ortodontist')
            ->assertSee('Hizmetler (1):')
            ->assertSee('3 saat önce')
            ->assertSee('Kontrol edip gönderin');

        $this->location['categories']['additionalCategories'][] = ['name' => 'categories/gcid:pediatric_dentist', 'displayName' => 'Pedodontist'];
        $page->call('refreshLive')->assertSee('Ek (2/9):')
            ->call('dismissPlanState')->assertDontSee('Request contains an invalid argument.');
    }

    public function test_a_line_google_refuses_falls_back_to_its_longest_word(): void
    {
        $this->googleRefuses = true;
        $found = app(GbpCategoryCatalog::class)->search(app(GbpCategoryCatalog::class)->location((int) $this->asset->id)[0], '**Pedodontist (çocuk)**');

        $this->assertSame(['categories/gcid:pediatric_dentist'], array_column($found, 'id'));
    }

    public function test_send_adds_only_the_chosen_rows_and_undo_removes_exactly_them(): void
    {
        $this->fakePlan();
        $page = $this->page()->set('wantCategories', 'Pedodontist')->set('wantServices', "diş beyazlatma\nZirkonyum Kaplama\nÇocuk diş hekimliği")->call('preparePlan')
            ->call('setTab', 'services');
        $services = collect(AiProduction::query()->where('kind', GbpProfilePlanner::KIND)->sole()->content['services']);
        $zirkon = (int) $services->search(fn (array $s): bool => $s['line'] === 'Zirkonyum Kaplama');
        $whitening = (int) $services->search(fn (array $s): bool => $s['line'] === 'diş beyazlatma');

        $page->set('pickServices', [$whitening, $zirkon])->set("serviceText.$zirkon", 'Doğal görünümlü, metal desteksiz diş kaplaması.')->call('sendPlan');

        $action = ExternalWriteAction::query()->where('action', ExternalWriteAction::ACTION_PROFILE_UPDATE)->sole();
        $this->assertSame('succeeded', $action->status, (string) $action->error);
        $this->assertSame('categories/gcid:dental_clinic', $this->location['categories']['primaryCategory']['name']);
        $this->assertSame(['categories/gcid:orthodontist', 'categories/gcid:pediatric_dentist'], array_column($this->location['categories']['additionalCategories'], 'name'));
        $this->assertCount(3, $this->location['serviceItems']);
        $this->assertSame('Diş İmplantı', $this->location['serviceItems'][0]['freeFormServiceItem']['label']['displayName'], 'existing items are kept first');
        $this->assertSame(['serviceTypeId' => 'job_type_id:teeth_whitening', 'description' => 'Dişlerin rengini açan işlem.'], $this->location['serviceItems'][1]['structuredServiceItem']);
        $this->assertSame('Doğal görünümlü, metal desteksiz diş kaplaması.', $this->location['serviceItems'][2]['freeFormServiceItem']['label']['description']);
        $this->assertSame(AiProduction::STATUS_USED, AiProduction::query()->where('kind', GbpProfilePlanner::KIND)->sole()->status);

        $this->location['serviceItems'][] = ['freeFormServiceItem' => ['category' => 'categories/gcid:dental_clinic', 'label' => ['displayName' => 'Elle eklenen']]];
        $page->call('undoWrite', $action->id);

        $this->assertSame('undone', $action->refresh()->status, (string) $action->error);
        $this->assertSame(['categories/gcid:orthodontist'], array_column($this->location['categories']['additionalCategories'], 'name'));
        $this->assertSame(['Diş İmplantı', 'Elle eklenen'], array_map(fn (array $i): string => $i['freeFormServiceItem']['label']['displayName'], $this->location['serviceItems']));
    }

    public function test_send_refuses_a_service_without_its_new_category_a_compliance_hit_and_non_admins(): void
    {
        $this->fakePlan();
        $page = $this->page()->set('wantCategories', 'Pedodontist')->set('wantServices', "Zirkonyum Kaplama\nÇocuk diş hekimliği")->call('preparePlan')
            ->call('setTab', 'services');
        $services = collect(AiProduction::query()->where('kind', GbpProfilePlanner::KIND)->sole()->content['services']);
        $child = (int) $services->search(fn (array $s): bool => $s['line'] === 'Çocuk diş hekimliği');
        $zirkon = (int) $services->search(fn (array $s): bool => $s['line'] === 'Zirkonyum Kaplama');

        $page->set('pickCategories', [])->set('pickServices', [$child])->call('sendPlan')->assertSee('kategorisini de seçin');
        $page->set('pickServices', [$zirkon])->set("serviceText.$zirkon", 'Garantili ve ağrısız zirkonyum kaplama.')->call('sendPlan')->assertSee('sektör uyum kuralına takılıyor');
        $this->assertSame(0, ExternalWriteAction::query()->count());
        $this->assertSame([], $this->patches);

        $operator = User::factory()->create(['is_active' => true]);
        $operator->assignRole(Roles::TEAM_MEMBER);
        Livewire::actingAs($operator)->test(OverviewPage::class, ['assetId' => (string) $this->asset->id, 'tab' => 'services'])
            ->assertSee('Gönderimi Admin onaylar.')->call('sendPlan')->assertForbidden();
    }

    public function test_pasted_list_turns_headings_into_categories_and_rows_into_services_with_the_operators_descriptions(): void
    {
        $paste = <<<'MD'
**Diş Kliniği**

| Hizmet | Açıklama |
|---|---|
| Gülüş Tasarımı | Dişlerin şekli, rengi, dizilimi ve diş eti görünümü birlikte değerlendirilir. |
| Diş Beyazlatma | Dişlerdeki renklenmeler için hekim kontrolünde beyazlatma seçenekleri değerlendirilir. |

**Pedodontist**

| Hizmet | Açıklama |
|---|---|
| Flor Uygulaması | Çürük riskine göre diş yüzeylerine koruyucu flor uygulaması değerlendirilir. |
MD;
        $parsed = GbpProfilePlanner::parse('', $paste."\nYer Tutucu\tSüt dişinin erken kaybedildiği durumlarda boşluk korunur.");
        $this->assertSame(['Diş Kliniği', 'Pedodontist'], $parsed['categories']);
        $this->assertSame(['Gülüş Tasarımı', 'Diş Beyazlatma', 'Flor Uygulaması', 'Yer Tutucu'], array_column($parsed['services'], 'line'));
        $this->assertSame(['Diş Kliniği', 'Diş Kliniği', 'Pedodontist', 'Pedodontist'], array_column($parsed['services'], 'category'));
        $this->assertSame('Süt dişinin erken kaybedildiği durumlarda boşluk korunur.', $parsed['services'][3]['description']);

        GbpProfilePlanAgent::fake([[
            'categories' => [
                ['line' => 'Diş Kliniği', 'category_id' => 'categories/gcid:dental_clinic', 'reason' => 'Profilin birincil kategorisi.'],
                ['line' => 'Pedodontist', 'category_id' => 'categories/gcid:pediatric_dentist', 'reason' => 'Çocuk diş hekimliği.'],
            ],
            'services' => [
                ['line' => 'Gülüş Tasarımı', 'category_id' => 'categories/gcid:dental_clinic', 'service_type_id' => '', 'name' => 'Gülüş tasarımı hizmeti', 'description' => '', 'reason' => 'x'],
                ['line' => 'Diş Beyazlatma', 'category_id' => 'categories/gcid:dental_clinic', 'service_type_id' => 'job_type_id:teeth_whitening', 'name' => 'Diş Beyazlatma', 'description' => '', 'reason' => 'x'],
                ['line' => 'Flor Uygulaması', 'category_id' => 'categories/gcid:pediatric_dentist', 'service_type_id' => '', 'name' => 'Flor', 'description' => 'AI metni.', 'reason' => 'x'],
            ],
        ]]);
        $this->page()->set('wantServices', $paste)->call('preparePlan');

        GbpProfilePlanAgent::assertPrompted(fn ($prompt): bool => str_contains((string) $prompt->prompt, '"category":"Pedodontist"'));
        $content = AiProduction::query()->where('kind', GbpProfilePlanner::KIND)->sole()->content;
        $this->assertSame([['categories/gcid:dental_clinic', 'exists'], ['categories/gcid:pediatric_dentist', 'new']],
            array_map(fn (array $c): array => [$c['id'], $c['status']], $content['categories']), 'a heading naming a profile category is that category');
        $services = collect($content['services'])->keyBy('line');
        $this->assertSame('Gülüş Tasarımı', $services['Gülüş Tasarımı']['name'], 'a pasted name is kept');
        $this->assertSame('Dişlerin şekli, rengi, dizilimi ve diş eti görünümü birlikte değerlendirilir.', $services['Gülüş Tasarımı']['description']);
        $this->assertSame('Diş beyazlatma', $services['Diş Beyazlatma']['name'], 'a predefined type keeps Google’s name');
        $this->assertSame('Dişlerdeki renklenmeler için hekim kontrolünde beyazlatma seçenekleri değerlendirilir.', $services['Diş Beyazlatma']['description']);
        $this->assertSame(['Flor Uygulaması', 'Çürük riskine göre diş yüzeylerine koruyucu flor uygulaması değerlendirilir.', 'categories/gcid:pediatric_dentist'],
            [$services['Flor Uygulaması']['name'], $services['Flor Uygulaması']['description'], $services['Flor Uygulaması']['category_id']]);
    }

    public function test_write_refuses_more_than_nine_additional_categories_and_only_allows_the_two_masks(): void
    {
        $this->location['categories']['additionalCategories'] = array_map(fn (int $i): array => ['name' => 'categories/gcid:c'.$i], range(1, 9));
        $action = ExternalWriteAction::query()->create(['channel' => ExternalWriteAction::CHANNEL_GBP, 'action' => ExternalWriteAction::ACTION_PROFILE_UPDATE,
            'digital_asset_id' => $this->asset->id, 'status' => 'queued', 'requested_by' => $this->admin->id,
            'request_payload' => ['categories' => [['id' => 'categories/gcid:pediatric_dentist', 'name' => 'Pedodontist']], 'services' => []]]);

        app(ExternalWriteService::class)->execute($action);

        $this->assertSame('failed', $action->refresh()->status);
        $this->assertStringContainsString('en çok 9 ek kategori', (string) $action->error);
        $this->assertSame([], $this->patches);

        $integration = CoreIntegration::query()->where('provider', 'google')->firstOrFail();
        $this->expectException(RuntimeException::class);
        app(GoogleApiClient::class)->writeBusinessProfile($integration, 'patch', 'https://mybusinessbusinessinformation.googleapis.com/v1/locations/22?updateMask=title', ['title' => 'x']);
    }
}
