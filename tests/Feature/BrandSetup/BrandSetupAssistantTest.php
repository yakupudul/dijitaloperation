<?php

namespace Tests\Feature\BrandSetup;

use App\Ai\Agents\BrandSetupAgent;
use App\Jobs\Site\RunSiteOperationJob;
use App\Livewire\Operator\Portfolio\BrandSetupPage;
use App\Models\AiTask;
use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\BrandSetupProposal;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\BrandIntelligence\BrandOfferingService;
use App\Services\BrandSetup\BrandSetupAssistant;
use App\Services\BrandSetup\BrandSetupMatcher;
use App\Services\Catalog\ServiceCatalogService;
use App\Services\Portfolio\UnassignedWebsites;
use App\Services\Prompts\PromptRegistry;
use App\Services\Site\SiteOperations;
use App\Support\Ai\AiRouteKeys;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\InsertsFacts;
use Tests\TestCase;

/**
 * Faz 1b: "Otomatik kur" — domain / GA4-stream / name matching, AI service proposal checked against the
 * catalog, and one-click application through the existing binding and offering services.
 */
final class BrandSetupAssistantTest extends TestCase
{
    use InsertsFacts;
    use RefreshDatabase;

    private User $admin;

    private Brand $brand;

    private CoreIntegration $google;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->seed(RoleAndPermissionSeeder::class);
        config([
            'moxdop.google.client_id' => 'cid', 'moxdop.google.client_secret' => 'csecret', 'moxdop.google.developer_token' => 'dev',
            'moxdop-seo-tasks.llm.enabled' => false,
            'moxdop.anthropic.api_key' => 'sk-ant-test',
        ]);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);

        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Adadent']);
        $this->google = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $this->google->id, 'encrypted_payload' => ['client_id' => 'cid', 'client_secret' => 'csecret', 'developer_token' => 'dev']]);
        CoreIntegrationCredential::factory()->authorization()->create(['integration_id' => $this->google->id, 'encrypted_payload' => ['access_token' => 'atok', 'refresh_token' => 'rtok'], 'expires_at' => now()->addHour()]);
        CoreIntegration::factory()->anthropic()->create(['status' => CoreIntegration::STATUS_ACTIVE]);

        Http::preventStrayRequests();
        Http::fake([
            'analyticsadmin.googleapis.com/*properties/111/dataStreams*' => Http::response(['dataStreams' => [['webStreamData' => ['defaultUri' => 'https://www.adadent.com.tr']]]]),
            'analyticsadmin.googleapis.com/*' => Http::response(['dataStreams' => [['webStreamData' => ['defaultUri' => 'https://baska.com']]]]),
        ]);
    }

    public function test_matcher_proposes_domain_matches_ticked_and_name_matches_for_review(): void
    {
        $this->resources();

        $items = collect(app(BrandSetupMatcher::class)->propose($this->brand, 'https://www.adadent.com.tr/'))->keyBy('key');

        $this->assertSame('proposed', $items['asset:website']['status']);
        $gsc = $items->firstWhere('capability', 'search_console');
        $this->assertSame('sc-domain:adadent.com.tr', CoreExternalResource::query()->find($gsc['resource_id'])->external_id);
        $this->assertTrue($gsc['selected']);
        $ga4 = $items->firstWhere('capability', 'ga4');
        $this->assertSame('properties/111', CoreExternalResource::query()->find($ga4['resource_id'])->external_id, 'matched by web stream URL, not by name');
        $this->assertTrue($ga4['selected']);
        $this->assertSame(['https://www.adadent.com.tr'], CoreExternalResource::query()->find($ga4['resource_id'])->metadata['web_stream_uris'], 'stream lookup cached');
        $this->assertTrue($items->firstWhere('capability', 'google_business_profile')['selected']);

        $ads = $items->where('capability', 'google_ads');
        $this->assertCount(1, $ads, 'manager and unrelated accounts are not proposed');
        $this->assertTrue($ads->first()['selected'], 'account name contains the domain root');
        $this->assertSame(0, $items->where('capability', 'search_console')->where('selected', true)->count() - 1, 'only one Search Console property ticked');
    }

    public function test_full_flow_builds_proposal_and_one_click_approval_applies_it(): void
    {
        [$gscResource] = $this->resources();
        $category = ServiceCategory::query()->create(['code' => 'saglik', 'name' => 'Sağlık', 'normalized_key' => 'saglik']);
        app(ServiceCatalogService::class)->resolveOrCreate('İmplant Tedavisi', 'saglik', actor: $this->admin);
        $this->insertFacts('gsc_query_page_daily', [
            'digital_asset_id' => null, 'external_resource_id' => $gscResource->id, 'site_url' => 'sc-domain:adadent.com.tr',
            'reporting_date' => now()->subDays(5)->toDateString(), 'query' => 'ankara implant', 'page' => 'https://www.adadent.com.tr/implant/',
            'clicks' => 3, 'impressions' => 400, 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
            'record_fingerprint' => hash('sha256', 'x'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        BrandSetupAgent::fake([[
            'brand_summary' => 'Ankara Çankaya\'da implant ve gülüş tasarımı yapan diş kliniği.',
            'sector_code' => 'saglik',
            'services' => [
                ['name' => 'İmplant Tedavisi', 'catalog_name' => 'İmplant Tedavisi', 'sector_code' => 'saglik', 'aliases' => ['Diş İmplantı'], 'is_core' => true, 'evidence' => 'Sorgu: ankara implant'],
                ['name' => 'Gülüş Tasarımı', 'catalog_name' => null, 'sector_code' => 'saglik', 'aliases' => [], 'is_core' => false, 'evidence' => 'Sayfa başlığı'],
                ['name' => 'Uydurma', 'catalog_name' => 'Katalogda olmayan ad', 'sector_code' => 'yok', 'aliases' => [], 'is_core' => false, 'evidence' => '-'],
            ],
            'prompt_version' => BrandSetupAgent::PROMPT_VERSION,
        ]]);

        $page = Livewire::test(BrandSetupPage::class, ['brand' => (string) $this->brand->id])
            ->set('websiteUrl', 'adadent.com.tr')
            ->call('start')
            ->assertSeeHtml('data-setup-warnings')->assertSee('Bu adres için bağlı bir web sitesi yok');
        $this->assertSame(0, BrandSetupProposal::query()->count(), 'nothing runs before "Yine de getir"');
        $page->call('start', true)->assertDontSeeHtml('data-setup-warnings');

        $proposal = BrandSetupProposal::query()->firstOrFail();
        $this->assertSame(BrandSetupProposal::STATUS_READY, $proposal->status, (string) $proposal->error_summary);
        $services = collect($proposal->services)->keyBy('name');
        $this->assertFalse($services['İmplant Tedavisi']['is_new']);
        $this->assertTrue($services['Gülüş Tasarımı']['is_new']);
        $this->assertTrue($services['Uydurma']['is_new'], 'unknown catalog name is not trusted');
        $this->assertFalse($services['Uydurma']['selected'], 'no valid sector → not ticked');
        $this->assertTrue($services['İmplant Tedavisi']['selected'], 'found in a query');
        $this->assertFalse($services['Gülüş Tasarımı']['selected'], 'no page or query shows it: not ticked');
        $this->assertStringStartsWith('Kanıt yok', $services['Gülüş Tasarımı']['evidence']);

        $page->call('$refresh')->assertSee('Varlıklar ve hesap bağlantıları')->assertSee('Katalogda var')->call('approve');

        $proposal->refresh();
        $this->assertSame(BrandSetupProposal::STATUS_APPLIED, $proposal->status);
        $website = DigitalAsset::query()->where('brand_id', $this->brand->id)->where('type', 'website')->firstOrFail();
        $this->assertSame('https://adadent.com.tr/', $website->primary_url);
        $bound = CoreAssetBinding::query()->where('digital_asset_id', $website->id)->where('status', 'active')->pluck('capability')->sort()->values()->all();
        $this->assertSame(['ga4', 'search_console'], $bound);
        $this->assertTrue(DigitalAsset::query()->where('brand_id', $this->brand->id)->where('type', 'google_business_profile')->exists());
        $this->assertTrue(DigitalAsset::query()->where('brand_id', $this->brand->id)->where('type', 'google_ads')->exists());

        $offerings = BrandOffering::query()->with('primaryName')->where('brand_id', $this->brand->id)->get()->keyBy(fn ($o) => $o->primaryName?->raw_label);
        $this->assertTrue((bool) $offerings['İmplant Tedavisi']->is_priority);
        $this->assertArrayNotHasKey('Gülüş Tasarımı', $offerings->all(), 'an unticked service is not added');
        $this->assertSame(1, ServiceCatalogItem::query()->whereHas('names', fn ($q) => $q->where('raw_label', 'İmplant Tedavisi'))->count(), 'catalog not duplicated');
        $this->assertSame($category->id, $this->brand->fresh()->sector_id);
        $this->assertTrue(collect($proposal->apply_result)->every(fn (array $r): bool => $r['ok']), json_encode($proposal->apply_result));
    }

    public function test_wordpress_page_titles_drive_services_and_business_context_fills_only_empty_fields(): void
    {
        ServiceCategory::query()->create(['code' => 'saglik', 'name' => 'Sağlık', 'normalized_key' => 'saglik']);
        $site = DigitalAsset::query()->create(['brand_id' => null, 'name' => 'adadent.com.tr', 'type' => 'website', 'status' => 'active', 'module_id' => 'website', 'domain' => 'adadent.com.tr', 'primary_url' => 'https://adadent.com.tr']);
        foreach ([[1, 'İmplant Tedavisi', null], [2, 'Zirkonyum Kaplama', null], [3, 'Hakkımızda', null], [4, 'All-on-4', 1]] as [$id, $title, $parent]) {
            DB::table('website_cms_object_snapshot')->insert(['digital_asset_id' => $site->id, 'cms' => 'wordpress', 'object_type' => 'page', 'object_id' => (string) $id, 'status' => 'publish',
                'title' => $title, 'permalink' => 'https://adadent.com.tr/p'.$id.'/', 'parent_id' => $parent, 'observed_at' => now(), 'contract_version' => 1,
                'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => hash('sha256', 'p'.$id), 'created_at' => now(), 'updated_at' => now()]);
        }
        app(UnassignedWebsites::class)->assign($site, $this->brand);
        $this->brand->intelligenceContext()->create(['positioning' => 'Operatörün yazdığı konumlanma', 'business_goals' => [], 'conversion_goals' => [], 'priority_offerings' => []]);
        $prompts = [];
        BrandSetupAgent::fake(function (string $prompt) use (&$prompts): array {
            $prompts[] = $prompt;

            return [
                'brand_summary' => 'Ankara diş kliniği.', 'sector_code' => 'saglik',
                'business_context' => ['business_summary' => 'Ankara\'da implant ve estetik diş tedavisi yapan klinik.', 'business_model' => 'Klinik — randevulu hizmet',
                    'target_audiences' => ['Eksik dişi olan yetişkinler'], 'positioning' => 'AI konumlanma', 'differentiators' => ['20 yıllık deneyim']],
                'services' => [['name' => 'İmplant Tedavisi', 'catalog_name' => null, 'sector_code' => 'saglik', 'aliases' => [], 'matching_phrases' => ['implant'], 'is_core' => true, 'evidence' => 'WordPress sayfası']],
                'prompt_version' => BrandSetupAgent::PROMPT_VERSION,
            ];
        });

        $page = Livewire::test(BrandSetupPage::class, ['brand' => (string) $this->brand->id])->set('websiteUrl', 'adadent.com.tr')->call('start', true);

        $this->assertStringContainsString('"wordpress_pages"', $prompts[0]);
        $this->assertStringContainsString('"title":"All-on-4","path":"/p4/","parent":"İmplant Tedavisi"', $prompts[0]);
        $proposal = BrandSetupProposal::query()->firstOrFail();
        $this->assertSame('Klinik — randevulu hizmet', data_get($proposal->summary, 'business_context.business_model'));

        $page->call('$refresh')->assertSee('İş bağlamı (siteden)')->call('approve');

        $context = $this->brand->fresh()->intelligenceContext;
        $this->assertSame('Ankara\'da implant ve estetik diş tedavisi yapan klinik.', $context->business_summary);
        $this->assertSame([['name' => 'Eksik dişi olan yetişkinler', 'note' => null]], $context->target_audiences, 'stored in the operator form shape');
        $this->assertSame('Operatörün yazdığı konumlanma', $context->positioning, 'what the operator wrote is never overwritten');
        $this->assertSame(1, DigitalAsset::query()->where('type', 'website')->count(), 'the site added under Integrations is reused, not duplicated');
    }

    public function test_services_the_brand_already_has_only_gain_missing_matching_expressions(): void
    {
        [$gscResource] = $this->resources();
        ServiceCategory::query()->create(['code' => 'saglik', 'name' => 'Sağlık', 'normalized_key' => 'saglik']);
        $item = app(ServiceCatalogService::class)->resolveOrCreate('İmplant Tedavisi', 'saglik', actor: $this->admin)['service'];
        $offering = app(BrandOfferingService::class)->resolveOrCreate($this->brand, 'İmplant Tedavisi', actor: $this->admin)['offering'];
        $this->insertFacts('gsc_query_page_daily', [
            'digital_asset_id' => null, 'external_resource_id' => $gscResource->id, 'site_url' => 'sc-domain:adadent.com.tr',
            'reporting_date' => now()->subDays(5)->toDateString(), 'query' => 'vidalı diş', 'page' => 'https://www.adadent.com.tr/implant/',
            'clicks' => 3, 'impressions' => 400, 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
            'record_fingerprint' => hash('sha256', 'z'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        BrandSetupAgent::fake([[
            'brand_summary' => 'Diş kliniği.', 'sector_code' => 'saglik',
            'services' => [['name' => 'İmplant Tedavisi', 'catalog_name' => 'İmplant Tedavisi', 'sector_code' => 'saglik', 'aliases' => [], 'matching_phrases' => ['vidalı diş', 'implant'], 'is_core' => true, 'evidence' => 'Sorgu']],
            'prompt_version' => BrandSetupAgent::PROMPT_VERSION,
        ]]);

        $proposal = app(BrandSetupAssistant::class)->queue($this->brand, 'adadent.com.tr', $this->admin)->fresh();
        $this->assertSame('already', $proposal->services[0]['status']);
        $this->assertTrue($proposal->services[0]['selected'], 'additive: pre-ticked, still needs approval');

        Livewire::test(BrandSetupPage::class, ['brand' => (string) $this->brand->id])->call('approve');

        $this->assertSame(['implant', 'implant tedavisi', 'vidalı diş'], $item->matchingKeywords()->orderBy('label')->pluck('label')->all());
        $this->assertSame(1, BrandOffering::query()->where('brand_id', $this->brand->id)->count(), 'no duplicate offering');
        $this->assertFalse((bool) $offering->fresh()->is_priority, 'operator priority is not overwritten');
    }

    public function test_delegated_service_suggestion_waits_for_claude_without_getting_stuck(): void
    {
        [$gscResource] = $this->resources();
        ServiceCategory::query()->create(['code' => 'saglik', 'name' => 'Sağlık', 'normalized_key' => 'saglik']);
        $this->insertFacts('gsc_query_page_daily', [
            'digital_asset_id' => null, 'external_resource_id' => $gscResource->id, 'site_url' => 'sc-domain:adadent.com.tr',
            'reporting_date' => now()->subDays(5)->toDateString(), 'query' => 'ankara implant', 'page' => 'https://www.adadent.com.tr/implant/',
            'clicks' => 3, 'impressions' => 400, 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
            'record_fingerprint' => hash('sha256', 'w'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        config(['moxdop-mcp.token' => 'test-mcp-token']);
        $registry = app(PromptRegistry::class);
        $registry->publish(AiRouteKeys::BRAND_SETUP, ['template' => (string) $registry->current(AiRouteKeys::BRAND_SETUP)->template, 'model' => AiTaskQueue::MODEL], $this->admin);
        BrandSetupAgent::fake()->preventStrayPrompts();

        $proposal = app(BrandSetupAssistant::class)->queue($this->brand, 'adadent.com.tr', $this->admin)->fresh();

        $this->assertSame(BrandSetupProposal::STATUS_BUILDING, $proposal->status);
        $this->assertTrue($proposal->waitsForClaude());
        $task = AiTask::query()->sole();
        $this->assertStringStartsWith('CONTEXT_JSON', $task->input);
        $this->travel(2)->hours();
        $this->assertFalse($proposal->fresh()->isStuck(), 'waiting for Claude is not a dead worker');
        $this->assertSame($proposal->id, app(BrandSetupAssistant::class)->queue($this->brand, 'adadent.com.tr', $this->admin)->id, 'a second click follows the waiting build');
        Livewire::test(BrandSetupPage::class, ['brand' => (string) $this->brand->id])->assertSee("Claude'da bekliyor", false)->assertSeeHtml('data-setup-progress="services"');
        BrandSetupAgent::assertNeverPrompted();

        $this->assertSame([], app(AiTaskQueue::class)->submit($task, ['brand_summary' => 'Diş kliniği.', 'sector_code' => 'saglik',
            'services' => [['name' => 'İmplant Tedavisi', 'catalog_name' => null, 'sector_code' => 'saglik', 'aliases' => [], 'matching_phrases' => ['implant'], 'is_core' => true, 'evidence' => 'Sorgu']],
            'business_context' => ['business_summary' => null, 'business_model' => null, 'target_audiences' => [], 'positioning' => null, 'differentiators' => []],
            'prompt_version' => BrandSetupAgent::PROMPT_VERSION]));

        $proposal = $proposal->fresh();
        $this->assertSame(BrandSetupProposal::STATUS_READY, $proposal->status);
        $this->assertSame('İmplant Tedavisi', $proposal->services[0]['name']);
        $this->assertSame(AiTaskQueue::PROVIDER, $proposal->summary['provider']);
    }

    public function test_ai_failure_is_shown_to_the_operator_and_accounts_are_still_proposed(): void
    {
        $this->resources();
        $this->insertFacts('gsc_query_page_daily', [
            'digital_asset_id' => null, 'external_resource_id' => CoreExternalResource::query()->where('resource_type', 'search_console')->value('id'), 'site_url' => 'sc-domain:adadent.com.tr',
            'reporting_date' => now()->subDays(5)->toDateString(), 'query' => 'ankara implant', 'page' => 'https://www.adadent.com.tr/implant/',
            'clicks' => 3, 'impressions' => 400, 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
            'record_fingerprint' => hash('sha256', 'y'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        BrandSetupAgent::fake(fn () => throw new \RuntimeException('cURL error 28: Operation timed out'));

        $proposal = app(BrandSetupAssistant::class)->queue($this->brand, 'adadent.com.tr', $this->admin)->fresh();

        $this->assertSame(BrandSetupProposal::STATUS_READY, $proposal->status);
        $this->assertSame('ai_unavailable', $proposal->services_status);
        $this->assertSame('llm_error', $proposal->summary['ai_skipped_reason']);
        $this->assertNotEmpty($proposal->items);
        Livewire::test(BrandSetupPage::class, ['brand' => (string) $this->brand->id])
            ->assertSee('AI çağrısı hata verdi')
            ->assertSee('Operation timed out');
    }

    public function test_without_site_data_services_wait_and_nothing_is_applied_without_approval(): void
    {
        $proposal = app(BrandSetupAssistant::class)->queue($this->brand, 'yeni-marka.com', $this->admin)->fresh();

        $this->assertSame('waiting_for_site', $proposal->services_status);
        $this->assertSame([], $proposal->services);
        $this->assertSame(0, DigitalAsset::query()->count(), 'building a proposal writes nothing');
        $this->assertSame(0, CoreAssetBinding::query()->count());
    }

    public function test_areas_from_the_business_profile_are_added_the_site_screen_is_prepared_and_a_different_sector_is_only_reported(): void
    {
        [$gscResource] = $this->resources();
        CoreExternalResource::query()->where('external_id', 'locations/1')->firstOrFail()
            ->forceFill(['metadata' => ['website_uri' => 'https://adadent.com.tr/', 'selectable' => true, 'storefront_address' => ['regionCode' => 'TR', 'administrativeArea' => 'Ankara', 'locality' => 'Çankaya']]])->save();
        $other = ServiceCategory::query()->create(['code' => 'guzellik', 'name' => 'Güzellik', 'normalized_key' => 'guzellik']);
        ServiceCategory::query()->create(['code' => 'saglik', 'name' => 'Sağlık', 'normalized_key' => 'saglik']);
        $this->brand->forceFill(['sector_id' => $other->id])->save();
        $this->insertFacts('gsc_query_page_daily', [
            'digital_asset_id' => null, 'external_resource_id' => $gscResource->id, 'site_url' => 'sc-domain:adadent.com.tr',
            'reporting_date' => now()->subDays(5)->toDateString(), 'query' => 'implant fiyatları', 'page' => 'https://www.adadent.com.tr/implant/',
            'clicks' => 3, 'impressions' => 400, 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
            'record_fingerprint' => hash('sha256', 'y'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        BrandSetupAgent::fake([['brand_summary' => 'Diş kliniği.', 'sector_code' => 'saglik',
            'services' => [['name' => 'İmplant Tedavisi', 'catalog_name' => null, 'sector_code' => 'saglik', 'aliases' => [], 'is_core' => true, 'evidence' => 'Sorgu']],
            'prompt_version' => BrandSetupAgent::PROMPT_VERSION]]);
        Queue::fake([RunSiteOperationJob::class]);

        $page = Livewire::test(BrandSetupPage::class, ['brand' => (string) $this->brand->id])->set('websiteUrl', 'adadent.com.tr')->call('start', true);
        $proposal = BrandSetupProposal::query()->firstOrFail();
        $this->assertSame([['Çankaya, Ankara', 'gbp', true, true]], array_map(fn (array $a): array => [$a['label'], $a['source'], $a['physical'], $a['selected']], $proposal->summary['areas']));
        $this->assertNull(BrandSetupAssistant::progress($proposal->id), 'progress cleared when ready');

        $page->call('$refresh')->assertSee('Hizmet bölgeleri')->assertSee('Çankaya, Ankara')->assertSeeHtml('data-sector-mismatch')
            ->assertDontSee('sorgu kütüphanesine eklenir')->call('approve');

        $area = $this->brand->serviceAreas()->sole();
        $this->assertSame(['Ankara', 'Çankaya', true], [$area->city_name, $area->district_name, $area->physical_branch]);
        $this->assertSame($other->id, $this->brand->fresh()->sector_id, 'a set sector is never changed');
        $this->assertStringContainsString('«Güzellik» seçili', collect($proposal->fresh()->apply_result)->firstWhere('key', 'sector')['message']);
        $website = DigitalAsset::query()->where('brand_id', $this->brand->id)->where('type', 'website')->firstOrFail();
        Queue::assertPushed(RunSiteOperationJob::class, fn (RunSiteOperationJob $job): bool => $job->siteId === $website->id && $job->operation === SiteOperations::SETUP);
    }

    public function test_reopening_the_page_follows_the_running_build_with_its_step_and_never_starts_another(): void
    {
        $proposal = BrandSetupProposal::query()->create(['brand_id' => $this->brand->id, 'status' => BrandSetupProposal::STATUS_BUILDING, 'website_url' => 'adadent.com.tr']);
        Cache::put(BrandSetupAssistant::progressKey($proposal->id), ['step' => 'services', 'at' => now()->toIso8601String()]);

        Livewire::withQueryParams(['url' => 'adadent.com.tr'])->test(BrandSetupPage::class, ['brand' => (string) $this->brand->id])
            ->assertSeeHtml('data-setup-progress="services"')->assertSee('bu sayfadan çıkabilirsin')->assertSee('Hazırlanıyor…');
        $this->assertSame(1, BrandSetupProposal::query()->count(), 'opening the page again does not queue a new build');

        // The button while it runs returns the same proposal.
        Livewire::test(BrandSetupPage::class, ['brand' => (string) $this->brand->id])->set('websiteUrl', 'adadent.com.tr')->call('start');
        $this->assertSame(1, BrandSetupProposal::query()->count());
    }

    /** @return list<CoreExternalResource> */
    private function resources(): array
    {
        $make = fn (string $type, string $externalId, string $name, array $meta = []) => CoreExternalResource::factory()->create([
            'integration_id' => $this->google->id, 'provider' => 'google', 'resource_type' => $type, 'external_id' => $externalId,
            'display_name' => $name, 'metadata' => $meta + ['selectable' => true], 'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);

        $gsc = $make('search_console', 'sc-domain:adadent.com.tr', 'adadent.com.tr', ['site_url' => 'sc-domain:adadent.com.tr', 'property_form' => 'domain']);
        $make('search_console', 'https://baska.com/', 'baska.com', ['site_url' => 'https://baska.com/', 'property_form' => 'url_prefix']);
        $make('ga4', 'properties/222', 'Adadent eski mülk', ['property_id' => '222']);
        $make('ga4', 'properties/111', 'Web', ['property_id' => '111']);
        $make('google_business_profile', 'locations/1', 'Adadent Ağız ve Diş Sağlığı', ['website_uri' => 'https://adadent.com.tr/']);
        $make('google_ads', '1234567890', 'Adadent Diş Kliniği', ['descriptive_name' => 'Adadent Diş Kliniği', 'is_manager' => false]);
        $make('google_ads', '999', 'Moximu MCC', ['descriptive_name' => 'Adadent MCC', 'is_manager' => true]);
        $make('google_ads', '555', 'Başka Firma', ['descriptive_name' => 'Başka Firma', 'is_manager' => false]);

        return [$gsc];
    }
}
