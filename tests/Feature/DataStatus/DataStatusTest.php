<?php

namespace Tests\Feature\DataStatus;

use App\Enums\Collection\ActivityTier;
use App\Enums\CustomerStatus;
use App\Enums\DigitalAssetStatus;
use App\Livewire\Operator\Assets\DataStatusStrip;
use App\Livewire\Operator\Integrations\ConnectionHealthPage;
use App\Models\AssetAlert;
use App\Models\Brand;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\ResourceActivity;
use App\Models\ResourceAutomation;
use App\Models\User;
use App\Services\DataStatus\ConnectionHealth;
use App\Services\DataStatus\DataStatus;
use App\Services\DataStatus\DataStatusReader;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Integrations\Google\GoogleScopes;
use App\Support\Integrations\Meta\MetaResourceType;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InsertsFacts;
use Tests\TestCase;

/**
 * One data-status language on every asset page: the Veri durumu strip, the website no-data banner, the KPI cards
 * and the Portföy sağlığı grid all come from DataStatusReader (bindings, integration state, central collection
 * and the newest Data Pool fact day), so they say the same thing.
 */
final class DataStatusTest extends TestCase
{
    use InsertsFacts;
    use RefreshDatabase;

    private Brand $brand;

    private CoreIntegration $google;

    private CoreIntegration $meta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-27 12:00:00'));
        Http::fake();
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true, 'locale' => 'tr']);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
        $customer = Customer::factory()->create(['status' => CustomerStatus::Active]);
        $this->brand = Brand::factory()->create(['customer_id' => $customer->id, 'name' => 'Örnek Klinik']);

        config(['moxdop.google.client_id' => 'test-client-id', 'moxdop.google.client_secret' => 'test-client-secret']);
        $this->google = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE, 'config' => ['granted_scopes' => [GoogleScopes::ADWORDS, GoogleScopes::SEARCH_CONSOLE_READONLY]]]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $this->google->id]);
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $this->google->id, 'encrypted_payload' => ['access_token' => 'a', 'refresh_token' => 'r', 'scope' => GoogleScopes::ADWORDS], 'expires_at' => now()->addHour(),
        ]);
        $this->meta = CoreIntegration::factory()->meta()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => ['auth_method' => 'oauth', 'auth_status' => 'connected', 'connection_status' => 'connected', 'credential_status' => 'valid', 'granted_permissions' => ['ads_read', 'business_management']],
        ]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $this->meta->id, 'encrypted_payload' => ['access_token' => 'EAAG-synthetic', 'granted_permissions' => ['ads_read']]]);
    }

    public function test_bound_website_with_collected_facts_is_current_everywhere_with_real_kpis_and_no_banner(): void
    {
        $site = $this->website();
        [$gsc, $ga4] = $this->bindWebsite($site);
        $this->collected($gsc);
        $this->collected($ga4);
        $this->gscFacts($gsc, '2026-09-01', '2026-09-26');
        $this->ga4Facts($ga4, '2026-09-01', '2026-09-26');

        $statuses = collect(app(DataStatusReader::class)->forAsset($site))->keyBy('capability');
        $this->assertSame(DataStatus::FRESH, $statuses['search_console']->state);
        $this->assertSame(DataStatus::FRESH, $statuses['ga4']->state);
        $this->assertSame('2026-09-26', $statuses['ga4']->lastDataDate?->toDateString(), 'last data day comes from the facts');

        $page = $this->get(route('operator.website', ['assetId' => $site->id]))->assertOk();
        $page->assertSee('Veri durumu')->assertSee('Güncel')->assertSee('son veri 26 Eyl');
        $page->assertDontSee('data-data-status-banner', false)->assertDontSee('İlk veri yükleniyor')->assertDontSee('Bağlı değil');
        $page->assertDontSee('henüz Google Analytics')->assertDontSee('Son veri: henüz yok');
        // Analiz: 26 days × 100 clicks inside the last-28-days window (Search Console property totals).
        $this->get(route('operator.website', ['assetId' => $site->id, 'tab' => 'analiz']))->assertOk()->assertSee('data-scorecard="clicks"', false)->assertSee('2.600');
        $page->assertDontSee('Açık bulgular');

    }

    public function test_bound_but_never_collected_website_says_first_load(): void
    {
        $site = $this->website();
        $this->bindWebsite($site);

        $status = app(DataStatusReader::class)->forAssetSource($site, 'ga4');
        $this->assertSame(DataStatus::FIRST_LOAD, $status->state);
        $this->assertSame('İlk veri yükleniyor', $status->label());

        $page = $this->get(route('operator.website', ['assetId' => $site->id]))->assertOk();
        $page->assertSee('İlk veri yükleniyor')->assertSee('data-data-status-banner="first_load"', false)
            ->assertSee('Search Console / Google Analytics için ilk veri yükleniyor')
            ->assertDontSee('data-data-status-banner="not_bound"', false);
    }

    public function test_running_first_collection_shows_progress(): void
    {
        $site = $this->website();
        [$gsc] = $this->bindWebsite($site);
        $run = CollectionRun::factory()->create();
        CollectionResourceRun::factory()->create([
            'collection_run_id' => $run->id, 'external_resource_id' => $gsc->id, 'status' => 'running', 'datasets_total' => 4, 'datasets_completed' => 1,
        ]);

        $status = app(DataStatusReader::class)->forAssetSource($site, 'search_console');
        $this->assertSame(DataStatus::FIRST_LOAD, $status->state);
        $this->assertTrue($status->collecting);
        $this->assertSame(25, $status->progressPct);
    }

    public function test_revoked_integration_is_an_access_problem_with_a_reconnect_link(): void
    {
        $site = $this->website();
        $this->bindWebsite($site);
        $this->google->update(['config' => array_merge($this->google->config ?? [], ['auth_status' => 'revoked'])]);

        $status = app(DataStatusReader::class)->forAssetSource($site, 'search_console');
        $this->assertSame(DataStatus::ACCESS_PROBLEM, $status->state);
        $this->assertSame(DataStatus::ACTION_RECONNECT, $status->action);
        $reconnect = route('integrations.google.authorize', ['integration' => $this->google->id]);
        $this->assertSame($reconnect, $status->actionUrl);

        $page = $this->get(route('operator.website', ['assetId' => $site->id]))->assertOk();
        $page->assertSee('Erişim sorunu')->assertSee('Yeniden bağla')->assertSee($reconnect, false)
            ->assertDontSee('data-data-status-banner', false);
    }

    public function test_old_facts_are_late_by_n_days_with_the_known_reason(): void
    {
        $site = $this->website();
        [$gsc, $ga4] = $this->bindWebsite($site);
        $this->collected($gsc, ['collection_error' => 'collection_failed', 'collection_status' => 'attention']);
        $this->collected($ga4);
        $this->gscFacts($gsc, '2026-08-25', '2026-09-07');
        $this->ga4Facts($ga4, '2026-09-01', '2026-09-26');

        $status = app(DataStatusReader::class)->forAssetSource($site, 'search_console');
        $this->assertSame(DataStatus::STALE, $status->state);
        $this->assertSame(20, $status->ageDays);
        $this->assertSame('Gecikmiş · 20 gün', $status->label());
        // The scanner's own freshness alert would be a second, differently worded freshness signal on the page.
        AssetAlert::query()->create(['digital_asset_id' => $site->id, 'brand_id' => $this->brand->id, 'alert_key' => 'gsc_stale', 'kind' => 'gsc_stale', 'severity' => 'medium',
            'title' => 'Search Console verisi güncel değil', 'message' => 'm', 'first_detected_at' => now(), 'last_detected_at' => now()]);

        $page = $this->get(route('operator.website', ['assetId' => $site->id]))->assertOk();
        $page->assertSee('Gecikmiş · 20 gün')->assertSee('Son toplama başarısız')->assertDontSee('data-data-status-banner', false)
            ->assertDontSee('Search Console verisi güncel değil');
    }

    public function test_unbound_website_says_not_bound_with_a_bind_action(): void
    {
        $site = $this->website();

        $statuses = app(DataStatusReader::class)->forAsset($site);
        $this->assertSame([DataStatus::NOT_BOUND, DataStatus::NOT_BOUND], array_map(fn (DataStatus $s): string => $s->state, $statuses));

        $page = $this->get(route('operator.website', ['assetId' => $site->id]))->assertOk();
        $page->assertSee('Bağlı değil')->assertSee('Kaynağı bağla')->assertSee('data-data-status-banner="not_bound"', false)
            ->assertSee('Bu web sitesine Search Console / Google Analytics bağlı değil');
    }

    public function test_paused_accounts_come_from_the_activity_interface(): void
    {
        $site = $this->website();
        [$gsc] = $this->bindWebsite($site);
        $this->gscFacts($gsc, '2026-08-01', '2026-08-05');
        $this->app->instance(DataStatusReader::class, new class extends DataStatusReader
        {
            public function activityFor(DigitalAsset $asset, string $capability, ?int $externalResourceId): ?string
            {
                return $capability === 'search_console' ? self::ACTIVITY_INACTIVE : null;
            }
        });

        $status = app(DataStatusReader::class)->forAssetSource($site, 'search_console');
        $this->assertSame(DataStatus::PAUSED, $status->state);
        $this->assertSame('Pasif', $status->label());
        $this->get(route('operator.website', ['assetId' => $site->id]))->assertOk()->assertSee('Pasif');
        $this->assertNull((new DataStatusReader)->activityFor($site, 'search_console', $gsc->id), 'no resource_activity row: the account is not inactive');
    }

    public function test_account_activity_of_a_whole_batch_is_one_query(): void
    {
        $tiers = ['active' => ActivityTier::Active, 'idle' => ActivityTier::Idle, 'dormant' => ActivityTier::Dormant, 'operator_paused' => ActivityTier::Active, 'no_row' => null];
        $assets = [];
        foreach ($tiers as $case => $tier) {
            $assets[$case] = $this->asset('gsc', 'Örnek GSC '.$case);
            $resource = $this->bind($assets[$case], $this->google, 'google', GoogleResourceType::GSC_PROPERTY, 'search_console', 'sc-domain:'.$case.'.test');
            if ($tier !== null) {
                ResourceActivity::query()->create(['external_resource_id' => $resource->id, 'provider' => 'google', 'tier' => $tier,
                    'operator_paused_at' => $case === 'operator_paused' ? now() : null]);
            }
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $statuses = app(DataStatusReader::class)->forAssets(collect(array_values($assets)));
        $activityQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "resource_activity"'))->count();
        DB::disableQueryLog();

        $this->assertSame(1, $activityQueries, 'one resource_activity query for all bound sources, not one per source');
        $this->assertSame(
            ['active' => DataStatus::FIRST_LOAD, 'idle' => DataStatus::PAUSED, 'dormant' => DataStatus::PAUSED, 'operator_paused' => DataStatus::PAUSED, 'no_row' => DataStatus::FIRST_LOAD],
            array_map(fn (DigitalAsset $asset): string => $statuses[$asset->id][0]->state, $assets),
        );
    }

    public function test_batch_activity_rows_do_not_outlive_the_batch(): void
    {
        $site = $this->asset('gsc', 'Örnek GSC');
        $gsc = $this->bind($site, $this->google, 'google', GoogleResourceType::GSC_PROPERTY, 'search_console', 'sc-domain:ornek.test');
        $activity = ResourceActivity::query()->create(['external_resource_id' => $gsc->id, 'provider' => 'google', 'tier' => ActivityTier::Dormant]);
        $reader = app(DataStatusReader::class);
        $this->assertSame(DataStatus::PAUSED, $reader->forAssetSource($site, 'search_console')->state);
        $this->assertSame(DataStatusReader::ACTIVITY_INACTIVE, $reader->activityFor($site, 'search_console', $gsc->id));

        $activity->update(['tier' => ActivityTier::Active]);

        $this->assertNull($reader->activityFor($site, 'search_console', $gsc->id), 'outside a batch the row is read as it is now');
        $reader->flush();
        $this->assertSame(DataStatus::FIRST_LOAD, $reader->forAssetSource($site, 'search_console')->state, 'the next batch reads the rows again');
        $this->assertNull($reader->activityFor($site, 'search_console', null));
    }

    public function test_ga4_search_console_and_business_profile_pages_show_the_same_strip(): void
    {
        $ga4 = $this->asset('ga4', 'Örnek GA4');
        $ga4Resource = $this->bind($ga4, $this->google, 'google', GoogleResourceType::GA4_PROPERTY, 'ga4', 'properties/123');
        $this->collected($ga4Resource);
        $this->ga4Facts($ga4Resource, '2026-09-10', '2026-09-26');
        $gsc = $this->asset('gsc', 'Örnek GSC');
        $this->bind($gsc, $this->google, 'google', GoogleResourceType::GSC_PROPERTY, 'search_console', 'sc-domain:ornek.test');
        $gbp = $this->asset('gbp', 'Örnek Profil');

        $this->get(route('operator.analytics', ['assetId' => $ga4->id]))->assertOk()
            ->assertSee('data-data-status-source="ga4" data-state="fresh"', false)->assertSee('Güncel');
        $this->get(route('operator.search-console', ['assetId' => $gsc->id]))->assertOk()
            ->assertSee('data-data-status-source="search_console" data-state="first_load"', false)->assertSee('İlk veri yükleniyor');
        $this->get(route('operator.gbp', ['assetId' => $gbp->id]))->assertOk()
            ->assertSee('data-data-status-source="google_business_profile" data-state="not_bound"', false)->assertSee('Kaynağı bağla');
    }

    public function test_strip_refresh_starts_the_canonical_collection_for_that_source(): void
    {
        $site = $this->website();
        $this->bind($site, $this->google, 'google', GoogleResourceType::GA4_PROPERTY, 'ga4', 'properties/123');
        Bus::fake();
        Queue::fake();

        $strip = Livewire::test(DataStatusStrip::class, ['assetId' => $site->id])
            ->assertSee('Verileri yenile')
            ->call('refreshSource', 'ga4');
        $this->assertStringStartsWith('Google Analytics', $strip->get('feedback'));
        $this->assertStringNotContainsString('başlatılamadı', $strip->get('feedback'), 'the canonical GA4 collection accepted the request');
        $strip
            ->call('refreshSource', 'search_console')
            ->assertSee('Search Console bağlantısı kontrol edilmeli.');
    }

    public function test_connection_health_lists_what_the_system_repairs_and_what_needs_the_operator(): void
    {
        $site = $this->website();
        [$gsc, $ga4] = $this->bindWebsite($site);
        $this->collected($gsc, ['collection_error' => 'collection_failed', 'collection_status' => 'attention']);
        $this->collected($ga4);
        $this->gscFacts($gsc, '2026-08-25', '2026-09-07');
        $this->ga4Facts($ga4, '2026-09-01', '2026-09-26');
        $other = Brand::factory()->create(['customer_id' => $this->brand->customer_id, 'name' => 'Sitesiz Marka']);
        Bus::fake();
        Queue::fake();

        $health = app(ConnectionHealth::class);
        $issues = collect($health->issues())->keyBy(fn (array $i): string => $i['brand'].'|'.$i['kind']);
        $this->assertSame(ConnectionHealth::SYSTEM, $issues['Örnek Klinik|stale']['who']);
        $this->assertStringContainsString('Gecikmiş · 20 gün', $issues['Örnek Klinik|stale']['title']);
        $this->assertSame('Site hiç taranmamış', $issues['Örnek Klinik|crawl']['title']);
        $this->assertSame(ConnectionHealth::OPERATOR, $issues['Sitesiz Marka|no_site']['who']);
        $this->assertFalse($issues->has('Örnek Klinik|access'), 'GA4 is current');
        $this->assertSame(['system' => 2, 'operator' => 1, 'brands' => 2], $health->summary());

        Livewire::test(ConnectionHealthPage::class)->assertSee('Bağlantı sağlığı')->assertSee('Sitesiz Marka')->assertSee('Site hiç taranmamış')
            ->call('repairNow')->assertSee('1 hesabın veri çekimi ve 1 site taraması başlatıldı');
        $automation = ResourceAutomation::query()->where('external_resource_id', $gsc->id)->sole();
        $this->assertNull($automation->collection_error);
        $this->assertTrue($automation->next_collection_at->lte(now()));
        $this->assertTrue(CollectionRun::query()->where('digital_asset_id', $site->id)->exists(), 'the crawl was started');
        $this->assertFalse(collect($health->issues())->contains('kind', 'crawl'), 'a running crawl is not a gap');

        $this->google->update(['config' => array_merge($this->google->config ?? [], ['auth_status' => 'revoked'])]);
        app(DataStatusReader::class)->flush();
        $access = collect($health->issues())->firstWhere('kind', 'access');
        $this->assertSame(ConnectionHealth::OPERATOR, $access['who']);
        $this->artisan('moxdop:health:repair')->expectsOutputToContain('Senin işin')->assertSuccessful();
    }

    /** @return array<string, array{0: string}> */
    public static function adStates(): array
    {
        return ['fresh' => ['fresh'], 'first_load' => ['first_load'], 'access_problem' => ['access_problem'], 'stale' => ['stale'], 'not_bound' => ['not_bound']];
    }

    #[DataProvider('adStates')]
    public function test_google_ads_page_speaks_the_same_language(string $state): void
    {
        $asset = $this->asset('google_ads', 'Örnek Ads');
        $resource = $state === 'not_bound' ? null : $this->bind($asset, $this->google, 'google', GoogleResourceType::GOOGLE_ADS_CUSTOMER, 'google_ads', '1112223333', ['currency' => 'TRY']);
        $this->arrange($state, $this->google, $resource, fn (string $from, string $to) => $this->days($from, $to, fn (string $day) => $this->insertFact('google_ads_account_daily', $this->pool($asset, [
            'external_resource_id' => $resource?->id, 'digital_asset_id' => null, 'customer_id' => '1112223333', 'reporting_date' => $day,
            'impressions' => 1000, 'clicks' => 50, 'cost_micros' => 100_000_000, 'cost_amount' => 100, 'conversions' => 2, 'currency' => 'TRY',
        ]))));

        $this->assertAssetPage($asset, 'google_ads', $state, route('operator.google-ads.overview', ['assetId' => $asset->id]));
    }

    #[DataProvider('adStates')]
    public function test_meta_ads_page_speaks_the_same_language(string $state): void
    {
        $asset = $this->asset('meta_ads', 'Örnek Meta');
        $resource = $state === 'not_bound' ? null : $this->bind($asset, $this->meta, 'meta', MetaResourceType::META_AD_ACCOUNT, 'meta_ads', 'act_555', ['currency' => 'TRY', 'timezone_name' => 'Europe/Istanbul']);
        $this->arrange($state, $this->meta, $resource, fn (string $from, string $to) => $this->days($from, $to, fn (string $day) => $this->insertFact('meta_account_daily', $this->pool($asset, [
            'external_resource_id' => $resource?->id, 'account_id' => '555', 'reporting_date' => $day,
            'spend' => 40, 'impressions' => 2000, 'clicks' => 30, 'reach' => 1500, 'currency' => 'TRY',
        ]))));

        $this->assertAssetPage($asset, 'meta_ads', $state, route('operator.meta.overview', ['assetId' => $asset->id]));
    }

    private function arrange(string $state, CoreIntegration $integration, ?CoreExternalResource $resource, callable $facts): void
    {
        match ($state) {
            'fresh' => [$this->collected($resource), $facts('2026-09-10', '2026-09-26')],
            'stale' => [$this->collected($resource), $facts('2026-08-20', '2026-09-07')],
            'access_problem' => $integration->update(['config' => array_merge($integration->config ?? [], $integration->provider === 'meta'
                ? ['credential_status' => 'revoked'] : ['auth_status' => 'revoked'])]),
            default => null,
        };
    }

    private function assertAssetPage(DigitalAsset $asset, string $capability, string $state, string $url): void
    {
        $status = app(DataStatusReader::class)->forAssetSource($asset, $capability);
        $this->assertSame($state, $status->state);
        $expected = [
            'fresh' => 'Güncel', 'first_load' => 'İlk veri yükleniyor', 'access_problem' => 'Erişim sorunu',
            'stale' => 'Gecikmiş · 20 gün', 'not_bound' => 'Bağlı değil',
        ][$state];
        $this->assertSame($expected, $status->label());

        $page = $this->get($url)->assertOk();
        $page->assertSee('Veri durumu')->assertSee($expected)
            ->assertSee('data-data-status-source="'.$capability.'" data-state="'.$state.'"', false);
        $page->assertDontSee('Veri Güncelliği');
        if ($state === 'access_problem') {
            $page->assertSee($status->actionUrl, false);
        }
    }

    private function website(): DigitalAsset
    {
        return $this->asset('website', 'Örnek Site');
    }

    private function asset(string $type, string $name): DigitalAsset
    {
        return DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => $type, 'status' => DigitalAssetStatus::Active, 'name' => $name, 'domain' => 'ornek.test']);
    }

    /** @return array{0: CoreExternalResource, 1: CoreExternalResource} [search console, ga4] */
    private function bindWebsite(DigitalAsset $site): array
    {
        return [
            $this->bind($site, $this->google, 'google', GoogleResourceType::GSC_PROPERTY, 'search_console', 'sc-domain:ornek.test'),
            $this->bind($site, $this->google, 'google', GoogleResourceType::GA4_PROPERTY, 'ga4', 'properties/123'),
        ];
    }

    /** @param array<string, mixed> $metadata */
    private function bind(DigitalAsset $asset, CoreIntegration $integration, string $provider, string $type, string $capability, string $externalId, array $metadata = []): CoreExternalResource
    {
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id, 'provider' => $provider, 'resource_type' => $type, 'external_id' => $externalId,
            'display_name' => $externalId, 'status' => CoreExternalResource::STATUS_AVAILABLE, 'metadata' => $metadata,
        ]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id, 'capability' => $capability, 'status' => CoreAssetBinding::STATUS_ACTIVE]);

        return $resource;
    }

    /** @param array<string, mixed> $values */
    private function collected(?CoreExternalResource $resource, array $values = []): void
    {
        ResourceAutomation::query()->create(['external_resource_id' => $resource?->id, 'collection_status' => 'current', 'last_collection_success_at' => now()->subHours(3)] + $values);
    }

    private function gscFacts(CoreExternalResource $resource, string $from, string $to): void
    {
        $this->days($from, $to, fn (string $day) => $this->insertFact('gsc_property_daily', $this->pool(null, [
            'external_resource_id' => $resource->id, 'site_url' => 'sc-domain:ornek.test', 'reporting_date' => $day,
            'clicks' => 100, 'impressions' => 1000, 'search_type' => 'web', 'metadata' => '{}',
        ])));
    }

    private function ga4Facts(CoreExternalResource $resource, string $from, string $to): void
    {
        $this->days($from, $to, fn (string $day) => $this->insertFact('ga4_property_daily', $this->pool(null, [
            'external_resource_id' => $resource->id, 'property_id' => '123', 'reporting_date' => $day, 'sessions' => 40, 'engagedSessions' => 20,
        ])));
    }

    private function days(string $from, string $to, callable $each): void
    {
        for ($day = CarbonImmutable::parse($from); $day->lte(CarbonImmutable::parse($to)); $day = $day->addDay()) {
            $each($day->toDateString());
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function pool(?DigitalAsset $asset, array $values): array
    {
        return $values + [
            'digital_asset_id' => $asset?->id, 'contract_version' => 1,
            'first_collected_at' => now(), 'last_collected_at' => now(), 'source_timezone' => 'Europe/Istanbul',
            'record_fingerprint' => hash('sha256', json_encode($values)), 'created_at' => now(), 'updated_at' => now(),
        ];
    }
}
