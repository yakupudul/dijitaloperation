<?php

namespace Tests\Feature\Advisor;

use App\Enums\AdvisorItemStatus;
use App\Enums\DigitalAssetStatus;
use App\Jobs\DraftGoogleAdsAdCopyJob;
use App\Livewire\Operator\Advisor\AdvisorPanel;
use App\Models\AdvisorItem;
use App\Models\AdvisorPlan;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Advisor\AdvisorPlanRunner;
use App\Services\Advisor\GoogleAds\GoogleAdsAdvisorInputCollector;
use App\Services\Advisor\GoogleAds\QualityScoreHistoryRecorder;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsKeywordSnapshotGuard;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsNormalizer;
use App\Services\GoogleAds\GoogleAdsSpecialistBindingResolver;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Integrations\Google\GoogleScopes;
use App\Support\Roles;
use Carbon\Carbon;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * End-to-end: stored Google Ads rows (central, digital_asset_id NULL) → advisor run → items → panel.
 */
final class GoogleAdsAdvisorRunTest extends TestCase
{
    use RefreshDatabase;

    private DigitalAsset $asset;

    private CoreExternalResource $resource;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-23 12:00:00', 'Europe/Istanbul'));
        Http::fake();
        $this->seed(RoleAndPermissionSeeder::class);
        config(['moxdop.google.client_id' => 'test-client-id', 'moxdop.google.client_secret' => 'test-client-secret']);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);

        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Örnek Klinik']);
        $this->asset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'google_ads', 'module_id' => 'google_ads', 'status' => DigitalAssetStatus::Active, 'name' => 'Örnek Klinik Ads']);
        $integration = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE, 'config' => ['granted_scopes' => [GoogleScopes::ADWORDS]]]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $integration->id]);
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $integration->id,
            'encrypted_payload' => ['access_token' => 'a', 'refresh_token' => 'r', 'scope' => GoogleScopes::ADWORDS],
            'expires_at' => now()->addHour(),
        ]);
        $this->resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id, 'provider' => 'google', 'resource_type' => GoogleResourceType::GOOGLE_ADS_CUSTOMER,
            'external_id' => '1112223333', 'display_name' => 'Örnek', 'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => ['timezone' => 'Europe/Istanbul', 'currency' => 'TRY'],
        ]);
        CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->asset->id, 'external_resource_id' => $this->resource->id,
            'capability' => GoogleAdsSpecialistBindingResolver::CAPABILITY, 'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);
    }

    public function test_run_writes_items_resolves_them_and_the_panel_acts(): void
    {
        $this->seedAccount();

        $input = app(GoogleAdsAdvisorInputCollector::class)->collect($this->asset);
        $this->assertTrue($input['bound']);
        $this->assertSame('TRY', $input['currency']);
        $this->assertEqualsWithDelta(4500.0, $input['account']['cost'], 0.01);
        $this->assertSame(3, $input['keywords'][0]['quality_score']);
        $this->assertSame(['ADDED'], $input['search_terms']['implant diş']['statuses']);

        $runner = app(AdvisorPlanRunner::class);
        $plan = $runner->run($runner->queue($this->asset, $this->admin)->id);
        $this->assertSame(AdvisorPlan::STATUS_COMPLETED, $plan->status);
        $rules = AdvisorItem::query()->pluck('rule_id')->all();
        $this->assertContains('negative-keywords', $rules);
        $this->assertContains('low-quality-score', $rules);
        $negatives = AdvisorItem::query()->where('rule_id', 'negative-keywords')->firstOrFail();
        $this->assertStringContainsString('[ücretsiz diş tedavisi]', $negatives->copy_text);
        $this->assertStringNotContainsString('maaşları', $negatives->copy_text, 'existing negative');

        // Panel: global and account tab, done, draft request, per-account run.
        $this->get(route('operator.ads_advisor'))->assertOk()->assertSee('Danışman');
        $quality = AdvisorItem::query()->where('rule_id', 'low-quality-score')->firstOrFail();
        Livewire::test(AdvisorPanel::class)
            ->assertSee('Negatif anahtar kelime listesi')
            ->assertSee('Tüm hesapları incele')
            ->call('toggle', $negatives->id)
            ->assertSee('Listeyi kopyala')
            ->call('markDone', $quality->id);
        $this->assertSame(AdvisorItemStatus::Done, $quality->fresh()->status);

        // Next run: the wasted term got excluded → the negatives item closes itself; done stays done.
        DB::table('google_ads_search_term_daily')->whereIn('search_term', ['ücretsiz diş tedavisi', 'ücretsiz implant', 'ücretsiz diş muayenesi', 'diş hekimi iş ilanları'])->delete();
        $runner->run($runner->queue($this->asset, $this->admin)->id);
        $this->assertSame(AdvisorItemStatus::Resolved, $negatives->fresh()->status);
        $this->assertSame(AdvisorItemStatus::Done, $quality->fresh()->status);

        Queue::fake();
        $weak = AdvisorItem::query()->where('rule_id', 'weak-ad-strength')->first();
        if ($weak !== null) {
            Livewire::test(AdvisorPanel::class, ['assetId' => $this->asset->id])->call('requestDraft', $weak->id)->assertSee('taslağı hazırlanıyor');
            Queue::assertPushed(DraftGoogleAdsAdCopyJob::class);
            $this->assertSame('queued', $weak->fresh()->draft_status);
        }
        Livewire::test(AdvisorPanel::class, ['assetId' => $this->asset->id])->call('refreshAsset', $this->asset->id)->assertSee('danışman #3');
        $this->get(route('operator.google-ads.overview', ['assetId' => $this->asset->id, 'tab' => 'advisor']))->assertOk()->assertSee('Danışman');
    }

    public function test_unbound_account_is_silent(): void
    {
        CoreAssetBinding::query()->update(['status' => 'inactive']);
        $runner = app(AdvisorPlanRunner::class);
        $plan = $runner->run($runner->queue($this->asset, $this->admin)->id);
        $this->assertSame('Google Ads hesabı bağlı değil.', $plan->summary_text);
        $this->assertSame(0, AdvisorItem::query()->count());
    }

    public function test_quality_score_is_collected_and_not_overwritten_by_report_rows(): void
    {
        $rows = app(GoogleAdsNormalizer::class)->normalizeKeywordSnapshots('1112223333', 'Europe/Istanbul', [[
            'adGroupCriterion' => ['criterionId' => '55', 'keyword' => ['text' => 'implant', 'matchType' => 'PHRASE'], 'status' => 'ENABLED', 'qualityInfo' => ['qualityScore' => 4, 'creativeQualityScore' => 'BELOW_AVERAGE', 'postClickQualityScore' => 'AVERAGE', 'searchPredictedCtr' => 'ABOVE_AVERAGE']],
            'adGroup' => ['id' => '9'], 'campaign' => ['id' => '1'],
        ]], null, $this->resource->id);
        $this->assertSame(4, $rows[0]['metadata']['quality_score']);
        $this->assertSame('BELOW_AVERAGE', $rows[0]['metadata']['ad_relevance']);

        $this->row('google_ads_keyword_snapshot', ['ad_group_id' => '9', 'criterion_id' => '55', 'metadata' => json_encode(['keyword_text' => 'implant', 'quality_score' => 4])]);
        $derived = [
            ['digital_asset_id' => null, 'external_resource_id' => $this->resource->id, 'customer_id' => '1112223333', 'ad_group_id' => '9', 'criterion_id' => '55'],
            ['digital_asset_id' => null, 'external_resource_id' => $this->resource->id, 'customer_id' => '1112223333', 'ad_group_id' => '9', 'criterion_id' => '56'],
        ];
        $this->assertSame(['56'], array_column(app(GoogleAdsKeywordSnapshotGuard::class)->onlyMissing($derived), 'criterion_id'));
    }

    public function test_quality_score_history_is_recorded_daily_and_read_as_the_earlier_value(): void
    {
        $this->seedAccount();
        DB::table('google_ads_quality_score_history')->insert([
            'digital_asset_id' => null, 'customer_id' => '1112223333', 'ad_group_id' => 'ag1', 'criterion_id' => 'k1', 'keyword_text' => 'implant fiyat',
            'observed_on' => now('Europe/Istanbul')->subDays(35)->toDateString(), 'quality_score' => 7, 'ad_relevance' => 'AVERAGE', 'landing_page_experience' => 'AVERAGE', 'expected_ctr' => 'AVERAGE',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $recorder = app(QualityScoreHistoryRecorder::class);
        $this->assertSame(1, $recorder->record());
        $this->assertSame(1, $recorder->record(), 'same day → upsert, no duplicate');
        $today = DB::table('google_ads_quality_score_history')->whereDate('observed_on', now()->toDateString())->first();
        $this->assertSame(3, (int) $today->quality_score);
        $this->assertSame('BELOW_AVERAGE', $today->landing_page_experience);
        $this->assertSame(2, DB::table('google_ads_quality_score_history')->count());

        $input = app(GoogleAdsAdvisorInputCollector::class)->collect($this->asset);
        $this->assertSame(7, $input['quality_history']["ag1\0k1"]['quality_score'], 'today\'s copy is too recent to be the baseline');
        $this->assertSame(1000, $input['campaign_daily']['c1'][now('Europe/Istanbul')->subDays(1)->toDateString()]['impressions']);
        $this->assertSame(0, $this->artisan('moxdop:google-ads:record-quality-scores')->run());
    }

    private function seedAccount(): void
    {
        $this->row('google_ads_account_snapshot', ['metadata' => json_encode(['descriptive_name' => 'Örnek', 'currency_code' => 'TRY', 'auto_tagging_enabled' => true])]);
        $this->row('google_ads_campaign_snapshot', ['campaign_id' => 'c1', 'metadata' => json_encode(['name' => 'Implant Arama', 'status' => 'ENABLED', 'advertising_channel_type' => 'SEARCH', 'budget_id' => 'b1'])]);
        $this->row('google_ads_campaign_budget_snapshot', ['budget_id' => 'b1', 'metadata' => json_encode(['amount' => '150'])]);
        for ($day = 1; $day <= 30; $day++) {
            $date = now('Europe/Istanbul')->subDays($day)->toDateString();
            $this->row('google_ads_campaign_daily', ['reporting_date' => $date, 'campaign_id' => 'c1', 'impressions' => 1000, 'clicks' => 30, 'cost_micros' => 150_000_000, 'cost_amount' => 150, 'conversions' => 2, 'currency' => 'TRY', 'metadata' => json_encode(['search_budget_lost_impression_share' => '0.05'])]);
        }
        $term = fn (string $text, float $cost, int $clicks, int $conv, string $status = 'NONE') => $this->row('google_ads_search_term_daily', [
            'reporting_date' => now('Europe/Istanbul')->subDays(3)->toDateString(), 'search_term' => $text, 'impressions' => $clicks * 10, 'clicks' => $clicks,
            'cost_micros' => (int) ($cost * 1_000_000), 'cost_amount' => $cost, 'conversions' => $conv, 'currency' => 'TRY',
            'metadata' => json_encode(['source_view' => 'search_term_view', 'contexts' => [['campaign_id' => 'c1', 'ad_group_id' => 'ag1', 'status' => $status, 'advertising_channel_type' => 'SEARCH']]]),
        ]);
        $term('ücretsiz diş tedavisi', 180, 12, 0);
        $term('ücretsiz implant', 90, 6, 0);
        $term('ücretsiz diş muayenesi', 60, 5, 0);
        $term('diş hekimi iş ilanları', 120, 9, 0);
        $term('diş hekimi maaşları', 140, 10, 0);
        $term('implant diş', 900, 60, 20, 'ADDED');
        $this->row('google_ads_campaign_negative_keyword_snapshot', ['campaign_id' => 'c1', 'criterion_id' => 'n1', 'keyword_text' => 'diş hekimi maaşları', 'match_type' => 'EXACT', 'status' => 'ENABLED']);
        $this->row('google_ads_keyword_snapshot', ['ad_group_id' => 'ag1', 'criterion_id' => 'k1', 'metadata' => json_encode(['keyword_text' => 'implant fiyat', 'match_type' => 'PHRASE', 'status' => 'ENABLED', 'campaign_id' => 'c1', 'quality_score' => 3, 'landing_page_experience' => 'BELOW_AVERAGE'])]);
        $this->row('google_ads_keyword_daily', ['reporting_date' => now('Europe/Istanbul')->subDays(2)->toDateString(), 'ad_group_id' => 'ag1', 'criterion_id' => 'k1', 'impressions' => 900, 'clicks' => 60, 'cost_micros' => 400_000_000, 'cost_amount' => 400, 'conversions' => 1, 'currency' => 'TRY', 'metadata' => json_encode(['keyword_text' => 'implant fiyat'])]);
        $this->row('google_ads_ad_group_snapshot', ['ad_group_id' => 'ag1', 'metadata' => json_encode(['name' => 'Implant Genel', 'campaign_id' => 'c1'])]);
        $this->row('google_ads_ad_snapshot', ['ad_id' => 'a1', 'metadata' => json_encode(['type' => 'RESPONSIVE_SEARCH_AD', 'status' => 'ENABLED', 'ad_strength' => 'POOR', 'final_urls' => ['https://ornek.test/implant/'], 'ad_group_id' => 'ag1', 'campaign_id' => 'c1'])]);
        $this->row('google_ads_conversion_action_snapshot', ['conversion_action_id' => 'ca1', 'metadata' => json_encode(['name' => 'Form', 'status' => 'ENABLED', 'category' => 'SUBMIT_LEAD_FORM', 'primary_for_goal' => true, 'counting_type' => 'ONE_PER_CLICK'])]);
    }

    /** @param array<string, mixed> $values */
    private function row(string $table, array $values): void
    {
        DB::table($table)->insert($values + [
            'digital_asset_id' => null,
            'external_resource_id' => $this->resource->id,
            'customer_id' => '1112223333',
            'contract_version' => 1,
            'first_collected_at' => now(),
            'last_collected_at' => now(),
            'source_timezone' => 'Europe/Istanbul',
            'record_fingerprint' => hash('sha256', $table.json_encode($values)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
