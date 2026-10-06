<?php

namespace Tests\Feature\Collection;

use App\Enums\Collection\ActivityTier;
use App\Enums\Collection\CollectionRunStatus;
use App\Enums\CustomerStatus;
use App\Enums\DataPool\MaterializationStatus;
use App\Enums\DigitalAssetStatus;
use App\Jobs\Async\ResourceCollectionJob;
use App\Livewire\Operator\ActivityPauseToggle;
use App\Models\Brand;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DataPool\DatasetMaterialization;
use App\Models\DigitalAsset;
use App\Models\ResourceActivity;
use App\Models\ResourceAutomation;
use App\Models\User;
use App\Services\Collection\Activity\ActivityCollectionPlan;
use App\Services\Collection\Activity\ActivityTierService;
use App\Services\Collection\Activity\CollectionActivityGate;
use App\Services\Collection\Ga4\Ga4CentralCollectionService;
use App\Services\Collection\GoogleAds\GoogleAdsCentralCollectionService;
use App\Services\Collection\Providers\Ga4\Ga4RequestFamilyCatalog;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsCentralRequestFamilyCatalog;
use App\Services\Collection\Providers\SearchConsole\SearchConsoleCentralDatasetExecutor;
use App\Services\Collection\SearchConsole\SearchConsoleCentralCollectionService;
use App\Services\DataPool\Freshness\DataFreshnessPolicyLoader;
use App\Services\DataPool\Freshness\DueCollectionQueryService;
use App\Services\Integrations\Meta\MetaApiClient;
use App\Services\Integrations\ResourceAutomationService;
use App\Services\Operations\SystemHealthReader;
use App\Support\Integrations\Google\GoogleOAuthConfig;
use App\Support\Integrations\Google\GoogleScopes;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\Support\InsertsFacts;
use Tests\TestCase;

/**
 * Activity-aware collection: tiers from facts, tier-shaped plans (full / light weekly / cheap weekly check),
 * structure snapshots only on provider change, operator pause, resume backfill, 13-month initial load and the
 * short re-fetch windows.
 */
class ActivityAwareCollectionTest extends TestCase
{
    use InsertsFacts;
    use RefreshDatabase;

    private const string NOW = '2026-09-27 10:00:00';

    private User $admin;

    private CoreIntegration $google;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::NOW, 'UTC'));
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
        config([
            'moxdop.google.client_id' => 'cid',
            'moxdop.google.client_secret' => 'csecret',
            'moxdop.google.developer_token' => 'app-level-dev-token',
            'moxdop.google.ads_api_version' => 'v25',
            'moxdop-google-ads-collector.minimum_request_interval_ms' => 0,
        ]);

        $this->google = CoreIntegration::factory()->google()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => ['granted_scopes' => [GoogleScopes::ADWORDS]],
        ]);
        CoreIntegrationCredential::factory()->provider()->create([
            'integration_id' => $this->google->id,
            'encrypted_payload' => ['client_id' => 'cid', 'client_secret' => 'csecret'],
        ]);
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $this->google->id,
            'encrypted_payload' => ['access_token' => 'ads-access-token', 'refresh_token' => 'ads-refresh-token', 'scope' => GoogleScopes::ADWORDS],
            'expires_at' => now()->addHour(),
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_tiers_are_computed_from_stored_facts_for_every_provider(): void
    {
        $adsActive = $this->resource('google_ads', '1110000001');
        $adsIdle = $this->resource('google_ads', '1110000002');
        $adsDormant = $this->resource('google_ads', '1110000003');
        $this->adsSpend($adsActive, '2026-09-24', 12.5);
        $this->adsSpend($adsIdle, '2026-09-10', 40);
        $this->adsSpend($adsIdle, '2026-09-25', 0); // zero spend is not activity
        $this->adsSpend($adsDormant, '2026-08-01', 99);

        $meta = $this->resource('meta_ads', 'act_2220001', 'meta');
        $this->metaSpend($meta, '2026-09-12', 5);

        $ga4 = $this->resource('ga4', 'properties/3330001');
        $this->ga4Sessions($ga4, '2026-09-26', 300);

        // Search Console lags three days: clicks on 09-18 are 6 days before the latest final day (09-24) → still active.
        $gsc = $this->resource('search_console', 'sc-domain:example.test');
        $this->gscClicks($gsc, '2026-09-18', 12);

        $counts = app(ActivityTierService::class)->refresh();

        $this->assertSame(ActivityTier::Active, $this->tier($adsActive));
        $this->assertSame(ActivityTier::Idle, $this->tier($adsIdle));
        $this->assertSame(ActivityTier::Dormant, $this->tier($adsDormant));
        $this->assertSame(ActivityTier::Idle, $this->tier($meta));
        $this->assertSame(ActivityTier::Active, $this->tier($ga4));
        $this->assertSame(ActivityTier::Active, $this->tier($gsc));
        $this->assertSame('2026-09-10', ResourceActivity::query()->where('external_resource_id', $adsIdle->id)->first()->last_active_on->toDateString());
        $this->assertSame(['active' => 3, 'idle' => 2, 'dormant' => 1, 'paused' => 0], $counts);
    }

    public function test_a_new_binding_without_facts_is_active_until_its_initial_load_completes(): void
    {
        $resource = $this->resource('google_ads', '1110000009', automationSuccess: false);
        $tiers = app(ActivityTierService::class);

        $this->assertSame(ActivityTier::Active, $tiers->refreshResource($resource)->tier);

        ResourceAutomation::query()->where('external_resource_id', $resource->id)->update(['last_collection_success_at' => now()]);
        $this->assertSame(ActivityTier::Dormant, $tiers->refreshResource($resource)->tier);
    }

    public function test_operator_pause_counts_as_dormant_and_clears_itself_when_spend_reappears(): void
    {
        [$asset, $resource] = $this->boundAds('meta_ads', 'act_4440001', 'meta');
        $this->metaSpend($resource, '2026-09-25', 30);
        $tiers = app(ActivityTierService::class);
        $tiers->refresh();
        ResourceActivity::query()->where('external_resource_id', $resource->id)->update(['last_full_collection_at' => '2026-09-20 06:00:00']);

        $tiers->pause($resource->id, $this->admin);
        $this->assertSame(ActivityTier::Dormant, $tiers->tierFor($resource->id));
        $this->assertSame(['tier' => 'dormant', 'last_active_on' => '2026-09-25', 'operator_paused' => true], $tiers->forAsset($asset->id));
        $plan = app(CollectionActivityGate::class)->plan($resource);
        $this->assertSame(ActivityCollectionPlan::MODE_CHECK, $plan->mode);

        // Spend on the pause day itself does not clear it …
        $this->metaSpend($resource, '2026-09-27', 3);
        $tiers->refreshResource($resource);
        $this->assertTrue($tiers->row($resource->id)->isPaused());

        // … spend on a later day does, and collection resumes immediately with a backfill from the last full collection.
        ResourceAutomation::query()->where('external_resource_id', $resource->id)->update(['next_collection_at' => now()->addDays(6)]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-29 10:00:00', 'UTC'));
        Carbon::setTestNow(Carbon::parse('2026-09-29 10:00:00', 'UTC'));
        $this->metaSpend($resource, '2026-09-28', 8);
        $row = $tiers->refreshResource($resource);

        $this->assertFalse($row->isPaused());
        $this->assertSame(ActivityTier::Active, $row->effectiveTier());
        $this->assertSame('2026-09-20', $row->backfill_from->toDateString());
        $this->assertTrue(ResourceAutomation::query()->where('external_resource_id', $resource->id)->first()->next_collection_at->lessThanOrEqualTo(now()));
        $this->assertSame(['tier' => 'active', 'last_active_on' => '2026-09-28', 'operator_paused' => false], $tiers->forAsset($asset->id));
    }

    public function test_operator_can_clear_the_pause_and_the_livewire_toggle_sets_and_clears_it(): void
    {
        [$asset, $resource] = $this->boundAds('google_ads', '1110000020');
        $this->adsSpend($resource, '2026-09-25', 10);

        $this->actingAs($this->admin);
        Livewire::test(ActivityPauseToggle::class, ['assetId' => (string) $asset->id])
            ->assertSee('Duraklat (müşteri kararı)')
            ->call('pause')
            ->assertSee('Duraklatıldı (müşteri kararı)');
        $row = ResourceActivity::query()->where('external_resource_id', $resource->id)->first();
        $this->assertTrue($row->isPaused());
        $this->assertSame($this->admin->id, (int) $row->operator_paused_by);

        Livewire::test(ActivityPauseToggle::class, ['assetId' => (string) $asset->id])
            ->call('resume')
            ->assertSee('Duraklat (müşteri kararı)');
        $this->assertFalse($row->fresh()->isPaused());
        $this->assertSame(ActivityTier::Active, app(ActivityTierService::class)->tierFor($resource->id));

        // The header component the asset pages place renders the toggle for ad assets only.
        $this->blade('<x-operator.activity-pause :asset="$asset" />', ['asset' => $asset])->assertSee('Duraklat (müşteri kararı)');
        $website = DigitalAsset::factory()->create(['brand_id' => $asset->brand_id, 'type' => 'website']);
        $this->blade('<x-operator.activity-pause :asset="$asset" />', ['asset' => $website])->assertDontSee('Duraklat');
    }

    public function test_google_ads_active_account_plans_the_full_set_with_a_weekly_deep_window_then_three_days(): void
    {
        $resource = $this->resource('google_ads', '1112223333');
        $this->adsSpend($resource, '2026-09-26', 50);
        $this->centralBaseline($resource, 'GOOGLE_ADS', [
            GoogleAdsCentralRequestFamilyCatalog::ACCOUNT_DAILY => '2026-09-26',
            GoogleAdsCentralRequestFamilyCatalog::CAMPAIGN_DAILY => '2026-09-26',
        ]);

        $first = $this->googleAdsPlan($resource);
        $updateFamilies = array_values(array_filter(GoogleAdsCentralRequestFamilyCatalog::supportedFamilies(),
            fn (string $f): bool => ! GoogleAdsCentralRequestFamilyCatalog::isHistoryFamily($f)));
        $this->assertSame('full', $first['activity']['mode']);
        $this->assertSame($updateFamilies, array_column($first['families'], 'family'), 'active accounts collect every dataset (structure never collected yet)');
        $this->assertSame(['start' => '2026-08-28', 'end' => '2026-09-26'], $this->range($first, GoogleAdsCentralRequestFamilyCatalog::CAMPAIGN_DAILY), 'weekly deep restatement: 30 days');

        $second = $this->googleAdsPlan($resource);
        $this->assertSame(['start' => '2026-09-24', 'end' => '2026-09-26'], $this->range($second, GoogleAdsCentralRequestFamilyCatalog::CAMPAIGN_DAILY), 'daily window: last 3 days');
        $this->assertSame(2, DB::table('collection_activity_passes')->where('external_resource_id', $resource->id)->where('mode', 'full')->count());
    }

    public function test_google_ads_structure_snapshots_are_skipped_when_unchanged_and_replanned_on_change(): void
    {
        $resource = $this->resource('google_ads', '1112223333');
        $this->adsSpend($resource, '2026-09-26', 50);
        $this->centralBaseline($resource, 'GOOGLE_ADS', [GoogleAdsCentralRequestFamilyCatalog::ACCOUNT_DAILY => '2026-09-26'],
            structureFamilies: (array) config('moxdop-collection-activity.structure_families.GOOGLE_ADS'), structureFinishedAt: '2026-09-25 04:00:00');
        $url = GoogleOAuthConfig::adsApiUrl('customers/1112223333/googleAds:search');
        $results = [];
        Http::fake(function ($request) use ($url, &$results) {
            return $request->url() === $url ? Http::response(['results' => $results]) : null;
        });

        $unchanged = array_column($this->googleAdsPlan($resource)['families'], 'family');
        $this->assertNotContains(GoogleAdsCentralRequestFamilyCatalog::ENTITY_SNAPSHOT, $unchanged);
        $this->assertNotContains('GADS_CENTRAL_RF_BIDDING_STRATEGIES', $unchanged);
        $this->assertContains(GoogleAdsCentralRequestFamilyCatalog::CAMPAIGN_DAILY, $unchanged);
        Http::assertSent(fn ($request): bool => $request->url() === $url
            && str_contains((string) ($request->data()['query'] ?? ''), 'FROM change_status')
            && str_contains((string) ($request->data()['query'] ?? ''), "last_change_date_time >= '2026-09-25 04:00:00'")
            && ! str_contains(strtolower($request->url()), 'mutate'));
        $pass = DB::table('collection_activity_passes')->where('external_resource_id', $resource->id)->latest('id')->first();
        $this->assertSame(4, (int) $pass->skipped_datasets, 'entity snapshot, two negative keyword snapshots, bidding strategies');

        $results = [['changeStatus' => ['resourceName' => 'customers/1112223333/changeStatus/1', 'lastChangeDateTime' => '2026-09-26 12:00:00']]];
        $changed = array_column($this->googleAdsPlan($resource)['families'], 'family');
        $this->assertContains(GoogleAdsCentralRequestFamilyCatalog::ENTITY_SNAPSHOT, $changed);
        $this->assertContains('GADS_CENTRAL_RF_BIDDING_STRATEGIES', $changed);

        // Weekly safety net: a week after the last structure collection they are planned without asking.
        $results = [];
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-03 10:00:00', 'UTC'));
        Carbon::setTestNow(Carbon::parse('2026-10-03 10:00:00', 'UTC'));
        $this->assertContains(GoogleAdsCentralRequestFamilyCatalog::ENTITY_SNAPSHOT, array_column($this->googleAdsPlan($resource)['families'], 'family'));
    }

    public function test_google_ads_idle_account_gets_the_light_set_weekly_and_dormant_account_a_cheap_check(): void
    {
        $idle = $this->resource('google_ads', '1112220001');
        $this->adsSpend($idle, '2026-09-12', 20);
        $this->centralBaseline($idle, 'GOOGLE_ADS', [GoogleAdsCentralRequestFamilyCatalog::ACCOUNT_DAILY => '2026-09-20']);

        $plan = $this->googleAdsPlan($idle);
        $this->assertSame('light', $plan['activity']['mode']);
        $this->assertSame([GoogleAdsCentralRequestFamilyCatalog::ACCOUNT_DAILY], array_column($plan['families'], 'family'));
        $this->assertSame(['start' => '2026-09-21', 'end' => '2026-09-26'], $plan['families'][0]['date_range'], 'light set continues from its coverage');
        $total = count(GoogleAdsCentralRequestFamilyCatalog::supportedFamilies()) - 1;
        $pass = DB::table('collection_activity_passes')->where('external_resource_id', $idle->id)->first();
        $this->assertSame(['light', 1, $total - 1], [$pass->mode, (int) $pass->planned_datasets, (int) $pass->skipped_datasets]);

        // Planning alone does not start the weekly clock (a failed pass must be retried soon); its success does.
        $gate = app(CollectionActivityGate::class);
        $this->assertTrue($gate->plan($idle)->due);
        $gate->markLightCheck((int) $idle->id);
        // Weekly: not due again until seven days after the light pass.
        $this->assertFalse($gate->plan($idle)->due);
        $automation = ResourceAutomation::query()->where('external_resource_id', $idle->id)->first();
        $this->assertSame('2026-10-04', app(ResourceAutomationService::class)->nextAt($automation)->toDateString());
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-04 10:00:00', 'UTC'));
        Carbon::setTestNow(Carbon::parse('2026-10-04 10:00:00', 'UTC'));
        $this->assertTrue($gate->plan($idle)->due);

        $dormant = $this->resource('google_ads', '1112220002');
        $this->adsSpend($dormant, '2026-06-01', 20);
        $this->centralBaseline($dormant, 'GOOGLE_ADS', [
            GoogleAdsCentralRequestFamilyCatalog::ACCOUNT_DAILY => '2026-07-01',
            GoogleAdsCentralRequestFamilyCatalog::CAMPAIGN_DAILY => '2026-07-01',
        ]);
        $check = $this->googleAdsPlan($dormant);
        $this->assertSame('check', $check['activity']['mode']);
        $this->assertSame([GoogleAdsCentralRequestFamilyCatalog::ACCOUNT_DAILY], array_column($check['families'], 'family'));
        $this->assertSame(['start' => '2026-07-02', 'end' => '2026-10-03'], $check['families'][0]['date_range'], 'dormant: account totals from the day after the stored coverage, no gap');
    }

    public function test_resumed_activity_returns_to_full_collection_and_backfills_the_gap(): void
    {
        $resource = $this->resource('google_ads', '1112220003');
        $this->adsSpend($resource, '2026-07-10', 20);
        $this->centralBaseline($resource, 'GOOGLE_ADS', [
            GoogleAdsCentralRequestFamilyCatalog::ACCOUNT_DAILY => '2026-09-20',
            GoogleAdsCentralRequestFamilyCatalog::CAMPAIGN_DAILY => '2026-07-17',
        ]);
        $tiers = app(ActivityTierService::class);
        $tiers->refresh();
        ResourceActivity::query()->where('external_resource_id', $resource->id)->update(['last_full_collection_at' => '2026-07-17 05:00:00']);
        $this->assertSame(ActivityTier::Dormant, $tiers->tierFor($resource->id));

        // The weekly check finds spend again.
        $this->adsSpend($resource, '2026-09-26', 35);
        $row = $tiers->refreshResource($resource);
        $this->assertSame(ActivityTier::Active, $row->effectiveTier());
        $this->assertSame('2026-07-17', $row->backfill_from->toDateString());
        $automation = ResourceAutomation::query()->where('external_resource_id', $resource->id)->first();
        $this->assertFalse($automation->next_collection_at->isFuture(), 'due immediately');
        $this->assertFalse(app(ResourceAutomationService::class)->nextAt($automation)->isFuture(), 'stays due until the backfill ran');

        $plan = $this->googleAdsPlan($resource);
        $this->assertSame('full', $plan['activity']['mode']);
        $this->assertSame('2026-07-18', $this->range($plan, GoogleAdsCentralRequestFamilyCatalog::CAMPAIGN_DAILY)['start'], 'gap since the last full collection');
        $this->assertContains(GoogleAdsCentralRequestFamilyCatalog::SEARCH_TERM, array_column($plan['families'], 'family'));

        $tiers->markFullCollection($resource->id);
        $this->assertNull($tiers->row($resource->id)->backfill_from);
    }

    public function test_search_console_idle_plans_property_totals_only_and_active_refetches_four_days(): void
    {
        $idle = $this->resource('search_console', 'sc-domain:idle.test');
        $this->gscClicks($idle, '2026-09-05', 3);
        $this->centralBaseline($idle, 'SEARCH_CONSOLE', [SearchConsoleCentralDatasetExecutor::FAMILY_ANALYTICS => '2026-09-24']);

        $plan = $this->privatePlan(SearchConsoleCentralCollectionService::class, $idle);
        $this->assertSame('light', $plan['activity']['mode']);
        $this->assertSame(['gsc_property_daily'], array_column($plan['dataset_plans'], 'dataset_id'));
        $this->assertSame(['web'], $plan['active_search_types']);
        Http::assertNothingSent();

        $active = $this->resource('search_console', 'sc-domain:active.test');
        $this->gscClicks($active, '2026-09-23', 30);
        $this->centralBaseline($active, 'SEARCH_CONSOLE', [SearchConsoleCentralDatasetExecutor::FAMILY_ANALYTICS => '2026-09-24']);
        Http::fake(['*' => Http::response(['rows' => []])]);
        $full = $this->privatePlan(SearchConsoleCentralCollectionService::class, $active);
        $this->assertSame('full', $full['activity']['mode']);
        $this->assertContains('gsc_query_page_daily', array_column($full['dataset_plans'], 'dataset_id'));
        $this->assertSame(4, $full['days'], 'Search Console re-fetches the last 4 final days');
    }

    public function test_ga4_dormant_property_is_checked_for_seven_days_and_active_refetches_three_days(): void
    {
        $dormant = $this->resource('ga4', 'properties/5550001');
        $this->ga4Sessions($dormant, '2026-05-01', 10);
        Cache::put('ga4:property-context:properties/5550001', ['timeZone' => 'UTC'], 3600);
        $this->centralBaseline($dormant, 'GA4', [Ga4RequestFamilyCatalog::FAMILY_PROPERTY_DAILY => '2026-09-19']);

        $check = $this->privatePlan(Ga4CentralCollectionService::class, $dormant);
        $this->assertSame('check', $check['activity']['mode']);
        $this->assertSame([Ga4RequestFamilyCatalog::FAMILY_PROPERTY_DAILY], $check['families']);
        $this->assertSame(['start' => '2026-09-20', 'end' => '2026-09-26'], $check['family_ranges'][Ga4RequestFamilyCatalog::FAMILY_PROPERTY_DAILY]);

        $active = $this->resource('ga4', 'properties/5550002');
        $this->ga4Sessions($active, '2026-09-26', 10);
        Cache::put('ga4:property-context:properties/5550002', ['timeZone' => 'UTC'], 3600);
        $this->centralBaseline($active, 'GA4', array_fill_keys(Ga4RequestFamilyCatalog::centralFamilies(), '2026-09-26'));
        $full = $this->privatePlan(Ga4CentralCollectionService::class, $active);
        $this->assertSame(Ga4RequestFamilyCatalog::centralFamilies(), $full['families']);
        $this->assertSame(['start' => '2026-09-24', 'end' => '2026-09-26'], $full['family_ranges'][Ga4RequestFamilyCatalog::FAMILY_LANDING_SOURCE_DAILY]);
        Http::assertNothingSent();
    }

    public function test_meta_due_query_follows_the_activity_tier_and_gates_structure_snapshots(): void
    {
        [$asset, $resource, $binding] = $this->boundAds('meta_ads', 'act_7770001', 'meta');
        $this->metaMaterialization($binding, 'meta_account_daily', '2026-09-20');
        $this->metaMaterialization($binding, 'meta_campaign_daily', '2026-09-20');
        $this->metaMaterialization($binding, 'meta_campaign_snapshot', null, '2026-09-10 00:00:00');
        $this->metaMaterialization($binding, 'meta_ad_snapshot', null, '2026-09-10 00:00:00');
        $gated = fn (): array => app(DueCollectionQueryService::class)->query(['core_asset_binding_ids' => [$binding->id], 'activity_gate' => true]);
        $families = fn (array $items): array => array_values(array_unique(array_map(fn ($i) => $i->requestFamilyId, array_filter($items, fn ($i) => ! $i->actionRequired))));

        // Idle: account totals only.
        $this->metaSpend($resource, '2026-09-10', 5);
        $this->assertSame(['META_V2_RF_ACCOUNT_DAILY'], $families($gated()));

        // Dormant: account totals for the last 7 days only.
        DB::table('meta_account_daily')->delete();
        $this->metaMaterialization($binding, 'meta_account_daily', '2026-08-01');
        app(ActivityTierService::class)->refreshResource($resource);
        ResourceActivity::query()->where('external_resource_id', $resource->id)->update(['last_light_check_at' => null]);
        $dormantItems = $gated();
        $this->assertSame(['META_V2_RF_ACCOUNT_DAILY'], $families($dormantItems));
        $this->assertSame(['start' => '2026-09-20', 'end' => '2026-09-26'], $dormantItems[0]->dateRange);

        // Active with a recent structure collection: snapshots only when Meta reports updated_time changes.
        $this->metaSpend($resource, '2026-09-26', 9);
        $this->metaMaterialization($binding, 'meta_account_daily', '2026-09-20');
        app(ActivityTierService::class)->refreshResource($resource);
        ResourceActivity::query()->where('external_resource_id', $resource->id)->update(['backfill_from' => null]);
        $this->structureRun($resource, 'META_ADS', ['RF_META_ENTITY_SNAPSHOT', 'META_V2_RF_AD_SNAPSHOT'], '2026-09-26 02:00:00');
        $calls = [];
        $this->mock(MetaApiClient::class, function ($mock) use (&$calls): void {
            $mock->shouldReceive('get')->andReturnUsing(function ($integration, string $path, array $query) use (&$calls): array {
                $calls[] = [$path, $query];

                return ['data' => []];
            });
        });
        $unchanged = $families($gated());
        $this->assertContains('META_V2_RF_CAMPAIGN_DAILY', $unchanged);
        $this->assertNotContains('RF_META_ENTITY_SNAPSHOT', $unchanged);
        $this->assertSame(['act_7770001/campaigns', 'act_7770001/adsets', 'act_7770001/ads'], array_column($calls, 0));
        $this->assertStringContainsString('campaign.updated_time', (string) $calls[0][1]['filtering']);
        $this->assertStringContainsString('GREATER_THAN', (string) $calls[0][1]['filtering']);

        $this->mock(MetaApiClient::class, fn ($mock) => $mock->shouldReceive('get')->andReturn(['data' => [['id' => '1', 'updated_time' => '2026-09-26T20:00:00+0000']]]));
        $this->assertContains('RF_META_ENTITY_SNAPSHOT', $families($gated()));
    }

    public function test_meta_resume_backfills_beyond_the_normal_catch_up_bound(): void
    {
        [, $resource, $binding] = $this->boundAds('meta_ads', 'act_7770002', 'meta');
        $this->metaMaterialization($binding, 'meta_campaign_daily', '2026-07-01');
        $this->metaSpend($resource, '2026-09-26', 9);
        app(ActivityTierService::class)->refreshResource($resource);

        $bounded = app(DueCollectionQueryService::class)->query(['core_asset_binding_ids' => [$binding->id], 'activity_gate' => true]);
        $campaign = collect($bounded)->firstWhere('requestFamilyId', 'META_V2_RF_CAMPAIGN_DAILY');
        $this->assertSame('2026-08-23', $campaign->dateRange['start'], 'without a resume the catch-up stays bounded (35 days)');

        ResourceActivity::query()->where('external_resource_id', $resource->id)->update(['backfill_from' => '2026-07-01']);
        $resumed = app(DueCollectionQueryService::class)->query(['core_asset_binding_ids' => [$binding->id], 'activity_gate' => true]);
        $campaign = collect($resumed)->firstWhere('requestFamilyId', 'META_V2_RF_CAMPAIGN_DAILY');
        $this->assertSame('2026-07-02', $campaign->dateRange['start'], 'resume backfills the whole gap');
    }

    public function test_initial_load_is_thirteen_months_and_daily_refetch_windows_are_short(): void
    {
        $this->assertSame(395, Ga4CentralCollectionService::INITIAL_DAYS);
        $this->assertSame(395, SearchConsoleCentralCollectionService::INITIAL_DAYS);
        $this->assertSame(13, (int) config('moxdop-google-ads-history.granular_lookback_months'));
        foreach (['META_V2_RF_ACCOUNT_DAILY', 'META_V2_RF_CAMPAIGN_DAILY', 'META_V2_RF_AD_DAILY'] as $family) {
            $this->assertSame('395d', config('moxdop-meta-ads-central.families.'.$family.'.history'), $family);
        }

        $this->assertSame(3, Ga4CentralCollectionService::RESTATEMENT_DAYS);
        $this->assertSame(3, GoogleAdsCentralCollectionService::RESTATEMENT_DAYS);
        $this->assertSame(4, SearchConsoleCentralCollectionService::RESTATEMENT_DAYS);
        $meta = app(DataFreshnessPolicyLoader::class)->policy('meta_ad_daily');
        $this->assertSame(3, $meta['late_data_reprocessing']['window_days']);
        $this->assertTrue($meta['weekly_reconciliation']['enabled'], 'weekly 35-day attribution replay is kept');

        // Search Console initial plan covers 13 months.
        $gsc = $this->resource('search_console', 'sc-domain:new.test', automationSuccess: false);
        $initial = $this->privatePlan(SearchConsoleCentralCollectionService::class, $gsc);
        $this->assertSame('initial', $initial['mode']);
        $this->assertSame(395, $initial['days']);
        $this->assertSame('2025-08-26', $initial['dataset_plans'][0]['date_range']['start']);
    }

    public function test_resource_automation_waits_for_the_weekly_pass_of_idle_accounts_and_refreshes_tiers_after_collection(): void
    {
        Queue::fake();
        [, $idle] = $this->boundAds('google_ads', '1112220011');
        [, $active] = $this->boundAds('google_ads', '1112220012');
        Customer::query()->update(['status' => CustomerStatus::Active->value]);
        $this->adsSpend($idle, '2026-09-12', 20);
        $this->adsSpend($active, '2026-09-26', 20);
        app(ActivityTierService::class)->refresh();
        ResourceActivity::query()->where('external_resource_id', $idle->id)->update(['last_light_check_at' => now()->subDays(2)]);

        (new ReflectionMethod(ResourceAutomationService::class, 'admitCollections'))
            ->invoke(app(ResourceAutomationService::class), 'google_ads', 'database');

        $idleAutomation = ResourceAutomation::query()->where('external_resource_id', $idle->id)->first();
        $this->assertNotSame('planning', $idleAutomation->collection_status);
        $this->assertSame('2026-10-02', $idleAutomation->next_collection_at->toDateString(), 'weekly: seven days after the last light pass');
        $this->assertSame('planning', ResourceAutomation::query()->where('external_resource_id', $active->id)->value('collection_status'));
        Queue::assertPushed(ResourceCollectionJob::class, 1);

        // A successful full collection moves the full-collection mark and recomputes the tier.
        $run = CollectionRun::factory()->create(['status' => CollectionRunStatus::Completed]);
        CollectionResourceRun::factory()->create([
            'collection_run_id' => $run->id, 'provider_or_source' => 'GOOGLE_ADS', 'external_resource_id' => $active->id,
            'status' => CollectionRunStatus::Completed, 'metadata' => ['activity' => ['mode' => 'full']],
        ]);
        $automation = ResourceAutomation::query()->where('external_resource_id', $active->id)->first();
        $automation->update(['collection_run_id' => $run->id, 'collection_status' => 'collecting']);
        (new ReflectionMethod(ResourceAutomationService::class, 'reconcile'))->invoke(app(ResourceAutomationService::class), $automation->fresh());

        $row = ResourceActivity::query()->where('external_resource_id', $active->id)->first();
        $this->assertSame(self::NOW, $row->last_full_collection_at->format('Y-m-d H:i:s'));
        $this->assertSame(self::NOW, $row->refreshed_at->format('Y-m-d H:i:s'));
    }

    public function test_system_health_exposes_collection_activity(): void
    {
        $resource = $this->resource('google_ads', '1112220004');
        $this->adsSpend($resource, '2026-09-12', 20);
        $this->centralBaseline($resource, 'GOOGLE_ADS', [GoogleAdsCentralRequestFamilyCatalog::ACCOUNT_DAILY => '2026-09-20']);
        $this->googleAdsPlan($resource);

        $health = app(SystemHealthReader::class)->read()['collection_activity'];
        $this->assertSame(['active' => 0, 'idle' => 1, 'dormant' => 0], $health['tiers']);
        $this->assertSame(1, $health['passes_24h']);
        $this->assertSame(1, $health['planned_datasets_24h']);
        $this->assertSame(count(GoogleAdsCentralRequestFamilyCatalog::supportedFamilies()) - 2, $health['skipped_datasets_24h']);
    }

    // ---------------------------------------------------------------- helpers

    private function resource(string $type, string $externalId, string $provider = 'google', bool $automationSuccess = true): CoreExternalResource
    {
        $integration = $provider === 'google' ? $this->google : CoreIntegration::factory()->meta()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => $provider,
            'resource_type' => $type,
            'external_id' => $externalId,
            'display_name' => 'Account '.$externalId,
            'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => ['is_manager' => false, 'time_zone' => 'UTC', 'timezone' => 'UTC', 'currency_code' => 'TRY'],
        ]);
        ResourceAutomation::query()->create([
            'external_resource_id' => $resource->id,
            'collection_enabled' => true,
            'last_collection_success_at' => $automationSuccess ? now()->subDay() : null,
            'next_collection_at' => now(),
        ]);

        return $resource;
    }

    /** @return array{0: DigitalAsset, 1: CoreExternalResource, 2: CoreAssetBinding} */
    private function boundAds(string $type, string $externalId, string $provider = 'google'): array
    {
        $resource = $this->resource($type, $externalId, $provider);
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $asset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => $type, 'status' => DigitalAssetStatus::Active]);
        $binding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $resource->id,
            'capability' => $type,
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);

        return [$asset, $resource, $binding];
    }

    private function tier(CoreExternalResource $resource): ActivityTier
    {
        return ResourceActivity::query()->where('external_resource_id', $resource->id)->firstOrFail()->tier;
    }

    /** @return array<string, mixed> */
    private function provenance(string $date, string $seed): array
    {
        return ['contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
            'record_fingerprint' => hash('sha256', $seed.$date), 'created_at' => now(), 'updated_at' => now()];
    }

    private function adsSpend(CoreExternalResource $resource, string $date, float $cost): void
    {
        $this->insertFact('google_ads_account_daily', [
            'external_resource_id' => $resource->id, 'customer_id' => (string) $resource->external_id, 'reporting_date' => $date,
            'impressions' => 100, 'clicks' => 5, 'cost_micros' => (int) ($cost * 1_000_000), 'conversions' => 0, 'cost_amount' => $cost, 'currency' => 'TRY',
        ] + $this->provenance($date, 'gads'.$resource->id));
    }

    private function metaSpend(CoreExternalResource $resource, string $date, float $spend): void
    {
        $this->insertFact('meta_account_daily', [
            'external_resource_id' => $resource->id, 'account_id' => preg_replace('/\D+/', '', (string) $resource->external_id), 'reporting_date' => $date,
            'spend' => $spend, 'impressions' => 100, 'clicks' => 5, 'currency' => 'TRY',
        ] + $this->provenance($date, 'meta'.$resource->id));
    }

    private function ga4Sessions(CoreExternalResource $resource, string $date, int $sessions): void
    {
        $this->insertFact('ga4_property_daily', [
            'external_resource_id' => $resource->id, 'property_id' => preg_replace('/\D+/', '', (string) $resource->external_id), 'reporting_date' => $date,
            'sessions' => $sessions, 'engagedSessions' => 0, 'screenPageViews' => 0, 'userEngagementDuration' => 0, 'totalUsers' => 0, 'activeUsers' => 0,
        ] + $this->provenance($date, 'ga4'.$resource->id));
    }

    private function gscClicks(CoreExternalResource $resource, string $date, int $clicks): void
    {
        $this->insertFact('gsc_property_daily', [
            'external_resource_id' => $resource->id, 'site_url' => (string) $resource->external_id, 'reporting_date' => $date,
            'search_type' => 'web', 'clicks' => $clicks, 'impressions' => $clicks * 10,
        ] + $this->provenance($date, 'gsc'.$resource->id));
    }

    /**
     * A completed provider-resource-first collection whose dated datasets end on the given dates.
     *
     * @param  array<string, string>  $familyEnds
     * @param  list<string>  $structureFamilies
     */
    private function centralBaseline(CoreExternalResource $resource, string $provider, array $familyEnds, array $structureFamilies = [], ?string $structureFinishedAt = null): void
    {
        $run = CollectionRun::factory()->create(['status' => CollectionRunStatus::Completed]);
        $resourceRun = CollectionResourceRun::factory()->create([
            'collection_run_id' => $run->id, 'provider_or_source' => $provider, 'external_resource_id' => $resource->id,
            'digital_asset_id' => null, 'core_asset_binding_id' => null, 'status' => CollectionRunStatus::Completed,
            'metadata' => ['collection_scope' => 'provider_resource_first', 'history_policy_version' => GoogleAdsCentralCollectionService::HISTORY_POLICY_VERSION],
        ]);
        foreach ($familyEnds as $family => $end) {
            $datasetId = $provider === 'SEARCH_CONSOLE' ? 'gsc_property_daily' : 'dataset_'.strtolower($family);
            CollectionDatasetRun::factory()->create([
                'collection_run_id' => $run->id, 'collection_resource_run_id' => $resourceRun->id, 'provider_or_source' => $provider,
                'dataset_contract_id' => $datasetId, 'request_family_id' => $family, 'status' => CollectionRunStatus::Completed,
                'finished_at' => now()->subDay(),
                'metadata' => ['collection_scope' => 'provider_resource_first', 'date_range' => ['start' => '2025-09-01', 'end' => $end], 'search_type' => 'web'],
            ]);
        }
        foreach ($structureFamilies as $family) {
            CollectionDatasetRun::factory()->create([
                'collection_run_id' => $run->id, 'collection_resource_run_id' => $resourceRun->id, 'provider_or_source' => $provider,
                'dataset_contract_id' => 'structure', 'request_family_id' => $family, 'status' => CollectionRunStatus::Completed,
                'finished_at' => $structureFinishedAt, 'metadata' => ['collection_scope' => 'provider_resource_first'],
            ]);
        }
    }

    /** @param list<string> $families */
    private function structureRun(CoreExternalResource $resource, string $provider, array $families, string $finishedAt): void
    {
        $this->centralBaseline($resource, $provider, [], $families, $finishedAt);
    }

    private function metaMaterialization(CoreAssetBinding $binding, string $datasetId, ?string $coveredThrough, ?string $collectedAt = null): void
    {
        DatasetMaterialization::query()->where('dataset_id', $datasetId)->where('external_resource_id', $binding->external_resource_id)->delete();
        $dates = [];
        if ($coveredThrough !== null) {
            for ($day = CarbonImmutable::parse('2026-06-01'); $day->toDateString() <= $coveredThrough; $day = $day->addDay()) {
                $dates[] = $day->toDateString();
            }
        }
        DatasetMaterialization::query()->create([
            'dataset_id' => $datasetId,
            'digital_asset_id' => $binding->digital_asset_id,
            'external_resource_id' => $binding->external_resource_id,
            'provider_or_source' => 'META_ADS',
            'contract_version' => 1,
            'status' => MaterializationStatus::Available,
            'last_collected_at' => $collectedAt ?? '2026-09-21 03:00:00',
            'coverage_start_date' => $dates[0] ?? null,
            'coverage_end_date' => $coveredThrough,
            'row_count_approx' => 1,
            'row_count_semantics' => 'approximate_from_batches',
            'partial' => false,
            'freshness_metadata' => $coveredThrough === null ? null : [
                'successful_coverage_dates' => $dates,
                'verified_contiguous_watermark' => $coveredThrough,
                'latest_observed_reporting_date' => $coveredThrough,
                'last_successful_reporting_date' => $coveredThrough,
                'last_reprocess_through' => $coveredThrough,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function googleAdsPlan(CoreExternalResource $resource): array
    {
        return $this->privatePlan(GoogleAdsCentralCollectionService::class, $resource);
    }

    /** @return array<string, mixed> */
    private function privatePlan(string $service, CoreExternalResource $resource): array
    {
        $method = new ReflectionMethod($service, 'smartPlan');
        $instance = app($service);

        return $method->getNumberOfParameters() === 1
            ? $method->invoke($instance, $resource->fresh())
            : $method->invoke($instance, $this->google, $resource->fresh());
    }

    /** @return array{start: string, end: string} */
    private function range(array $plan, string $family): array
    {
        foreach ($plan['families'] as $entry) {
            if ($entry['family'] === $family) {
                return $entry['date_range'];
            }
        }
        $this->fail('Family not planned: '.$family);
    }
}
