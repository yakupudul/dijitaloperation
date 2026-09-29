<?php

namespace Tests\Feature\Collection;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\CustomerStatus;
use App\Enums\DigitalAssetStatus;
use App\Enums\Observability\OperationalAlertState;
use App\Models\Brand;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Observability\OperationalAlert;
use App\Models\ResourceAutomation;
use App\Services\Collection\Ga4\Ga4CentralCollectionService;
use App\Services\Collection\GoogleAds\GoogleAdsCentralCollectionService;
use App\Services\Collection\Providers\Ga4\Ga4RequestFamilyCatalog;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsCentralRequestFamilyCatalog;
use App\Services\Collection\Providers\SearchConsole\SearchConsoleCentralDatasetExecutor;
use App\Services\Collection\SearchConsole\SearchConsoleCentralCollectionService;
use App\Services\Integrations\ResourceAutomationService;
use App\Support\Integrations\Google\GoogleScopes;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use ReflectionMethod;
use Tests\Support\InsertsFacts;
use Tests\TestCase;

/**
 * Production collection repairs (2026-09-29 diagnose):
 *  - Search Console datasets "covered" by earlier runs but with no stored fact are refetched over the whole window
 *    (self-heal + moxdop:gsc:repair-facts), not 4 days forever;
 *  - unbound / passive query-source accounts collect their query dataset only and never page the operator;
 *  - a GA4 repair keeps ga4_property_daily current; accounts stopped by now-fixed write errors are re-admitted.
 */
final class ProductionCollectionRepairTest extends TestCase
{
    use InsertsFacts;
    use RefreshDatabase;

    private CoreIntegration $google;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-27 10:00:00', 'UTC'));
        Carbon::setTestNow(Carbon::parse('2026-09-27 10:00:00', 'UTC'));
        config(['moxdop.google.client_id' => 'cid', 'moxdop.google.client_secret' => 'csecret']);
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
            'encrypted_payload' => ['access_token' => 'test-access', 'refresh_token' => 'test-refresh', 'scope' => GoogleScopes::ADWORDS],
            'expires_at' => now()->addHour(),
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dataset_without_facts_is_refetched_over_the_full_window_once_a_week(): void
    {
        $resource = $this->resource('search_console', 'sc-domain:heal.test');
        $this->gscProperty($resource, '2026-09-23', 30);
        $this->gscFact('gsc_page_daily', $resource, '2026-09-23', ['page' => 'https://heal.test/']);
        $this->completedDatasets($resource, 'SEARCH_CONSOLE', SearchConsoleCentralDatasetExecutor::FAMILY_ANALYTICS,
            ['gsc_property_daily', 'gsc_query_daily', 'gsc_page_daily'], '2026-09-24');
        Http::fake(['*' => Http::response(['rows' => []])]);

        $this->assertContains('gsc_query_daily', app(SearchConsoleCentralCollectionService::class)->datasetsMissingFacts($resource));
        $this->assertNotContains('gsc_page_daily', app(SearchConsoleCentralCollectionService::class)->datasetsMissingFacts($resource));

        $plan = $this->plan(SearchConsoleCentralCollectionService::class, 'smartPlan', $resource);
        $this->assertSame('2025-08-26', $this->gscStart($plan, 'gsc_query_daily'), 'no stored query row: whole 395-day window');
        $this->assertSame('2026-09-21', $this->gscStart($plan, 'gsc_page_daily'), 'facts present: restatement window');

        $again = $this->plan(SearchConsoleCentralCollectionService::class, 'smartPlan', $resource);
        $this->assertSame('2026-09-21', $this->gscStart($again, 'gsc_query_daily'), 'self-heal at most once a week');
    }

    public function test_property_with_little_traffic_is_not_refetched(): void
    {
        $resource = $this->resource('search_console', 'sc-domain:quiet.test');
        $this->gscProperty($resource, '2026-09-23', 1);

        $this->assertSame([], app(SearchConsoleCentralCollectionService::class)->datasetsMissingFacts($resource));
    }

    public function test_repair_facts_command_is_dry_by_default_and_queues_the_affected_datasets_with_apply(): void
    {
        Queue::fake();
        [$asset, $resource] = $this->bound('search_console', 'sc-domain:repair.test', 'website');
        $this->gscProperty($resource, '2026-09-23', 30);
        $this->gscFact('gsc_page_daily', $resource, '2026-09-23', ['page' => 'https://repair.test/']);

        $this->artisan('moxdop:gsc:repair-facts', ['--asset' => (string) $asset->id])
            ->expectsOutputToContain('gsc_query_daily')
            ->expectsOutputToContain('Kuru çalıştırma')
            ->assertSuccessful();
        $this->assertSame(0, CollectionRun::query()->count());

        $this->artisan('moxdop:gsc:repair-facts', ['--asset' => (string) $asset->id, '--apply' => true])
            ->expectsOutputToContain('Yeniden aktarım kuyrukta')
            ->assertSuccessful();
        $run = CollectionRun::query()->latest('id')->firstOrFail();
        $datasets = $run->datasetRuns()->pluck('dataset_contract_id')->unique()->values()->all();
        $this->assertContains('gsc_query_daily', $datasets);
        $this->assertNotContains('gsc_page_daily', $datasets);
        $this->assertNotContains('gsc_property_daily', $datasets);
        $query = $run->datasetRuns()->where('dataset_contract_id', 'gsc_query_daily')->firstOrFail();
        $this->assertSame('2025-05-27', data_get($query->metadata, 'date_range.start'), '486-day window');
        $this->assertSame([], (array) $query->checkpoint);
    }

    public function test_unbound_search_console_account_collects_queries_only(): void
    {
        Queue::fake();
        $unbound = $this->resource('search_console', 'sc-domain:unbound.test');
        $automation = ResourceAutomation::query()->where('external_resource_id', $unbound->id)->firstOrFail();
        $automation->update(['collection_status' => 'planning']);

        app(ResourceAutomationService::class)->collect($automation->id);

        $run = CollectionRun::query()->findOrFail($automation->fresh()->collection_run_id);
        $this->assertSame(['gsc_query_daily'], $run->datasetRuns()->pluck('dataset_contract_id')->unique()->values()->all());
        $this->assertTrue((bool) data_get($run->metadata, 'query_only'));
        $this->assertSame('2025-08-26', data_get($run->datasetRuns()->first()->metadata, 'date_range.start'));

        // Bound to an operational website: the full Search Analytics set.
        Http::fake(['*' => Http::response(['rows' => []])]);
        [, $bound] = $this->bound('search_console', 'sc-domain:bound.test', 'website');
        $boundAutomation = ResourceAutomation::query()->where('external_resource_id', $bound->id)->firstOrFail();
        $boundAutomation->update(['collection_status' => 'planning']);
        app(ResourceAutomationService::class)->collect($boundAutomation->id);
        $boundRun = CollectionRun::query()->findOrFail($boundAutomation->fresh()->collection_run_id);
        $this->assertContains('gsc_page_daily', $boundRun->datasetRuns()->pluck('dataset_contract_id')->all());
    }

    public function test_unbound_google_ads_account_plans_search_terms_only(): void
    {
        $unbound = $this->resource('google_ads', '1234567890');
        $this->completedDatasets($unbound, 'GOOGLE_ADS', GoogleAdsCentralRequestFamilyCatalog::SEARCH_TERM, ['google_ads_search_term_daily'], '2026-09-20');

        $plan = $this->plan(GoogleAdsCentralCollectionService::class, 'queryOnlyPlan', $unbound);

        $this->assertSame([GoogleAdsCentralRequestFamilyCatalog::SEARCH_TERM], array_values(array_unique(array_column($plan['families'], 'family'))));
        $this->assertSame(['start' => '2026-09-21', 'end' => '2026-09-26'], $plan['families'][0]['date_range']);
        $this->assertNull($plan['history_policy_version'], 'a query-only run never becomes the history baseline');
        $this->assertTrue(app(ResourceAutomationService::class)->isQueryOnly($unbound));
        $this->assertFalse(app(ResourceAutomationService::class)->isQueryOnly($this->resource('ga4', 'properties/77')));
    }

    public function test_only_operational_accounts_raise_collection_alerts_and_old_ones_are_resolved(): void
    {
        $service = app(ResourceAutomationService::class);
        $unbound = $this->resource('search_console', 'sc-domain:silent.test');
        [, $bound] = $this->bound('search_console', 'sc-domain:loud.test', 'website');
        $unboundAutomation = ResourceAutomation::query()->where('external_resource_id', $unbound->id)->firstOrFail();
        $boundAutomation = ResourceAutomation::query()->where('external_resource_id', $bound->id)->firstOrFail();

        $service->alert($unboundAutomation->id, 'collection', 'collection_failed');
        $service->alert($boundAutomation->id, 'collection', 'collection_failed');

        $open = OperationalAlert::query()->where('rule_key', 'resource-automation.collection')->where('state', OperationalAlertState::Open->value);
        $this->assertSame([(string) $bound->id], (clone $open)->pluck('scope_key')->all());

        // An alert raised before this rule (unbound account) is resolved by the cleanup; the bound one stays.
        $bindings = CoreAssetBinding::query()->where('external_resource_id', $bound->id);
        $bindings->update(['status' => 'inactive']);
        $this->assertSame(1, $service->resolveUnboundAlerts());
        $this->assertSame(0, (clone $open)->count());
    }

    public function test_accounts_stopped_by_fixed_write_errors_are_readmitted(): void
    {
        $resource = $this->resource('search_console', 'sc-domain:stuck.test');
        $run = CollectionRun::factory()->create(['status' => CollectionRunStatus::Partial]);
        $resourceRun = CollectionResourceRun::factory()->create(['collection_run_id' => $run->id, 'provider_or_source' => 'SEARCH_CONSOLE',
            'external_resource_id' => $resource->id, 'digital_asset_id' => null, 'status' => CollectionRunStatus::Partial]);
        CollectionDatasetRun::factory()->create(['collection_run_id' => $run->id, 'collection_resource_run_id' => $resourceRun->id,
            'provider_or_source' => 'SEARCH_CONSOLE', 'dataset_contract_id' => 'gsc_page_country_daily', 'status' => CollectionRunStatus::Failed,
            'error_code' => 'PERSISTENCE', 'error_message' => 'SQLSTATE[23514]: Check violation: no partition of relation "gsc_f_page_country" found for row']);
        ResourceAutomation::query()->where('external_resource_id', $resource->id)->update([
            'collection_status' => 'attention', 'collection_error' => 'request_requires_fix', 'collection_run_id' => $run->id, 'next_collection_at' => null,
        ]);

        $stats = app(ResourceAutomationService::class)->retryStopped();

        $this->assertSame(1, $stats['recovered']);
        $this->assertSame('waiting', ResourceAutomation::query()->where('external_resource_id', $resource->id)->value('collection_status'));
    }

    public function test_ga4_repair_also_brings_property_daily_up_to_date_in_the_property_time_zone(): void
    {
        $resource = $this->resource('ga4', 'properties/5550009');
        Cache::put('ga4:property-context:properties/5550009', ['timeZone' => 'Turkey'], 3600);
        $this->completedDatasets($resource, 'GA4', Ga4RequestFamilyCatalog::FAMILY_PROPERTY_DAILY, ['ga4_property_daily'], '2026-09-12');
        $run = CollectionRun::factory()->create(['status' => CollectionRunStatus::Partial]);
        $resourceRun = CollectionResourceRun::factory()->create(['collection_run_id' => $run->id, 'provider_or_source' => 'GA4',
            'external_resource_id' => $resource->id, 'digital_asset_id' => null, 'status' => CollectionRunStatus::Partial,
            'metadata' => ['collection_scope' => 'provider_resource_first']]);
        CollectionDatasetRun::factory()->create(['collection_run_id' => $run->id, 'collection_resource_run_id' => $resourceRun->id,
            'provider_or_source' => 'GA4', 'dataset_contract_id' => 'ga4_page_content_daily', 'request_family_id' => Ga4RequestFamilyCatalog::FAMILY_PAGE_CONTENT_DAILY,
            'status' => CollectionRunStatus::Failed, 'metadata' => ['date_range' => ['start' => '2026-09-10', 'end' => '2026-09-12']]]);

        $plan = $this->plan(Ga4CentralCollectionService::class, 'smartPlan', $resource);

        $this->assertSame('repair', $plan['mode']);
        $this->assertSame('Europe/Istanbul', $plan['timezone']);
        $this->assertSame([Ga4RequestFamilyCatalog::FAMILY_PAGE_CONTENT_DAILY, Ga4RequestFamilyCatalog::FAMILY_PROPERTY_DAILY], $plan['families']);
        $this->assertSame(['start' => '2026-09-13', 'end' => '2026-09-26'], $plan['family_ranges'][Ga4RequestFamilyCatalog::FAMILY_PROPERTY_DAILY]);
    }

    // ---------------------------------------------------------------- helpers

    private function resource(string $type, string $externalId): CoreExternalResource
    {
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $this->google->id,
            'provider' => 'google',
            'resource_type' => $type,
            'external_id' => $externalId,
            'display_name' => 'Account '.$externalId,
            'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => ['is_manager' => false, 'time_zone' => 'UTC', 'currency_code' => 'TRY'],
        ]);
        ResourceAutomation::query()->create([
            'external_resource_id' => $resource->id, 'collection_enabled' => true,
            'last_collection_success_at' => now()->subDay(), 'next_collection_at' => now(),
        ]);

        return $resource;
    }

    /** @return array{0: DigitalAsset, 1: CoreExternalResource} */
    private function bound(string $type, string $externalId, string $assetType): array
    {
        $resource = $this->resource($type, $externalId);
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id]);
        $asset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => $assetType, 'status' => DigitalAssetStatus::Active]);
        CoreAssetBinding::factory()->create([
            'digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id,
            'capability' => $type, 'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);

        return [$asset, $resource];
    }

    private function gscProperty(CoreExternalResource $resource, string $date, int $clicks): void
    {
        $this->gscFact('gsc_property_daily', $resource, $date, ['clicks' => $clicks, 'impressions' => $clicks * 10]);
    }

    /** @param  array<string, mixed>  $extra */
    private function gscFact(string $table, CoreExternalResource $resource, string $date, array $extra): void
    {
        $this->insertFact($table, $extra + [
            'external_resource_id' => $resource->id, 'site_url' => (string) $resource->external_id, 'reporting_date' => $date,
            'search_type' => 'web', 'clicks' => 3, 'impressions' => 30, 'contract_version' => 1, 'first_collected_at' => now(),
            'last_collected_at' => now(), 'record_fingerprint' => hash('sha256', $table.$resource->id.$date), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param  list<string>  $datasets */
    private function completedDatasets(CoreExternalResource $resource, string $provider, string $family, array $datasets, string $end): void
    {
        $run = CollectionRun::factory()->create(['status' => CollectionRunStatus::Completed]);
        $resourceRun = CollectionResourceRun::factory()->create([
            'collection_run_id' => $run->id, 'provider_or_source' => $provider, 'external_resource_id' => $resource->id,
            'digital_asset_id' => null, 'core_asset_binding_id' => null, 'status' => CollectionRunStatus::Completed,
            'metadata' => ['collection_scope' => 'provider_resource_first', 'history_policy_version' => GoogleAdsCentralCollectionService::HISTORY_POLICY_VERSION],
        ]);
        foreach ($datasets as $datasetId) {
            CollectionDatasetRun::factory()->create([
                'collection_run_id' => $run->id, 'collection_resource_run_id' => $resourceRun->id, 'provider_or_source' => $provider,
                'dataset_contract_id' => $datasetId, 'request_family_id' => $family, 'status' => CollectionRunStatus::Completed,
                'finished_at' => now()->subDay(), 'execution_variant' => $provider === 'SEARCH_CONSOLE' ? 'web' : 'recent',
                'metadata' => ['collection_scope' => 'provider_resource_first', 'date_range' => ['start' => '2025-09-01', 'end' => $end], 'search_type' => 'web'],
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function plan(string $service, string $method, CoreExternalResource $resource): array
    {
        $reflection = new ReflectionMethod($service, $method);

        return $reflection->getNumberOfParameters() === 1
            ? $reflection->invoke(app($service), $resource->fresh())
            : $reflection->invoke(app($service), $this->google, $resource->fresh());
    }

    /** @param  array<string, mixed>  $plan */
    private function gscStart(array $plan, string $datasetId): string
    {
        foreach ($plan['dataset_plans'] as $dataset) {
            if ($dataset['dataset_id'] === $datasetId && ($dataset['search_type'] ?? 'web') === 'web') {
                return (string) $dataset['date_range']['start'];
            }
        }
        $this->fail('Dataset not planned: '.$datasetId);
    }
}
