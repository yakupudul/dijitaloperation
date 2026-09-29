<?php

namespace Tests\Feature\Outcomes;

use App\Enums\CustomerStatus;
use App\Enums\DigitalAssetStatus;
use App\Livewire\Demo\Dashboard;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\Analyst\AnalystDecisionStore;
use App\Services\Gbp\GbpSuggestions;
use App\Services\GoogleAds\GoogleAdsSpecialistBindingResolver;
use App\Services\GoogleAds\GoogleAdsSuggestions;
use App\Services\Outcomes\OutcomeTracker;
use App\Services\Retention\DataRetentionService;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Integrations\Google\GoogleScopes;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Faz 9 Sonuç takibi: uniform baseline on apply (İşletme Profili, Google Ads campaign / account / unknown campaign),
 * 28 / 56-day measurement from seeded facts (işe yaradı / yaramadı / belirsiz), each point once, waiting for late data,
 * Bugün "Sonuçlar", closed suggestions deleted after 12 months.
 */
final class OutcomeTrackingTest extends TestCase
{
    use RefreshDatabase;

    private const string APPLIED = '2026-06-01 10:00:00';

    private User $admin;

    private Brand $brand;

    private DigitalAsset $gbp;

    private int $gbpResourceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id, 'name' => 'Panorama Ankara']);
        $this->gbp = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_business_profile', 'status' => 'active', 'name' => 'Panorama Çankaya']);
        $integration = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $resource = CoreExternalResource::factory()->create(['integration_id' => $integration->id, 'provider' => 'google', 'resource_type' => 'google_business_profile',
            'external_id' => 'locations/22', 'status' => CoreExternalResource::STATUS_AVAILABLE]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $this->gbp->id, 'external_resource_id' => $resource->id, 'capability' => 'google_business_profile', 'status' => CoreAssetBinding::STATUS_ACTIVE]);
        $this->gbpResourceId = (int) $resource->id;
        $this->travelTo(CarbonImmutable::parse(self::APPLIED, 'Europe/Istanbul'));
    }

    public function test_apply_stores_the_uniform_baseline_of_the_28_days_before(): void
    {
        $this->gbpDays(-28, -1, 2); // 2 calls a day before apply
        $suggestion = $this->gbpSuggestion('hours');

        app(AnalystDecisionStore::class)->markDone($suggestion, $this->admin);

        $suggestion->refresh();
        $this->assertSame(Suggestion::APPLIED, $suggestion->status);
        $baseline = $suggestion->baseline;
        $this->assertSame(56, $baseline['actions']);
        $this->assertSame(56, $baseline['calls']);
        $this->assertSame(28, $baseline['window_days']);
        $this->assertSame(['2026-05-04', '2026-05-31'], [$baseline['from'], $baseline['to']]);
        $this->assertSame(['asset_id' => $this->gbp->id, 'resource_id' => $this->gbpResourceId], $baseline['scope']);
        $this->assertArrayHasKey('captured_at', $baseline);
        $this->assertArrayHasKey('facts', $baseline);
    }

    public function test_google_ads_baseline_reads_the_referenced_campaign_else_the_account_and_says_veri_yok_for_an_unknown_campaign(): void
    {
        $ads = $this->googleAds();
        $suggestions = app(GoogleAdsSuggestions::class);
        $make = function (string $key, array $action) use ($ads, $suggestions): Suggestion {
            $suggestions->replaceGroup($ads, 'check', [['key' => 'check:'.$key, 'title' => 'Öneri '.$key, 'reason' => 'Neden', 'priority' => 2, 'evidence' => [],
                'action_type' => 'ads_check', 'action' => $action]], sweep: false);

            return Suggestion::query()->where('title', 'Öneri '.$key)->sole();
        };
        $campaign = $make('campaign', ['campaign' => 'İmplant Ankara']);
        $account = $make('account', ['check' => 'budget']);
        $unknown = $make('unknown', ['campaign' => 'Olmayan kampanya']);

        foreach ([$campaign, $account, $unknown] as $suggestion) {
            $suggestions->markApplied($suggestion, $this->admin, ['write_action_id' => 7]);
        }

        $this->assertEquals(560, $campaign->fresh()->baseline['cost'], '28 days × 20 of campaign 101 only');
        $this->assertEquals(28, $campaign->fresh()->baseline['conversions']);
        $this->assertEquals(20, $campaign->fresh()->baseline['cpa']);
        $this->assertSame('101', $campaign->fresh()->baseline['scope']['campaign_id']);
        $this->assertSame(7, $campaign->fresh()->baseline['write_action_id']);
        $this->assertEquals(840, $account->fresh()->baseline['cost'], 'no campaign referenced → the whole account');
        $this->assertNull($unknown->fresh()->baseline['scope']);
        $this->assertArrayNotHasKey('cost', $unknown->fresh()->baseline);

        $this->travel(29)->days();
        app(OutcomeTracker::class)->measure($unknown->fresh());
        $this->assertSame(['unclear', 'veri yok'], [$unknown->fresh()->outcome['d28']['verdict'], $unknown->fresh()->outcome['d28']['reason']]);
    }

    public function test_measures_28_and_56_days_once_each_with_verdicts(): void
    {
        $this->gbpDays(-28, -1, 2);   // baseline 56 actions
        $this->gbpDays(0, 27, 4);     // days 1–28: 112 → işe yaradı
        $this->gbpDays(28, 55, 1);    // days 29–56: 28 → işe yaramadı
        $suggestion = $this->gbpSuggestion('hours');
        app(AnalystDecisionStore::class)->markDone($suggestion, $this->admin);

        $this->travel(20)->days();
        $this->artisan('moxdop:outcomes:measure')->assertSuccessful();
        $this->assertNull($suggestion->fresh()->outcome, 'not due before day 28');

        $this->travel(8)->days(); // day 28 after apply
        $this->artisan('moxdop:outcomes:measure')->expectsOutputToContain('Ölçülen sonuç: 1')->assertSuccessful();
        $outcome = $suggestion->fresh()->outcome;
        $this->assertSame(OutcomeTracker::WORKED, $outcome['d28']['verdict']);
        $this->assertSame(112, $outcome['d28']['actions']);
        $this->assertSame('etkileşim 56 → 112 (+%100)', $outcome['d28']['reason']);
        $this->assertArrayNotHasKey('d56', $outcome);
        $measuredAt = $suggestion->fresh()->measured_at;
        $this->assertNotNull($measuredAt);

        $this->artisan('moxdop:outcomes:measure')->expectsOutputToContain('Ölçülen sonuç: 0')->assertSuccessful();
        $this->assertEquals($outcome, $suggestion->fresh()->outcome, 'no double measurement');

        $this->travel(28)->days(); // day 56
        $this->artisan('moxdop:outcomes:measure')->expectsOutputToContain('Ölçülen sonuç: 1');
        $outcome = $suggestion->fresh()->outcome;
        $this->assertSame(OutcomeTracker::WORKED, $outcome['d28']['verdict'], 'd28 kept');
        $this->assertSame(OutcomeTracker::NOT_WORKED, $outcome['d56']['verdict']);
        $this->assertSame(['2026-06-29', '2026-07-26'], [$outcome['d56']['from'], $outcome['d56']['to']]);
        $this->assertSame('d56', OutcomeTracker::latest($suggestion->fresh())['point']);
    }

    public function test_little_data_is_belirsiz_and_missing_data_waits_then_is_belirsiz(): void
    {
        $this->gbpDays(-28, -1, 0.25); // 7 actions
        $this->gbpDays(0, 27, 0.5);     // 14 actions: below 20 in both periods
        $small = $this->gbpSuggestion('small');
        app(AnalystDecisionStore::class)->markDone($small, $this->admin);
        $late = $this->gbpSuggestion('late');
        $this->travel(1)->days();
        DB::table('gbp_performance_daily')->where('reporting_date', '>=', '2026-06-01')->delete(); // nothing collected after apply yet
        app(AnalystDecisionStore::class)->markDone($late, $this->admin);

        $this->travel(29)->days();
        $this->assertSame([], app(OutcomeTracker::class)->measure($late->fresh()), 'waits for the window to be collected');
        $this->gbpDays(0, 27, 0.5);
        $this->assertSame(['d28'], app(OutcomeTracker::class)->measure($small->fresh()));
        $this->assertSame(OutcomeTracker::UNCLEAR, $small->fresh()->outcome['d28']['verdict']);
        $this->assertSame('veri az (en az 20 etkileşim)', $small->fresh()->outcome['d28']['reason']);

        DB::table('gbp_performance_daily')->where('reporting_date', '>=', '2026-06-02')->delete();
        $this->travel(OutcomeTracker::WAIT_DAYS)->days();
        $this->assertSame(['d28'], app(OutcomeTracker::class)->measure($late->fresh()));
        $this->assertSame(['unclear', 'veri yok'], [$late->fresh()->outcome['d28']['verdict'], $late->fresh()->outcome['d28']['reason']]);
    }

    public function test_verdict_follows_the_better_direction_of_the_primary_metric(): void
    {
        $base = ['window_days' => 28, 'cost' => 1000.0, 'conversions' => 10.0, 'cpa' => 100.0];
        $this->assertSame(OutcomeTracker::WORKED, OutcomeTracker::verdict('google_ads', $base, ['cost' => 960.0, 'conversions' => 12.0, 'cpa' => 80.0])[0], 'lower cost per conversion is better');
        $this->assertSame(OutcomeTracker::NOT_WORKED, OutcomeTracker::verdict('google_ads', $base, ['cost' => 1300.0, 'conversions' => 10.0, 'cpa' => 130.0])[0]);
        $this->assertSame(OutcomeTracker::NOT_WORKED, OutcomeTracker::verdict('google_ads', $base, ['cost' => 950.0, 'conversions' => 10.0, 'cpa' => 95.0])[0], 'under 10 % is not a result');
        $this->assertSame(OutcomeTracker::UNCLEAR, OutcomeTracker::verdict('google_ads', $base, ['cost' => 900.0, 'conversions' => 0.0, 'cpa' => null])[0], 'never guessed');
        $this->assertSame(OutcomeTracker::WORKED, OutcomeTracker::verdict('search', ['window_days' => 28, 'clicks' => 40, 'impressions' => 900, 'position' => 8.0],
            ['clicks' => 55, 'impressions' => 1000, 'position' => 6.0])[0]);
        $this->assertSame(OutcomeTracker::UNCLEAR, OutcomeTracker::verdict('meta', ['spend' => 100.0], ['spend' => 100.0, 'results' => 20, 'cpr' => 5.0])[0], 'no baseline → belirsiz');
    }

    public function test_bugun_shows_the_results_block(): void
    {
        $worked = $this->measured('Çalışma saatlerini ekle', OutcomeTracker::WORKED, 'etkileşim 56 → 112 (+%100)');
        $this->measured('Açıklamayı güncelle', OutcomeTracker::NOT_WORKED, 'etkileşim 60 → 40 (−%33)');
        $this->measured('Fotoğraf ekle', OutcomeTracker::UNCLEAR, 'veri az (en az 20 etkileşim)');
        $old = $this->measured('Eski öneri', OutcomeTracker::WORKED, 'x');
        $old->forceFill(['measured_at' => now()->subDays(120)])->save();

        Livewire::actingAs($this->admin)->test(Dashboard::class)
            ->assertSee('Sonuçlar')->assertSeeInOrder(['İşe yaradı', '1', 'İşe yaramadı', '1', 'Belirsiz', '1'])
            ->assertSee('Panorama Ankara')->assertSee('Çalışma saatlerini ekle')->assertSee('etkileşim 56 → 112 (+%100)')
            ->assertSee('işe yaramadı')->assertDontSee('Eski öneri');
        $this->assertSame(['worked' => 1, 'not_worked' => 1, 'unclear' => 1], app(OutcomeTracker::class)->summary()['counts']);
        $this->assertSame(['Harita', 'Çalışma saatlerini ekle'], [app(OutcomeTracker::class)->summary()['items'][2]['channel'], $worked->title]);
    }

    public function test_bugun_without_results_says_so(): void
    {
        Livewire::actingAs($this->admin)->test(Dashboard::class)->assertSee('Sonuçlar')->assertSee('Henüz ölçülen öneri yok');
    }

    public function test_closed_suggestions_older_than_12_months_are_deleted(): void
    {
        $oldApplied = $this->gbpSuggestion('old-applied');
        $oldDismissed = $this->gbpSuggestion('old-dismissed');
        $recent = $this->gbpSuggestion('recent');
        $oldOpen = $this->gbpSuggestion('old-open');
        $oldApplied->forceFill(['status' => Suggestion::APPLIED, 'resolved_at' => now()->subMonths(13), 'applied_at' => now()->subMonths(13)])->save();
        $oldDismissed->forceFill(['status' => Suggestion::DISMISSED, 'resolved_at' => now()->subMonths(14)])->save();
        $recent->forceFill(['status' => Suggestion::APPLIED, 'resolved_at' => now()->subMonths(6), 'applied_at' => now()->subMonths(6)])->save();
        $oldOpen->forceFill(['created_at' => now()->subMonths(20), 'updated_at' => now()->subMonths(20)])->saveQuietly();

        $retention = app(DataRetentionService::class);
        $this->assertSame(2, $retention->purgeClosedSuggestions(true), 'dry run only counts');
        $this->assertSame(4, Suggestion::query()->count());
        $this->artisan('moxdop:retention --apply')->expectsOutputToContain('silinen kapalı öneri: 2')->assertSuccessful();

        $this->assertSame([$recent->id, $oldOpen->id], Suggestion::query()->orderBy('id')->pluck('id')->all());
    }

    private function gbpSuggestion(string $key): Suggestion
    {
        app(GbpSuggestions::class)->replaceGroup($this->gbp, 'standard', [['key' => 'standard:'.$key, 'title' => 'Öneri '.$key, 'reason' => 'Neden', 'priority' => 2,
            'evidence' => [['durum' => 'eksik']], 'action_type' => 'gbp_standard', 'action' => ['standard' => $key]]], sweep: false);

        return Suggestion::query()->where('title', 'Öneri '.$key)->sole();
    }

    /** Daily CALL_CLICKS from apply day + $from to apply day + $to ($perDay may be fractional: spread over the days). */
    private function gbpDays(int $from, int $to, float $perDay): void
    {
        $applied = CarbonImmutable::parse(self::APPLIED, 'Europe/Istanbul')->startOfDay();
        $carry = 0.0;
        for ($day = $from; $day <= $to; $day++) {
            $carry += $perDay;
            $value = (int) floor($carry);
            $carry -= $value;
            DB::table('gbp_performance_daily')->updateOrInsert(['external_resource_id' => $this->gbpResourceId, 'reporting_date' => $applied->addDays($day)->toDateString(), 'metric' => 'CALL_CLICKS'],
                ['digital_asset_id' => $this->gbp->id, 'run_id' => 1, 'location_name' => 'locations/22', 'value' => $value, 'collected_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function measured(string $title, string $verdict, string $reason): Suggestion
    {
        $suggestion = $this->gbpSuggestion($title);
        $suggestion->forceFill(['title' => $title, 'status' => Suggestion::APPLIED, 'applied_at' => now()->subDays(30), 'measured_at' => now()->subDay(),
            'outcome' => ['d28' => ['verdict' => $verdict, 'reason' => $reason, 'from' => '2026-05-02', 'to' => '2026-05-29']]])->save();

        return $suggestion;
    }

    private function googleAds(): DigitalAsset
    {
        config(['moxdop.google.client_id' => 'cid', 'moxdop.google.client_secret' => 'csecret', 'moxdop.google.developer_token' => 'devtoken']);
        $asset = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_ads', 'module_id' => 'google_ads', 'status' => DigitalAssetStatus::Active, 'name' => 'Panorama Ads']);
        $integration = CoreIntegration::query()->where('provider', 'google')->sole();
        $integration->update(['config' => ['granted_scopes' => [GoogleScopes::ADWORDS]]]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $integration->id, 'encrypted_payload' => ['client_id' => 'cid', 'client_secret' => 'csecret', 'developer_token' => 'devtoken']]);
        CoreIntegrationCredential::factory()->authorization()->create(['integration_id' => $integration->id, 'encrypted_payload' => ['access_token' => 'a', 'refresh_token' => 'r', 'scope' => GoogleScopes::ADWORDS], 'expires_at' => now()->addHour()]);
        $resource = CoreExternalResource::factory()->create(['integration_id' => $integration->id, 'provider' => 'google', 'resource_type' => GoogleResourceType::GOOGLE_ADS_CUSTOMER,
            'external_id' => '1112223333', 'status' => CoreExternalResource::STATUS_AVAILABLE, 'metadata' => ['timezone' => 'Europe/Istanbul', 'currency' => 'TRY']]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id, 'capability' => GoogleAdsSpecialistBindingResolver::CAPABILITY, 'status' => CoreAssetBinding::STATUS_ACTIVE]);
        $provenance = fn (): array => ['digital_asset_id' => null, 'external_resource_id' => $resource->id, 'customer_id' => '1112223333', 'source_timezone' => 'Europe/Istanbul',
            'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => bin2hex(random_bytes(32)), 'created_at' => now(), 'updated_at' => now()];
        DB::table('google_ads_campaign_snapshot')->insert(['campaign_id' => '101', 'metadata' => json_encode(['name' => 'İmplant Ankara', 'status' => 'ENABLED'])] + $provenance());
        DB::table('google_ads_campaign_snapshot')->insert(['campaign_id' => '102', 'metadata' => json_encode(['name' => 'Genel', 'status' => 'ENABLED'])] + $provenance());
        for ($day = 1; $day <= 40; $day++) {
            $date = now('Europe/Istanbul')->subDays($day)->toDateString();
            foreach ([['101', 20, 1], ['102', 10, 0]] as [$campaign, $cost, $conversions]) {
                DB::table('google_ads_campaign_daily')->insert(['campaign_id' => $campaign, 'reporting_date' => $date, 'impressions' => 100, 'clicks' => 5, 'cost_micros' => 0,
                    'cost_amount' => $cost, 'conversions' => $conversions, 'currency' => 'TRY', 'metadata' => '{}'] + $provenance());
            }
        }

        return $asset;
    }
}
