<?php

namespace Tests\Feature\MetaAds;

use App\Enums\DigitalAssetStatus;
use App\Jobs\CollectMetaGeoResultsJob;
use App\Livewire\Demo\Meta\OverviewPage;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Integrations\Meta\MetaApiClient;
use App\Services\Integrations\Meta\MetaException;
use App\Services\Meta\MetaScreen;
use App\Services\MetaAds\MetaAdsSpecialistBindingResolver;
use App\Services\MetaAds\MetaGeoResults;
use App\Support\Integrations\Meta\MetaResourceType;
use App\Support\Roles;
use Carbon\Carbon;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/** Meta country + city results: collection, city rows with results, Analiz › Bölgeye göre. */
final class MetaGeoResultsTest extends TestCase
{
    use RefreshDatabase;

    private DigitalAsset $asset;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-23 10:00:00', 'UTC'));
        app()->setLocale('tr');
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Atlas Diş']);
        $this->asset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'meta_ads', 'module_id' => 'meta-ads', 'status' => DigitalAssetStatus::Active, 'name' => 'Atlas Meta']);
        $integration = CoreIntegration::factory()->meta()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => ['auth_method' => 'oauth', 'auth_status' => 'connected', 'connection_status' => 'connected', 'credential_status' => 'valid', 'granted_permissions' => ['ads_read', 'business_management']],
        ]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $integration->id, 'encrypted_payload' => ['access_token' => 'EAAG-synthetic', 'granted_permissions' => ['ads_read', 'business_management']]]);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id, 'provider' => 'meta', 'resource_type' => MetaResourceType::META_AD_ACCOUNT, 'external_id' => 'act_777',
            'status' => CoreExternalResource::STATUS_AVAILABLE, 'metadata' => ['currency' => 'TRY', 'timezone_name' => 'Europe/Istanbul', 'account_status' => 1],
        ]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $this->asset->id, 'external_resource_id' => $resource->id, 'capability' => MetaAdsSpecialistBindingResolver::CAPABILITY, 'status' => CoreAssetBinding::STATUS_ACTIVE]);
    }

    public function test_country_and_region_rows_are_stored_with_results_and_summarised(): void
    {
        $this->fakeInsights();

        $stored = app(MetaGeoResults::class)->collect($this->asset, 3);

        $this->assertSame(12, $stored, '6 geo rows + 6 breakdown rows');
        $this->assertSame(['10'], DB::table('meta_breakdown_results_daily')->where('dimension', 'hour')->pluck('key1')->all());
        $this->assertSame(4.0, (float) DB::table('meta_breakdown_results_daily')->where('dimension', 'age_gender')->where('key1', '25-34')->where('key2', 'female')->value('leads'));
        $istanbul = DB::table('meta_geo_results_daily')->where('level', 'region')->where('region', 'Istanbul')->first();
        $this->assertSame('TR', $istanbul->country, 'the ad delivered only in Türkiye that day');
        $this->assertSame(6.0, (float) $istanbul->leads, 'lead aliases are not double counted');
        $this->assertSame('Implant – İstanbul – 35+', $istanbul->adset_name);
        $berlin = DB::table('meta_geo_results_daily')->where('level', 'region')->where('region', 'Berlin')->first();
        $this->assertSame('', $berlin->country, 'an ad in two countries cannot place its cities');

        $regions = app(MetaScreen::class)->regions(app(MetaScreen::class)->account($this->asset), '2026-09-01', '2026-09-30');
        $this->assertSame(['Istanbul', 'Berlin', 'Ankara'], array_column($regions, 'region'), 'most spend first');
        $this->assertSame(50.0, $regions[0]['cpr']);
        $this->assertSame('TR', $regions[0]['country']);

        app(MetaGeoResults::class)->collect($this->asset, 3);
        $this->assertSame(6, DB::table('meta_geo_results_daily')->count(), 'a re-collection replaces the window');
        $this->assertSame(6, DB::table('meta_breakdown_results_daily')->count());
    }

    public function test_an_account_collected_before_the_breakdowns_gets_their_first_30_days(): void
    {
        $this->fakeInsights();
        app(MetaGeoResults::class)->collect($this->asset, 3);
        DB::table('meta_breakdown_results_daily')->delete();
        $ranges = [];
        $this->mock(MetaApiClient::class, function ($mock) use (&$ranges): void {
            $mock->shouldReceive('get')->andReturnUsing(function ($integration, string $path, array $query) use (&$ranges): array {
                $ranges[] = json_decode((string) $query['time_range'], true)['since'];

                return ['data' => []];
            });
        });

        app(MetaGeoResults::class)->collect($this->asset);

        $this->assertSame('2026-08-24', min($ranges), 'geo rows alone do not shorten the first run');
    }

    public function test_analysis_tab_shows_regions_and_queues_collection(): void
    {
        $this->fakeInsights();
        app(MetaGeoResults::class)->collect($this->asset, 3);

        $this->get(route('operator.meta.overview', ['assetId' => $this->asset->id, 'tab' => 'analysis']))
            ->assertOk()->assertSee('Ülke ve şehir')->assertSee('Istanbul · TR')->assertSee('Yaş × cinsiyet')->assertSee('Facebook akış');

        Queue::fake();
        Livewire::test(OverviewPage::class, ['assetId' => (string) $this->asset->id, 'tab' => 'analysis'])->call('collectGeoResults');
        Queue::assertPushed(CollectMetaGeoResultsJob::class, fn ($job): bool => $job->assetId === $this->asset->id && $job->days === 90);
    }

    public function test_geo_job_retries_when_meta_is_unreachable_and_never_reports_it_as_an_application_error(): void
    {
        Exceptions::fake();
        Http::fake(['*' => Http::failedConnection()]);
        $state = fn (): array => Cache::get(CollectMetaGeoResultsJob::stateKey($this->asset->id));

        foreach ([1, 2, 3, 4] as $retry) {
            $job = $this->runGeoJob();
            $job->assertReleased();
            $job->assertNotFailed();
            $this->assertGreaterThanOrEqual(300, $job->job->releaseDelay, 'waits five minutes (plus jitter)');
            $this->assertLessThanOrEqual(360, $job->job->releaseDelay);
            $this->assertSame('waiting', $state()['state']);
            $this->assertSame($retry, $state()['transport_retries']);
        }

        // Still unreachable after four retries: recorded as failed, the next daily run tries again.
        $job = $this->runGeoJob();
        $job->assertNotReleased();
        $job->assertNotFailed();
        $this->assertSame('failed', $state()['state']);
        Exceptions::assertNothingReported();
    }

    public function test_connection_retries_count_only_the_runs_own_connection_waits(): void
    {
        Exceptions::fake();
        Http::fake(['*' => Http::failedConnection()]);
        $key = CollectMetaGeoResultsJob::stateKey($this->asset->id);

        // Tenth pick-up after overlap / cooldown / rate-limit waits: the first connection error is still retried.
        Cache::put($key, ['state' => 'waiting', 'error' => 'Meta istek sınırı; daha sonra tekrar denenecek.', 'at' => now()->toIso8601String()], now()->addDay());
        $this->runGeoJob(attempts: 10)->assertReleased();
        $this->assertSame(1, Cache::get($key)['transport_retries']);

        // Connection waits of a run older than the retry window do not use up a new run's retries.
        Cache::put($key, ['state' => 'waiting', 'transport_retries' => 4, 'at' => now()->subHours(13)->toIso8601String()], now()->addDay());
        $this->runGeoJob()->assertReleased();
        $this->assertSame(1, Cache::get($key)['transport_retries']);

        // A finished run, or the operator's "Bölge verisini çek", starts the count again.
        foreach ([['state' => 'done', 'rows' => 6], ['state' => 'running']] as $previous) {
            Cache::put($key, $previous + ['at' => now()->toIso8601String()], now()->addDay());
            $this->runGeoJob()->assertReleased();
            $this->assertSame(1, Cache::get($key)['transport_retries']);
        }
        Exceptions::assertNothingReported();
    }

    public function test_geo_job_still_fails_and_reports_errors_that_are_not_a_connection_failure(): void
    {
        Exceptions::fake();
        // MetaApiClient labels any unexpected error while sending as "transport"; without a connection error behind it,
        // it is an application error.
        $wrapped = new MetaException('Meta connection transport error.', MetaException::KIND_TRANSPORT, previous: new RuntimeException('cache store unavailable'));
        $provider = new MetaException('Unsupported get request.', MetaException::KIND_PROVIDER, 400, 100);
        $this->mock(MetaGeoResults::class)->shouldReceive('collect')->andThrowExceptions([$wrapped, $provider]);

        foreach ([$wrapped, $provider] as $exception) {
            $job = $this->runGeoJob();
            $job->assertNotReleased();
            $this->assertSame('failed', Cache::get(CollectMetaGeoResultsJob::stateKey($this->asset->id))['state']);
            Exceptions::assertReported(fn (MetaException $reported): bool => $reported === $exception);
        }
        Exceptions::assertReportedCount(2);
    }

    private function runGeoJob(int $attempts = 1): CollectMetaGeoResultsJob
    {
        $job = new CollectMetaGeoResultsJob($this->asset->id);
        $job->withFakeQueueInteractions();
        $job->job->attempts = $attempts;
        $job->handle(app(MetaGeoResults::class));

        return $job;
    }

    private function fakeInsights(): void
    {
        $base = ['campaign_id' => 'c1', 'campaign_name' => 'Implant Lead', 'adset_id' => 'as1', 'adset_name' => 'Implant – İstanbul – 35+', 'account_currency' => 'TRY', 'date_start' => '2026-09-20'];
        $leads = [['action_type' => 'lead', 'value' => '6'], ['action_type' => 'onsite_conversion.lead_grouped', 'value' => '6']];
        $this->mock(MetaApiClient::class, function ($mock) use ($base, $leads): void {
            $mock->shouldReceive('get')->andReturnUsing(function ($integration, string $path, array $query) use ($base, $leads): array {
                if ($query['breakdowns'] === 'country') {
                    return ['data' => [
                        $base + ['ad_id' => 'ad1', 'ad_name' => 'Implant video', 'country' => 'TR', 'spend' => '400', 'impressions' => '9000', 'clicks' => '120', 'actions' => $leads],
                        $base + ['ad_id' => 'ad2', 'ad_name' => 'Gurbetçi', 'country' => 'DE', 'spend' => '100', 'impressions' => '2000', 'clicks' => '20'],
                        $base + ['ad_id' => 'ad2', 'ad_name' => 'Gurbetçi', 'country' => 'TR', 'spend' => '20', 'impressions' => '300', 'clicks' => '2'],
                    ]];
                }
                $lead = fn (int $n): array => [['action_type' => 'lead', 'value' => (string) $n], ['action_type' => 'onsite_conversion.lead_grouped', 'value' => (string) $n]];
                $ad1 = $base + ['ad_id' => 'ad1', 'ad_name' => 'Implant video'];
                $other = match ($query['breakdowns']) {
                    'age,gender' => [$ad1 + ['age' => '25-34', 'gender' => 'female', 'spend' => '200', 'actions' => $lead(4)], $ad1 + ['age' => '35-44', 'gender' => 'male', 'spend' => '200', 'actions' => $lead(2)]],
                    'hourly_stats_aggregated_by_advertiser_time_zone' => [$ad1 + ['hourly_stats_aggregated_by_advertiser_time_zone' => '10:00:00 - 10:59:59', 'spend' => '400', 'actions' => $lead(6)]],
                    'publisher_platform,platform_position' => [$ad1 + ['publisher_platform' => 'facebook', 'platform_position' => 'feed', 'spend' => '300', 'actions' => $lead(5)],
                        $ad1 + ['publisher_platform' => 'instagram', 'platform_position' => 'story', 'spend' => '100', 'actions' => $lead(1)]],
                    'device_platform' => [$ad1 + ['device_platform' => 'mobile_app', 'spend' => '400', 'actions' => $lead(6)]],
                    default => null,
                };
                if ($other !== null) {
                    return ['data' => $other];
                }

                return ['data' => [
                    $base + ['ad_id' => 'ad1', 'ad_name' => 'Implant video', 'region' => 'Istanbul', 'spend' => '300', 'impressions' => '7000', 'clicks' => '100', 'actions' => $leads],
                    $base + ['ad_id' => 'ad1', 'ad_name' => 'Implant video', 'region' => 'Ankara', 'spend' => '100', 'impressions' => '2000', 'clicks' => '20'],
                    $base + ['ad_id' => 'ad2', 'ad_name' => 'Gurbetçi', 'region' => 'Berlin', 'spend' => '100', 'impressions' => '2000', 'clicks' => '20'],
                ]];
            });
        });
    }
}
