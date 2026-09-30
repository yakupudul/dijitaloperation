<?php

namespace Tests\Feature\GoogleAds;

use App\Ai\Agents\GoogleAdsAdTextsAgent;
use App\Ai\Agents\GoogleAdsSearchTermsAgent;
use App\Ai\Agents\GoogleAdsStructureAgent;
use App\Enums\CustomerStatus;
use App\Enums\DigitalAssetStatus;
use App\Livewire\Operator\GoogleAds\OverviewPage;
use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\BrandServiceArea;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\Page;
use App\Models\ServiceCategory;
use App\Models\ServiceMatchingKeyword;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\Catalog\ServiceCatalogService;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsCampaignLanguages;
use App\Services\GoogleAds\GoogleAdsAssistant;
use App\Services\GoogleAds\GoogleAdsChecks;
use App\Services\GoogleAds\GoogleAdsEditorCsv;
use App\Services\GoogleAds\GoogleAdsScreen;
use App\Services\GoogleAds\GoogleAdsSpecialistBindingResolver;
use App\Services\GoogleAds\GoogleAdsSuggestions;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Integrations\Google\GoogleScopes;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Faz 5 Google Ads screen (fake AI, faked Google HTTP): ≤ 10 system checks from collected data ("veri yok" without
 * data, never "kapat"), the search-term review validated against the data pack (invented terms, services, numbers and
 * negatives that would block converting terms are dropped), the ADR-064 shared-list send with undo, ad text limits,
 * structure validation, locked operator edits, the Google Ads Editor file and every tab rendering.
 */
final class GoogleAdsScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Brand $brand;

    private DigitalAsset $asset;

    private int $resourceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        Http::preventStrayRequests();
        config(['moxdop.google.client_id' => 'cid', 'moxdop.google.client_secret' => 'csecret', 'moxdop.google.developer_token' => 'devtoken']);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        config(['moxdop.anthropic.api_key' => 'test-anthropic-value']);
        CoreIntegration::factory()->anthropic()->create(['status' => CoreIntegration::STATUS_ACTIVE]);

        $dental = ServiceCategory::query()->firstOrCreate(['code' => 'dental'], ['name' => 'Diş sağlığı', 'normalized_key' => 'dis sagligi']);
        $customer = Customer::factory()->create(['status' => CustomerStatus::Active]);
        $this->brand = Brand::factory()->create(['customer_id' => $customer->id, 'name' => 'Panorama Ankara', 'sector_id' => $dental->id, 'languages' => ['tr', 'en']]);
        foreach ([['Diş İmplantı', 'main', 'implant'], ['Zirkonyum Kaplama', 'main', 'zirkonyum'], ['Ortodonti', 'secondary', 'ortodonti']] as [$name, $priority, $keyword]) {
            $service = app(ServiceCatalogService::class)->resolveOrCreate($name, 'dental', actor: $this->admin)['service'];
            BrandOffering::query()->create(['brand_id' => $this->brand->id, 'service_catalog_item_id' => $service->id, 'status' => 'active', 'priority' => $priority, 'locked' => true]);
            ServiceMatchingKeyword::query()->create(['service_catalog_item_id' => $service->id, 'label' => $keyword, 'normalized_key' => $keyword]);
        }
        BrandServiceArea::query()->create(['brand_id' => $this->brand->id, 'name' => 'Çankaya şubesi', 'country_code' => 'TR', 'city_name' => 'Ankara', 'district_name' => 'Çankaya',
            'normalized_key' => 'tr-ankara-cankaya', 'physical_branch' => true, 'status' => 'active']);
        $site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'name' => 'panorama.test', 'domain' => 'panorama.test', 'primary_url' => 'https://panorama.test/']);
        foreach (['/implant/' => ['Diş İmplantı Ankara', 'hizmet'], '/zirkonyum/' => ['Zirkonyum Kaplama', 'hizmet']] as $path => [$title, $category]) {
            Page::query()->create(['website_asset_id' => $site->id, 'url' => 'https://panorama.test'.$path, 'url_hash' => hash('sha256', $path), 'path' => $path,
                'title' => $title, 'category' => $category, 'language' => 'tr', 'is_indexable' => true, 'content_summary' => $title.' sayfası, 15 yıllık deneyim.']);
        }

        $this->asset = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_ads', 'module_id' => 'google_ads', 'status' => DigitalAssetStatus::Active, 'name' => 'Panorama Ads']);
        $integration = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE, 'config' => ['granted_scopes' => [GoogleScopes::ADWORDS]]]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $integration->id, 'encrypted_payload' => ['client_id' => 'cid', 'client_secret' => 'csecret', 'developer_token' => 'devtoken']]);
        CoreIntegrationCredential::factory()->authorization()->create(['integration_id' => $integration->id, 'encrypted_payload' => ['access_token' => 'a', 'refresh_token' => 'r', 'scope' => GoogleScopes::ADWORDS], 'expires_at' => now()->addHour()]);
        $resource = CoreExternalResource::factory()->create(['integration_id' => $integration->id, 'provider' => 'google', 'resource_type' => GoogleResourceType::GOOGLE_ADS_CUSTOMER,
            'external_id' => '1112223333', 'status' => CoreExternalResource::STATUS_AVAILABLE, 'metadata' => ['timezone' => 'Europe/Istanbul', 'currency' => 'TRY']]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $this->asset->id, 'external_resource_id' => $resource->id, 'capability' => GoogleAdsSpecialistBindingResolver::CAPABILITY, 'status' => CoreAssetBinding::STATUS_ACTIVE]);
        $this->resourceId = (int) $resource->id;
    }

    /** One bound account with campaigns, a conflicting negative, silent conversion tracking, search terms and landing pages. */
    private function seedAccount(): void
    {
        $this->row('google_ads_campaign_budget_snapshot', ['budget_id' => '501', 'metadata' => ['amount' => 100]]);
        $this->row('google_ads_campaign_budget_snapshot', ['budget_id' => '502', 'metadata' => ['amount' => 50]]);
        $this->row('google_ads_campaign_snapshot', ['campaign_id' => '101', 'metadata' => ['name' => 'İmplant Ankara', 'status' => 'ENABLED', 'advertising_channel_type' => 'SEARCH', 'budget_id' => '501']]);
        $this->row('google_ads_campaign_snapshot', ['campaign_id' => '102', 'metadata' => ['name' => 'Genel', 'status' => 'ENABLED', 'advertising_channel_type' => 'SEARCH', 'budget_id' => '502']]);
        $this->row('google_ads_ad_group_snapshot', ['ad_group_id' => '201', 'metadata' => ['name' => 'İmplant', 'status' => 'ENABLED', 'campaign_id' => '101']]);
        $this->row('google_ads_ad_group_snapshot', ['ad_group_id' => '202', 'metadata' => ['name' => 'Genel grup', 'status' => 'ENABLED', 'campaign_id' => '102']]);
        $this->row('google_ads_keyword_snapshot', ['ad_group_id' => '201', 'criterion_id' => '301', 'metadata' => ['keyword_text' => 'implant fiyatları', 'match_type' => 'PHRASE', 'status' => 'ENABLED', 'campaign_id' => '101']]);
        $this->row('google_ads_keyword_snapshot', ['ad_group_id' => '202', 'criterion_id' => '302', 'metadata' => ['keyword_text' => 'diş kliniği', 'match_type' => 'PHRASE', 'status' => 'ENABLED', 'campaign_id' => '102']]);
        DB::table('google_ads_campaign_negative_keyword_snapshot')->insert(['external_resource_id' => $this->resourceId, 'customer_id' => '1112223333', 'campaign_id' => '101',
            'criterion_id' => '901', 'keyword_text' => 'fiyatları', 'match_type' => 'PHRASE', 'status' => 'ENABLED'] + $this->provenance());
        $this->row('google_ads_conversion_action_snapshot', ['conversion_action_id' => '601', 'metadata' => ['name' => 'Form', 'status' => 'ENABLED', 'category' => 'SUBMIT_LEAD_FORM', 'primary_for_goal' => true]]);
        $this->row('google_ads_conversion_action_snapshot', ['conversion_action_id' => '602', 'metadata' => ['name' => 'Sayfa görüntüleme', 'status' => 'ENABLED', 'category' => 'PAGE_VIEW', 'primary_for_goal' => true]]);
        for ($day = 1; $day <= 12; $day++) {
            $date = $this->day($day);
            $this->row('google_ads_campaign_daily', ['campaign_id' => '101', 'reporting_date' => $date, 'impressions' => 200, 'clicks' => 10, 'cost_micros' => 0, 'cost_amount' => 20, 'conversions' => 0, 'currency' => 'TRY', 'metadata' => []]);
            $this->row('google_ads_keyword_daily', ['ad_group_id' => '201', 'criterion_id' => '301', 'reporting_date' => $date, 'impressions' => 200, 'clicks' => 10, 'cost_micros' => 0, 'cost_amount' => 20, 'conversions' => 0, 'currency' => 'TRY', 'metadata' => []]);
            $this->row('google_ads_conversion_action_daily', ['conversion_action_id' => '601', 'reporting_date' => $date, 'conversions' => 0, 'all_conversions' => 0, 'metadata' => []]);
        }
        $this->row('google_ads_conversion_action_daily', ['conversion_action_id' => '601', 'reporting_date' => $this->day(70), 'conversions' => 1, 'all_conversions' => 1, 'metadata' => []]);
        foreach ([['implant fiyatları ankara', 50, 10, 2, '101', '201'], ['ücretsiz implant', 40, 8, 0, '101', '201'], ['diş hekimi iş ilanı', 30, 6, 0, '102', '202'], ['istanbul implant', 25, 5, 0, '101', '201']] as [$term, $cost, $clicks, $conv, $campaign, $group]) {
            $this->row('google_ads_search_term_daily', ['search_term' => $term, 'reporting_date' => $this->day(2), 'impressions' => 100, 'clicks' => $clicks, 'cost_micros' => 0, 'cost_amount' => $cost,
                'conversions' => $conv, 'currency' => 'TRY', 'metadata' => ['contexts' => [['campaign_id' => $campaign, 'ad_group_id' => $group, 'advertising_channel_type' => 'SEARCH']]]]);
        }
        foreach ([['https://panorama.test/implant/', 100], ['https://baska.test/kampanya', 20]] as [$url, $cost]) {
            $this->row('google_ads_landing_page_daily', ['landing_page' => $url, 'reporting_date' => $this->day(2), 'impressions' => 100, 'clicks' => 5, 'cost_micros' => 0, 'cost_amount' => $cost, 'conversions' => 0, 'currency' => 'TRY', 'metadata' => []]);
        }
        DB::table('google_ads_ad_daily')->insert(['external_resource_id' => $this->resourceId, 'customer_id' => '1112223333', 'reporting_date' => $this->day(2), 'campaign_id' => '101',
            'ad_group_id' => '201', 'ad_id' => '401', 'impressions' => 100, 'clicks' => 5, 'interactions' => 5, 'cost_micros' => 0, 'cost_amount' => 10, 'conversions' => 0, 'currency' => 'TRY',
            'metadata' => json_encode(['ad_group_name' => 'İmplant', 'status' => 'ENABLED', 'approval_status' => 'DISAPPROVED', 'headlines' => ['Ankara İmplant'], 'descriptions' => ['Çankaya kliniği.'], 'final_urls' => ['https://panorama.test/implant/']])] + $this->provenance());
    }

    private function day(int $ago): string
    {
        return now('Europe/Istanbul')->subDays($ago)->toDateString();
    }

    /** @return array<string, mixed> */
    private function provenance(): array
    {
        return ['contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => bin2hex(random_bytes(32)), 'created_at' => now(), 'updated_at' => now()];
    }

    /** @param  array<string, mixed>  $values */
    private function row(string $table, array $values): void
    {
        $values['metadata'] = json_encode($values['metadata'] ?? []);
        DB::table($table)->insert(['digital_asset_id' => null, 'external_resource_id' => $this->resourceId, 'customer_id' => '1112223333', 'source_timezone' => 'Europe/Istanbul'] + $values + $this->provenance());
    }

    private function page(string $tab): Testable
    {
        return Livewire::actingAs($this->admin)->test(OverviewPage::class, ['assetId' => (string) $this->asset->id, 'tab' => $tab]);
    }

    /** @return array<string, array<string, mixed>> */
    private function checks(): array
    {
        return collect(app(GoogleAdsChecks::class)->run($this->asset))->keyBy('id')->all();
    }

    public function test_system_checks_come_from_the_data_and_say_veri_yok_without_it(): void
    {
        $empty = $this->checks();
        $this->assertCount(9, $empty);
        $this->assertTrue(collect($empty)->every(fn (array $c): bool => $c['state'] === 'nodata'), 'no data → veri yok, never a guess');

        $this->seedAccount();
        $checks = $this->checks();

        $this->assertLessThanOrEqual(10, count($checks));
        $this->assertSame('fail', $checks['conversion_tracking']['state']);
        $this->assertStringContainsString('120 tık', $checks['conversion_tracking']['reason']);
        $this->assertSame('fail', $checks['conversion_goals']['state']);
        $this->assertStringContainsString('Sayfa görüntüleme', $checks['conversion_goals']['reason']);
        $this->assertSame('fail', $checks['negative_conflict']['state']);
        $this->assertStringContainsString('«fiyatları» negatifi «implant fiyatları»', $checks['negative_conflict']['reason']);
        $this->assertSame('fail', $checks['landing']['state']);
        $this->assertSame('https://baska.test/kampanya', $checks['landing']['evidence'][0]['url']);
        $this->assertSame('fail', $checks['policy']['state']);
        $this->assertSame('fail', $checks['service_campaign']['state']);
        $this->assertStringContainsString('Zirkonyum Kaplama', $checks['service_campaign']['reason']);
        $this->assertSame('nodata', $checks['targeting']['state'], 'no geo rows');
        $this->assertSame('nodata', $checks['anomaly']['state'], 'too little data for a performance judgement');

        $count = app(GoogleAdsSuggestions::class)->syncChecks($this->asset);
        $rows = Suggestion::query()->where('channel', 'google_ads')->where('action_type', 'ads_check')->get();
        $this->assertSame($count, $rows->count());
        $this->assertTrue($rows->every(fn (Suggestion $s): bool => $s->evidence !== [] && $s->target_id === $this->asset->id));
        $this->assertTrue($rows->every(fn (Suggestion $s): bool => preg_match('/kapat|durdur|duraklat/iu', $s->title.' '.$s->reason.' '.json_encode($s->action, JSON_UNESCAPED_UNICODE)) !== 1), 'checks never propose closing');

        $this->page('overview')->assertSee('Sistem kontrolleri')->assertSee('Negatif çakışması')->assertSee('Veri yok')->assertSee('Maliyet');
    }

    public function test_campaign_language_targeting_is_collected_and_compared_with_the_brand_languages(): void
    {
        $this->seedAccount();
        GoogleAdsCampaignLanguages::store($this->resourceId, '1112223333', [
            ['campaign' => ['id' => '101'], 'campaignCriterion' => ['language' => ['languageConstant' => 'languageConstants/1001']]],
        ]);
        $this->assertSame(['101' => 'de', '102' => 'all'], DB::table('google_ads_campaign_snapshot')->orderBy('campaign_id')->pluck('language_codes', 'campaign_id')->all());

        $targeting = $this->checks()['targeting'];
        $this->assertSame('fail', $targeting['state']);
        $this->assertStringContainsString('İmplant Ankara', $targeting['reason']);
        $this->assertSame(['kampanya' => 'İmplant Ankara', 'dil_hedeflemesi' => 'de', 'marka_dilleri' => 'tr, en'], $targeting['evidence'][0]);

        GoogleAdsCampaignLanguages::store($this->resourceId, '1112223333', [
            ['campaign' => ['id' => '101'], 'campaignCriterion' => ['language' => ['languageConstant' => 'languageConstants/1037']]],
            ['campaign' => ['id' => '101'], 'campaignCriterion' => ['language' => ['languageConstant' => 'languageConstants/1000']]],
        ]);
        $targeting = $this->checks()['targeting'];
        $this->assertSame('pass', $targeting['state'], 'language fits; region has no data');
        $this->assertStringContainsString('Bölge verisi yok', $targeting['reason']);

        DB::table('google_ads_campaign_snapshot')->update(['language_codes' => null]);
        $this->assertSame('nodata', $this->checks()['targeting']['state'], 'veri yok only when neither region nor language is known');
    }

    public function test_anomaly_leaves_out_the_conversion_lag_window(): void
    {
        $this->row('google_ads_campaign_snapshot', ['campaign_id' => '101', 'metadata' => ['name' => 'İmplant Ankara', 'status' => 'ENABLED', 'advertising_channel_type' => 'SEARCH']]);
        $this->row('google_ads_conversion_action_snapshot', ['conversion_action_id' => '601', 'metadata' => ['name' => 'Form', 'status' => 'ENABLED', 'category' => 'SUBMIT_LEAD_FORM',
            'primary_for_goal' => true, 'click_through_lookback_window_days' => 7]]);
        for ($day = 1; $day <= 49; $day++) {
            // The last 7 days: costly and without conversions yet (they are still arriving).
            [$cost, $conversions] = $day <= 7 ? [50, 0] : [20, 1];
            $this->row('google_ads_campaign_daily', ['campaign_id' => '101', 'reporting_date' => $this->day($day), 'impressions' => 100, 'clicks' => 10, 'cost_micros' => 0,
                'cost_amount' => $cost, 'conversions' => $conversions, 'currency' => 'TRY', 'metadata' => []]);
        }

        $anomaly = $this->checks()['anomaly'];
        $this->assertSame('pass', $anomaly['state'], 'the lag window would otherwise read as a cost spike');
        $this->assertStringContainsString('son 7 gün hariç', $anomaly['reason']);

        DB::table('google_ads_campaign_daily')->where('reporting_date', '>=', $this->day(20))->where('reporting_date', '<=', $this->day(8))->update(['conversions' => 0, 'cost_amount' => 60]);
        $anomaly = $this->checks()['anomaly'];
        $this->assertSame('fail', $anomaly['state']);
        $this->assertSame(['dönüşüm_gecikmesi' => 'son 7 gün hariç (dönüşüm penceresi)'], end($anomaly['evidence']));
    }

    public function test_lead_quality_is_entered_monthly_per_campaign_and_feeds_the_structure_pack(): void
    {
        $this->seedAccount();
        $month = substr($this->day(2), 0, 7);
        $page = $this->page('measurement')->call('setLeadMonth', $month)->assertSee('Lead kalitesi')->assertSee('İmplant Ankara');

        $page->set('leadRows.101', ['leads' => 5, 'qualified' => 2, 'appointments' => 1, 'sales' => 9])->call('saveLeadQuality', '101');
        $this->assertSame(0, DB::table('google_ads_lead_quality')->count(), 'sales cannot exceed the forms');

        $page->set('leadRows.101', ['leads' => 12, 'qualified' => 5, 'appointments' => 3, 'sales' => 1])->call('saveLeadQuality', '101');
        $row = DB::table('google_ads_lead_quality')->sole();
        $this->assertSame([12, 5, 3, 1, 'İmplant Ankara', $month.'-01'], [(int) $row->leads, (int) $row->qualified, (int) $row->appointments, (int) $row->sales, $row->campaign_name, substr((string) $row->month, 0, 10)]);
        $this->page('measurement')->call('setLeadMonth', $month)->assertSet('leadRows.101.leads', 12)->assertSee('Google Ads dönüşüm');

        GoogleAdsStructureAgent::fake([['campaigns' => [], 'budget_split' => [], 'experiments' => []]]);
        $this->page('strategy')->call('proposeStructure');
        GoogleAdsStructureAgent::assertPrompted(fn ($prompt): bool => str_contains((string) $prompt->prompt, '"leads":12') && str_contains((string) $prompt->prompt, '"sales":1')
            && str_contains((string) $prompt->prompt, '"excluded_recent_days":7'));
    }

    public function test_search_term_review_keeps_only_what_the_data_supports(): void
    {
        $this->seedAccount();
        GoogleAdsSearchTermsAgent::fake([[
            'terms' => [
                ['term' => 'ücretsiz implant', 'intent' => 'alakasiz', 'service' => 'Diş İmplantı', 'fit' => 'uygunsuz', 'reason' => 'Ücretsiz arıyor.'],
                ['term' => 'implant fiyatları ankara', 'intent' => 'ticari', 'service' => 'Diş Beyazlatma', 'fit' => 'uygun', 'reason' => 'Fiyat araştırıyor.'],
                ['term' => 'uydurma terim', 'intent' => 'ticari', 'service' => 'Diş İmplantı', 'fit' => 'uygun', 'reason' => 'x'],
            ],
            'negatives' => [
                ['text' => 'ücretsiz', 'match_type' => 'BROAD', 'scope' => 'shared', 'campaign' => '', 'ad_group' => '', 'reason' => 'Ücretsiz arayanlar 40 TL harcattı.'],
                ['text' => 'iş ilanı', 'match_type' => 'PHRASE', 'scope' => 'campaign', 'campaign' => 'Genel', 'ad_group' => '', 'reason' => 'İş arayanlar.'],
                ['text' => 'ankara', 'match_type' => 'PHRASE', 'scope' => 'shared', 'campaign' => '', 'ad_group' => '', 'reason' => 'Bölge dışı.'],
                ['text' => 'istanbul', 'match_type' => 'PHRASE', 'scope' => 'shared', 'campaign' => '', 'ad_group' => '', 'reason' => 'İstanbul 999 TL harcadı.'],
                ['text' => 'maaş', 'match_type' => 'PHRASE', 'scope' => 'campaign', 'campaign' => 'Olmayan Kampanya', 'ad_group' => '', 'reason' => 'x'],
                ['text' => 'kurs', 'match_type' => 'PHRASE', 'scope' => 'shared', 'campaign' => '', 'ad_group' => '', 'reason' => 'Hiç görülmedi.'],
            ],
        ]]);

        $page = $this->page('terms')->call('reviewTerms');

        GoogleAdsSearchTermsAgent::assertPrompted(fn ($prompt): bool => str_contains((string) $prompt->prompt, 'ücretsiz implant') && str_contains((string) $prompt->prompt, 'Zirkonyum Kaplama'));
        $negatives = Suggestion::query()->where('action_type', 'ads_negative')->orderBy('id')->get();
        $this->assertSame(['ücretsiz', 'iş ilanı'], $negatives->pluck('action.text')->all(), 'converting-term blockers, invented numbers, unknown campaigns and unseen terms are dropped');
        $shared = $negatives->firstWhere('action.text', 'ücretsiz');
        $this->assertSame('PHRASE', $shared->action['match_type'], 'a shared broad negative is sent as phrase');
        $this->assertSame(40.0, (float) $shared->action['cost']);
        $this->assertSame([], $shared->action['blocks']);
        $this->assertSame('Genel', $negatives->firstWhere('action.text', 'iş ilanı')->action['campaign']);

        $verdicts = app(GoogleAdsScreen::class)->verdicts($this->asset);
        $this->assertSame(['ücretsiz implant', 'implant fiyatları ankara'], array_keys($verdicts));
        $this->assertSame('', $verdicts['implant fiyatları ankara']['service'], 'a service outside the offerings is not shown');
        $page->call('setTab', 'terms')->assertSee('Uygunsuz')->assertSee('Diş İmplantı')->assertSee('İmplant Ankara')
            ->call('setTab', 'todo')->assertSee('Negatif: «ücretsiz» (sıralı)')->assertDontSee('«ankara»');
    }

    public function test_selected_terms_are_reviewed_without_touching_other_negatives(): void
    {
        $this->seedAccount();
        GoogleAdsSearchTermsAgent::fake([
            ['terms' => [], 'negatives' => [['text' => 'ücretsiz', 'match_type' => 'PHRASE', 'scope' => 'shared', 'campaign' => '', 'ad_group' => '', 'reason' => 'Ücretsiz.']]],
            ['terms' => [['term' => 'istanbul implant', 'intent' => 'ticari', 'service' => 'Diş İmplantı', 'fit' => 'kismen', 'reason' => 'Başka şehir.']],
                'negatives' => [['text' => 'istanbul', 'match_type' => 'PHRASE', 'scope' => 'shared', 'campaign' => '', 'ad_group' => '', 'reason' => 'Bölge dışı.']]],
        ]);
        $page = $this->page('terms')->call('reviewTerms');
        $page->set('selectedTerms', ['istanbul implant'])->call('proposeNegatives');

        GoogleAdsSearchTermsAgent::assertPrompted(fn ($prompt): bool => str_contains((string) $prompt->prompt, '"focus":["istanbul implant"]'));
        $this->assertSame(['ücretsiz', 'istanbul'], Suggestion::query()->where('action_type', 'ads_negative')->where('status', Suggestion::OPEN)->orderBy('id')->pluck('action')->pluck('text')->all());
    }

    public function test_shared_negative_goes_to_the_shared_list_through_the_writer_and_is_undoable(): void
    {
        $this->seedAccount();
        GoogleAdsSearchTermsAgent::fake([['terms' => [], 'negatives' => [['text' => 'ücretsiz', 'match_type' => 'PHRASE', 'scope' => 'shared', 'campaign' => '', 'ad_group' => '', 'reason' => 'Ücretsiz.']]]]);
        $mutations = [];
        Http::fake(function (Request $request) use (&$mutations) {
            if (str_contains($request->url(), 'googleAds:search')) {
                return Http::response(['results' => str_contains((string) ($request->data()['query'] ?? ''), 'FROM campaign WHERE') ? [['campaign' => ['resourceName' => 'customers/1112223333/campaigns/101']]] : []]);
            }
            if (str_contains($request->url(), ':mutate')) {
                $service = str_contains($request->url(), 'sharedSets') ? 'sharedSets' : (str_contains($request->url(), 'sharedCriteria') ? 'sharedCriteria' : 'campaignSharedSets');
                $mutations[] = [$service, $request->data()];
                $ops = $request->data()['operations'] ?? [];

                return Http::response(['results' => array_map(fn ($op, $i) => ['resourceName' => 'customers/1112223333/'.$service.'/9~'.$i], $ops, array_keys($ops))]);
            }

            return Http::response([], 404);
        });
        $page = $this->page('todo')->call('reviewTerms');
        $suggestion = Suggestion::query()->where('action_type', 'ads_negative')->sole();

        $page->call('approveSuggestion', $suggestion->id);

        $action = ExternalWriteAction::query()->sole();
        $this->assertSame('succeeded', $action->status, (string) $action->error);
        $this->assertSame([['text' => 'ücretsiz', 'match_type' => 'PHRASE']], $action->request_payload['keywords']);
        $this->assertSame($suggestion->id, (int) $action->suggestion_id);
        $this->assertSame(['text' => 'ücretsiz', 'matchType' => 'PHRASE'], collect($mutations)->firstWhere(0, 'sharedCriteria')[1]['operations'][0]['create']['keyword']);
        $suggestion->refresh();
        $this->assertSame(Suggestion::APPLIED, $suggestion->status);
        $this->assertNotNull($suggestion->applied_at);
        $this->assertSame($action->id, $suggestion->baseline['write_action_id']);
        $this->assertArrayHasKey('cost', $suggestion->baseline);
        $this->assertSame(28, $suggestion->baseline['window_days']);

        $page->call('setTab', 'todo')->assertSee('Gönderildi')->call('undoWrite', $action->id);
        $this->assertSame('undone', $action->fresh()->status);
    }

    public function test_shared_negative_is_not_applied_when_the_write_fails(): void
    {
        $this->seedAccount();
        GoogleAdsSearchTermsAgent::fake([['terms' => [], 'negatives' => [['text' => 'ücretsiz', 'match_type' => 'BROAD', 'scope' => 'shared', 'campaign' => '', 'ad_group' => '', 'reason' => 'Ücretsiz.']]]]);
        Http::fake(fn (Request $request) => str_contains($request->url(), 'googleAds:search') ? Http::response(['results' => []]) : Http::response(['error' => ['message' => 'denied']], 403));
        $page = $this->page('todo')->call('reviewTerms');
        $suggestion = Suggestion::query()->where('action_type', 'ads_negative')->sole();

        $page->call('approveSuggestion', $suggestion->id);

        $this->assertSame('failed', ExternalWriteAction::query()->sole()->status);
        $suggestion->refresh();
        $this->assertSame(Suggestion::APPROVED, $suggestion->status, 'not applied: Google did not take it');
        $this->assertNull($suggestion->applied_at);
        $this->assertArrayNotHasKey('sending_write_id', $suggestion->action, 'can be sent again');
    }

    public function test_ad_texts_enforce_limits_numbers_urls_and_compliance(): void
    {
        $this->seedAccount();
        $pack = ['ad_group' => ['final_urls' => ['https://panorama.test/implant/']], 'pages' => [['url' => 'https://panorama.test/implant/', 'title' => 'İmplant', 'summary' => '15 yıllık deneyim']]];
        $ad = GoogleAdsAssistant::validateAdTexts([
            'headlines' => ['Ankara İmplant Kliniği', 'Çankaya’da Diş İmplantı', str_repeat('Uzun başlık ', 4), '15 Yıllık Deneyim', '%50 İndirimli İmplant', 'Hemen Arayın!', 'Randevu Alın'],
            'descriptions' => ['Çankaya şubemizde implant tedavisi için randevu alın.', str_repeat('Çok uzun açıklama ', 8), 'Deneyimli ekip, modern klinik.'],
            'path1' => 'implant', 'path2' => 'bu yol on beş karakterden uzun', 'final_url' => 'https://uydurma.test/x', 'landing_reason' => 'İmplant sayfası.',
        ], $pack, fn (string $text): array => []);

        $this->assertSame(['Ankara İmplant Kliniği', 'Çankaya’da Diş İmplantı', '15 Yıllık Deneyim', 'Randevu Alın'], $ad['headlines'], '> 30 chars, "!", numbers not in the data are dropped');
        $this->assertTrue(collect($ad['headlines'])->every(fn (string $h): bool => mb_strlen($h) <= 30));
        $this->assertSame(['Çankaya şubemizde implant tedavisi için randevu alın.', 'Deneyimli ekip, modern klinik.'], $ad['descriptions']);
        $this->assertSame('implant', $ad['path1']);
        $this->assertSame('', $ad['path2']);
        $this->assertSame('https://panorama.test/implant/', $ad['final_url'], 'an invented URL falls back to the current page of the brand');

        GoogleAdsAdTextsAgent::fake([
            ['headlines' => ['Garantili İmplant', 'Ankara İmplant', 'Çankaya İmplant'], 'descriptions' => ['Garantili sonuç.', 'Garantili tedavi.'], 'path1' => '', 'path2' => '', 'final_url' => 'https://panorama.test/implant/', 'landing_reason' => ''],
            ['headlines' => ['Ankara İmplant', 'Çankaya İmplant', 'Randevu Alın'], 'descriptions' => ['Çankaya şubemizde implant.', 'Deneyimli ekip.'], 'path1' => 'implant', 'path2' => '', 'final_url' => 'https://panorama.test/implant/', 'landing_reason' => 'Hizmet sayfası.'],
        ]);
        $page = $this->page('strategy')->assertSee('Ankara › İmplant')->set('adGroupKey', '201')->call('writeAds')
            ->call('setTab', 'strategy')->assertSee('sektör uyum kuralına takıldı');
        $this->assertSame(0, Suggestion::query()->where('action_type', 'ads_rsa')->count());
        $page->call('writeAds')->call('setTab', 'strategy')->assertSee('Reklam metni: İmplant')->assertSee('14/30');
        GoogleAdsAdTextsAgent::assertPrompted(fn ($prompt): bool => str_contains((string) $prompt->prompt, 'implant fiyatları') && str_contains((string) $prompt->prompt, 'Ankara İmplant'));
    }

    public function test_structure_uses_offerings_and_pages_scales_budgets_and_never_pauses_low_data(): void
    {
        $this->seedAccount();
        $valid = GoogleAdsAssistant::validateStructure([
            'campaigns' => [
                ['name' => 'Zirkonyum – Ankara', 'service' => 'Zirkonyum Kaplama', 'daily_budget' => 200, 'reason' => 'Ana hizmet.', 'ad_groups' => [
                    ['name' => 'Zirkonyum kaplama', 'landing_url' => 'https://panorama.test/zirkonyum', 'keywords' => [['text' => 'zirkonyum kaplama ankara', 'match_type' => 'PHRASE'], ['text' => 'bad!word', 'match_type' => 'EXACT']]],
                    ['name' => 'Boş grup', 'landing_url' => '', 'keywords' => []],
                ]],
                ['name' => 'Beyazlatma', 'service' => 'Diş Beyazlatma', 'daily_budget' => 50, 'reason' => 'x', 'ad_groups' => [['name' => 'x', 'landing_url' => '', 'keywords' => [['text' => 'beyazlatma', 'match_type' => 'EXACT']]]]],
                ['name' => 'İmplant – Ankara', 'service' => 'Diş İmplantı', 'daily_budget' => 100, 'reason' => 'Genel kampanyasını kapatın.', 'ad_groups' => [['name' => 'İmplant', 'landing_url' => 'https://uydurma.test/', 'keywords' => [['text' => 'implant ankara', 'match_type' => 'EXACT']]]]],
            ],
            'budget_split' => [['service' => 'Zirkonyum Kaplama', 'daily_budget' => 300, 'reason' => ''], ['service' => 'Diş İmplantı', 'daily_budget' => 300, 'reason' => '']],
            'experiments' => [['title' => 'Genel kampanyayı durdur', 'hypothesis' => 'x', 'metric' => 'dönüşüm', 'duration_days' => 5], ['title' => 'Tam eşleme testi', 'hypothesis' => 'Daha düşük maliyet.', 'metric' => 'EDM', 'duration_days' => 200]],
        ], [
            'offerings' => [['name' => 'Diş İmplantı', 'priority' => 'main'], ['name' => 'Zirkonyum Kaplama', 'priority' => 'main']],
            'pages' => [['url' => 'https://panorama.test/zirkonyum/', 'title' => 'Zirkonyum', 'category' => 'hizmet']],
            'total_daily_budget' => 150.0,
            'campaigns' => [['name' => 'Genel', 'enough_data' => false]],
        ]);

        $this->assertSame(['Zirkonyum – Ankara'], array_column($valid['campaigns'], 'name'), 'unknown services and pausing low-data campaigns are dropped');
        $group = $valid['campaigns'][0]['ad_groups'];
        $this->assertCount(1, $group);
        $this->assertSame('https://panorama.test/zirkonyum/', $group[0]['landing_url']);
        $this->assertSame([['text' => 'zirkonyum kaplama ankara', 'match_type' => 'PHRASE']], $group[0]['keywords']);
        $this->assertSame(150.0, $valid['campaigns'][0]['daily_budget'], 'budgets are scaled down to the total');
        $this->assertSame(150.0, array_sum(array_column($valid['budget_split'], 'daily_budget')));
        $this->assertSame(['Tam eşleme testi'], array_column($valid['experiments'], 'title'));
        $this->assertSame(56, $valid['experiments'][0]['duration_days']);

        GoogleAdsStructureAgent::fake([['campaigns' => [['name' => 'Zirkonyum – Ankara', 'service' => 'Zirkonyum Kaplama', 'daily_budget' => 60, 'reason' => 'Ana hizmet.',
            'ad_groups' => [['name' => 'Zirkonyum kaplama', 'landing_url' => 'https://panorama.test/zirkonyum/', 'keywords' => [['text' => 'zirkonyum kaplama', 'match_type' => 'PHRASE']]]]]],
            'budget_split' => [['service' => 'Zirkonyum Kaplama', 'daily_budget' => 60, 'reason' => '']], 'experiments' => []]]);
        $this->page('strategy')->call('proposeStructure')->call('setTab', 'strategy')->assertSee('Kampanya: Zirkonyum – Ankara')->assertSee('Bütçe dağılımı');
        GoogleAdsStructureAgent::assertPrompted(fn ($prompt): bool => str_contains((string) $prompt->prompt, '"total_daily_budget":150'));
    }

    public function test_operator_edit_is_locked_and_new_ai_output_only_leaves_a_proposal(): void
    {
        $this->seedAccount();
        $rsa = ['headlines' => ['Ankara İmplant', 'Çankaya İmplant', 'Randevu Alın'], 'descriptions' => ['Çankaya şubemizde implant.', 'Deneyimli ekip.'], 'path1' => '', 'path2' => '',
            'final_url' => 'https://panorama.test/implant/', 'landing_reason' => ''];
        GoogleAdsAdTextsAgent::fake([$rsa, ['headlines' => ['Yeni Başlık', 'Ankara İmplant', 'Randevu Alın']] + $rsa]);
        $page = $this->page('strategy')->set('adGroupKey', '201')->call('writeAds');
        $suggestion = Suggestion::query()->where('action_type', 'ads_rsa')->sole();

        $page->call('startEdit', $suggestion->id)->set('edit.headlines', "Benim Başlığım\nAnkara İmplant\nRandevu Alın")->call('saveEdit')->assertHasNoErrors();
        $page->call('startEdit', $suggestion->id)->set('edit.headlines', str_repeat('x', 31)."\nA\nB")->call('saveEdit')->assertHasErrors('edit.headlines');
        $page->call('cancelEdit')->call('writeAds');

        $suggestion->refresh();
        $this->assertTrue($suggestion->action['locked']);
        $this->assertSame('Benim Başlığım', $suggestion->action['headlines'][0], 'AI never overwrites an operator edit');
        $this->assertSame('Yeni Başlık', $suggestion->action['proposal']['headlines'][0]);
        $page->call('setTab', 'strategy')->assertSee('AI değişiklik önerisi var')->call('acceptProposal', $suggestion->id);
        $this->assertSame('Yeni Başlık', $suggestion->fresh()->action['headlines'][0]);
    }

    public function test_approved_drafts_become_a_google_ads_editor_file_and_are_applied_with_a_baseline(): void
    {
        $this->seedAccount();
        $suggestions = app(GoogleAdsSuggestions::class);
        $suggestions->replaceGroup($this->asset, 'structure', [['key' => 'structure:z', 'title' => 'Kampanya: Zirkonyum', 'reason' => 'r', 'priority' => 1, 'evidence' => [], 'action_type' => 'ads_campaign',
            'action' => ['name' => 'Zirkonyum', 'service' => 'Zirkonyum Kaplama', 'daily_budget' => 60, 'ad_groups' => [['name' => 'Kaplama', 'landing_url' => 'https://panorama.test/zirkonyum/', 'keywords' => [['text' => 'zirkonyum kaplama', 'match_type' => 'PHRASE']]]]]]]);
        $suggestions->replaceGroup($this->asset, 'rsa:201', [['key' => 'rsa:201', 'title' => 'Reklam metni: İmplant', 'reason' => 'r', 'priority' => 2, 'evidence' => [], 'action_type' => 'ads_rsa',
            'action' => ['campaign' => 'İmplant Ankara', 'ad_group' => 'İmplant', 'headlines' => ['Ankara İmplant', 'Çankaya İmplant', 'Randevu Alın'], 'descriptions' => ['Açıklama bir.', 'Açıklama iki.'], 'path1' => 'implant', 'path2' => '', 'final_url' => 'https://panorama.test/implant/']]]);
        $suggestions->replaceGroup($this->asset, 'negative', [['key' => 'negative:campaign:genel is ilani:PHRASE', 'title' => 'Negatif: «iş ilanı»', 'reason' => 'r', 'priority' => 2, 'evidence' => [], 'action_type' => 'ads_negative',
            'action' => ['text' => 'iş ilanı', 'match_type' => 'PHRASE', 'scope' => 'campaign', 'campaign' => 'Genel', 'ad_group' => '', 'blocks' => [], 'cost' => 30]]]);

        $page = $this->page('strategy');
        foreach (Suggestion::query()->whereIn('action_type', ['ads_campaign', 'ads_rsa', 'ads_negative'])->pluck('id') as $id) {
            $page->call('approveSuggestion', $id);
        }
        $this->assertSame(3, Suggestion::query()->where('status', Suggestion::APPROVED)->count());
        $this->assertSame(0, ExternalWriteAction::query()->count(), 'Editor drafts are never written to Google');

        $csv = GoogleAdsEditorCsv::build(Suggestion::query()->where('status', Suggestion::APPROVED)->orderBy('id')->get());
        $lines = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));
        $header = $lines[0];
        $rows = array_map(fn (array $l): array => array_combine($header, $l), array_slice($lines, 1));
        $this->assertSame(['Campaign', 'Campaign Type', 'Networks', 'Campaign Daily Budget', 'Campaign Status'], array_slice($header, 0, 5));
        $this->assertSame(['Zirkonyum', 'Search', '60.00', 'Paused'], [$rows[0]['Campaign'], $rows[0]['Campaign Type'], $rows[0]['Campaign Daily Budget'], $rows[0]['Campaign Status']]);
        $this->assertSame(['zirkonyum kaplama', 'Phrase', 'https://panorama.test/zirkonyum/'], [$rows[2]['Keyword'], $rows[2]['Criterion Type'], $rows[2]['Final URL']]);
        $this->assertSame(['Responsive search ad', 'Ankara İmplant', 'Açıklama iki.'], [$rows[3]['Ad type'], $rows[3]['Headline 1'], $rows[3]['Description 2']]);
        $this->assertSame(['Genel', '', 'iş ilanı', 'Campaign Negative Phrase'], [$rows[4]['Campaign'], $rows[4]['Ad Group'], $rows[4]['Keyword'], $rows[4]['Criterion Type']]);

        $page->call('setTab', 'strategy')->assertSee('Onaylı taslaklar · 3')->call('downloadEditor')->assertFileDownloaded();
        $drafts = Suggestion::query()->whereIn('action_type', ['ads_campaign', 'ads_rsa', 'ads_negative'])->get();
        $this->assertTrue($drafts->every(fn (Suggestion $s): bool => $s->status === Suggestion::APPROVED && $s->applied_at === null && filled($s->action['editor_batch'] ?? null)),
            'downloading the file changes nothing on Google: still approved');
        $batch = (string) $drafts->first()->action['editor_batch'];
        $page->call('setTab', 'strategy')->assertSee('Onaylı taslaklar · 0')->assertSee('aktardım')
            ->call('downloadEditor', $batch)->assertFileDownloaded();
        $this->assertSame(3, Suggestion::query()->where('status', Suggestion::APPROVED)->count(), 'downloading a batch again applies nothing');

        $page->call('confirmEditorBatch', $batch);
        $applied = Suggestion::query()->whereIn('action_type', ['ads_campaign', 'ads_rsa', 'ads_negative'])->get();
        $this->assertTrue($applied->every(fn (Suggestion $s): bool => $s->status === Suggestion::APPLIED && $s->applied_at !== null && isset($s->baseline['window_days'], $s->baseline['editor_file_at'])));
    }

    public function test_every_tab_renders_and_old_tab_keys_still_work(): void
    {
        $this->seedAccount();
        $this->page('overview')->assertOk()->assertSee('Genel Bakış')->assertSee('Kampanya Stratejisi')->assertSee('Ölçümleme');
        $this->page('todo')->assertOk()->assertSee('Arama terimlerini incele');
        $this->page('terms')->assertOk()->assertSee('ücretsiz implant')->assertSee('Diş İmplantı')->assertSee('Negatif öner');
        $this->page('strategy')->assertOk()->assertSee('Kampanya yapısı öner')->assertSee('Reklam metni yaz');
        $this->page('measurement')->assertOk()->assertSee('Form')->assertSee('Birincil')->assertSee($this->day(70));
        $this->page('analysis')->assertOk()->assertSee('İmplant Ankara')->call('setLevel', 'service')->assertSee('Diş İmplantı')->call('setLevel', 'geo')->assertSee('Veri yok');
        $this->page('settings')->assertOk()->assertSee('1112223333')->assertSee('Çankaya şubesi (şube)')->assertSee('tr, en');
        $this->page('search_demand')->assertSet('tab', 'terms');
        $this->page('advisor')->assertSet('tab', 'todo');
        $this->page('campaigns')->assertSet('tab', 'analysis');
        $this->page('data_connection')->assertSet('tab', 'settings');
        $this->actingAs($this->admin)->get(route('operator.google-ads.overview', ['assetId' => $this->asset->id]))->assertOk()->assertSee('Google Ads');
    }

    public function test_non_operational_brand_gets_no_ai(): void
    {
        $this->seedAccount();
        $this->brand->customer->forceFill(['status' => CustomerStatus::Inactive])->save();
        GoogleAdsSearchTermsAgent::fake();
        GoogleAdsStructureAgent::fake();

        $this->page('todo')->call('reviewTerms')->call('proposeStructure');
        app(GoogleAdsAssistant::class)->run($this->asset->id, GoogleAdsAssistant::OP_TERMS);

        GoogleAdsSearchTermsAgent::assertNeverPrompted();
        GoogleAdsStructureAgent::assertNeverPrompted();
        $this->assertSame('Marka operasyonel değil; AI çalışmaz.', app(GoogleAdsAssistant::class)->state($this->asset->id, GoogleAdsAssistant::OP_TERMS)['message']);
    }
}
