<?php

namespace Tests\Feature\Collection;

use App\Enums\Observability\OperationalAlertState;
use App\Models\Collection\CollectionRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Observability\OperationalAlert;
use App\Models\ResourceAutomation;
use App\Services\Collection\GoogleAds\GoogleAdsHistoricalActivityDiscoveryService;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsCustomerNotEnabledException;
use App\Services\Integrations\Google\DiscoverGoogleResourcesService;
use App\Services\Integrations\ResourceAutomationService;
use App\Support\Integrations\Google\GoogleScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * A closed / suspended Google Ads account answers 403 authorizationError:CUSTOMER_NOT_ENABLED. The history probe parks
 * it (`not_enabled_at`, retried weekly) and automatic collection shows "Hesap kapalı" instead of failing every tick;
 * its earlier "Hesap güncellemesi durdu" alert resolves, since the account is no longer collected on purpose.
 */
final class GoogleAdsClosedAccountTest extends TestCase
{
    use RefreshDatabase;

    private CoreExternalResource $resource;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        config([
            'moxdop.google.client_id' => 'cid',
            'moxdop.google.client_secret' => 'csecret',
            'moxdop.google.developer_token' => 'dev-token',
            'moxdop-collection.queue_connection' => 'database',
            'moxdop-collection.require_queue_connection' => false,
            'moxdop-google-ads-collector.minimum_request_interval_ms' => 0,
        ]);
        $google = CoreIntegration::factory()->google()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => ['granted_scopes' => [GoogleScopes::ADWORDS]],
        ]);
        CoreIntegrationCredential::factory()->provider()->create([
            'integration_id' => $google->id,
            'encrypted_payload' => ['client_id' => 'cid', 'client_secret' => 'csecret'],
        ]);
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $google->id,
            'encrypted_payload' => ['access_token' => 'test-access', 'refresh_token' => 'test-refresh', 'scope' => GoogleScopes::ADWORDS],
            'expires_at' => now()->addHour(),
        ]);
        $this->resource = CoreExternalResource::factory()->create([
            'integration_id' => $google->id,
            'provider' => 'google',
            'resource_type' => 'google_ads',
            'external_id' => '1112223333',
            'display_name' => 'Closed Ads',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => ['is_manager' => false, 'time_zone' => 'UTC', 'currency_code' => 'TRY'],
        ]);
    }

    public function test_the_403_answer_parks_the_account_for_a_week(): void
    {
        $this->fakeForbidden('CUSTOMER_NOT_ENABLED');

        try {
            app(GoogleAdsHistoricalActivityDiscoveryService::class)->discover($this->resource);
            $this->fail('A closed account cannot be probed.');
        } catch (GoogleAdsCustomerNotEnabledException $closed) {
            $this->assertSame('Google Ads hesabı etkin değil (kapalı ya da askıda).', $closed->getMessage());
        }

        $resource = $this->resource->fresh();
        $this->assertSame(now()->toIso8601String(), data_get($resource->metadata, 'not_enabled_at'));
        $this->assertSame('not_enabled', app(ResourceAutomationService::class)->readiness($resource));
    }

    public function test_automatic_collection_shows_the_closed_account_without_failing(): void
    {
        $this->fakeForbidden('CUSTOMER_NOT_ENABLED');
        CoreAssetBinding::factory()->create(['external_resource_id' => $this->resource->id, 'capability' => 'google_ads']);
        $automation = ResourceAutomation::query()->create([
            'external_resource_id' => $this->resource->id, 'collection_enabled' => true,
            'collection_status' => 'planning', 'collection_queued_at' => now(), 'next_collection_at' => now(),
        ]);

        app(ResourceAutomationService::class)->collect($automation->id);

        $automation->refresh();
        $this->assertSame(['attention', 'not_enabled', 0, null], [$automation->collection_status, $automation->collection_error,
            (int) $automation->collection_failures, $automation->collection_queued_at]);
        $this->assertTrue($automation->next_collection_at->isFuture());
        $this->assertSame(0, CollectionRun::query()->count(), 'no collection run for a closed account');
        $this->assertNotNull(data_get($this->resource->fresh()->metadata, 'not_enabled_at'));
        Http::assertSentCount(1);
    }

    public function test_a_lost_permission_is_not_mistaken_for_a_closed_account(): void
    {
        $this->fakeForbidden('USER_PERMISSION_DENIED');
        CoreAssetBinding::factory()->create(['external_resource_id' => $this->resource->id, 'capability' => 'google_ads']);
        $automation = ResourceAutomation::query()->create([
            'external_resource_id' => $this->resource->id, 'collection_enabled' => true,
            'collection_status' => 'planning', 'collection_queued_at' => now(), 'next_collection_at' => now(),
        ]);

        try {
            app(ResourceAutomationService::class)->collect($automation->id);
            $this->fail('A lost permission still fails the collection job.');
        } catch (RuntimeException $failure) {
            $this->assertNotInstanceOf(GoogleAdsCustomerNotEnabledException::class, $failure);
            $this->assertStringContainsString('Google Ads authorization failed', $failure->getMessage());
        }

        $this->assertArrayNotHasKey('not_enabled_at', (array) $this->resource->fresh()->metadata);
        $this->assertNull(app(ResourceAutomationService::class)->readiness($this->resource->fresh()));
        $this->assertNotSame('not_enabled', $automation->fresh()->collection_error);
    }

    public function test_an_account_that_answers_again_is_released(): void
    {
        $this->resource->forceFill(['metadata' => $this->resource->metadata + ['not_enabled_at' => now()->subDays(8)->toIso8601String()]])->save();
        Http::fake(['https://googleads.googleapis.com/*' => Http::response([['results' => []]])]);

        $activity = app(GoogleAdsHistoricalActivityDiscoveryService::class)->discover($this->resource->fresh());

        $this->assertFalse($activity['has_activity']);
        $this->assertArrayNotHasKey('not_enabled_at', (array) $this->resource->fresh()->metadata);
    }

    public function test_the_daily_account_discovery_keeps_the_park(): void
    {
        $parkedAt = now()->subDays(2)->toIso8601String();
        $this->resource->forceFill(['metadata' => $this->resource->metadata + ['not_enabled_at' => $parkedAt]])->save();
        Http::preventStrayRequests();
        Http::fake([
            'https://googleads.googleapis.com/*/customers:listAccessibleCustomers' => Http::response(['resourceNames' => ['customers/1112223333']]),
            'https://googleads.googleapis.com/*/customers/1112223333/googleAds:search' => Http::response(['results' => [['customerClient' => [
                'id' => 1112223333, 'descriptiveName' => 'Closed Ads', 'manager' => false, 'level' => 0, 'status' => 'CANCELED',
                'currencyCode' => 'TRY', 'timeZone' => 'UTC', 'clientCustomer' => 'customers/1112223333',
            ]]]]),
        ]);

        $result = app(DiscoverGoogleResourcesService::class)->discover($this->resource->integration);

        $this->assertSame('ok', $result['results']['google_ads']['status']);
        $resource = $this->resource->fresh();
        $this->assertSame('CANCELED', data_get($resource->metadata, 'status'), 'the discovered inventory is still written');
        $this->assertSame($parkedAt, data_get($resource->metadata, 'not_enabled_at'), 'the 05:10 discovery must not end the week early');
        $this->assertSame('not_enabled', app(ResourceAutomationService::class)->readiness($resource));
    }

    public function test_a_stopped_account_found_closed_on_its_daily_retry_resolves_its_stop_alert(): void
    {
        // The access token outlives the day the test travels.
        CoreIntegrationCredential::query()->where('integration_id', $this->resource->integration_id)
            ->where('credential_type', CoreIntegrationCredential::TYPE_AUTHORIZATION)->update(['expires_at' => now()->addDays(3)]);
        $automation = $this->boundAutomation($this->resource);
        $service = app(ResourceAutomationService::class);
        foreach (range(1, 3) as $attempt) {
            $service->fail($automation->id);
        }
        $alert = $this->stopAlert($this->resource);
        $this->assertSame(OperationalAlertState::Open, $alert->state);

        $this->travel(21)->hours();
        $this->assertSame(1, $service->retryStopped()['retried']);
        $this->assertSame(OperationalAlertState::Open, $alert->fresh()->state, 'not known to be closed yet');
        $automation->refresh()->update(['collection_status' => 'planning', 'collection_queued_at' => now()]);
        $this->fakeForbidden('CUSTOMER_NOT_ENABLED');

        $service->collect($automation->id);

        $this->assertSame(['attention', 'not_enabled'], [$automation->fresh()->collection_status, $automation->fresh()->collection_error]);
        $alert->refresh();
        $this->assertSame(OperationalAlertState::Resolved, $alert->state);
        $this->assertSame('NOT_ENABLED', $alert->resolution_kind);
    }

    public function test_a_lost_permission_keeps_the_stop_alert_open(): void
    {
        $automation = $this->boundAutomation($this->resource);
        $service = app(ResourceAutomationService::class);
        foreach (range(1, 3) as $attempt) {
            $service->fail($automation->id);
        }
        $automation->refresh()->update(['collection_status' => 'planning', 'collection_queued_at' => now()]);
        $this->fakeForbidden('USER_PERMISSION_DENIED');

        try {
            $service->collect($automation->id);
            $this->fail('A lost permission still fails the collection job.');
        } catch (RuntimeException $failure) {
            $this->assertNotInstanceOf(GoogleAdsCustomerNotEnabledException::class, $failure);
        }

        $this->assertSame(OperationalAlertState::Open, $this->stopAlert($this->resource)->state);
    }

    public function test_the_scheduler_pass_of_a_parked_account_resolves_its_stop_alert_but_a_reconnect_keeps_it(): void
    {
        config(['moxdop-resource-automation.queue_connection' => 'database']);
        Queue::fake();
        $this->resource->forceFill(['metadata' => $this->resource->metadata + ['not_enabled_at' => now()->subDay()->toIso8601String()]])->save();
        $closed = $this->boundAutomation($this->resource);
        $unavailable = CoreExternalResource::factory()->create([
            'integration_id' => $this->resource->integration_id, 'provider' => 'google', 'resource_type' => 'google_ads',
            'external_id' => '4445556666', 'display_name' => 'Unavailable Ads', 'status' => CoreExternalResource::STATUS_UNAVAILABLE,
            'metadata' => ['is_manager' => false],
        ]);
        $reconnect = $this->boundAutomation($unavailable);
        $service = app(ResourceAutomationService::class);
        $service->alert($closed->id, 'collection', 'collection_failed');
        $service->alert($reconnect->id, 'collection', 'collection_failed');

        $service->tick();

        $this->assertSame(['attention', 'not_enabled'], [$closed->fresh()->collection_status, $closed->fresh()->collection_error]);
        $this->assertSame(['attention', 'reconnect'], [$reconnect->fresh()->collection_status, $reconnect->fresh()->collection_error]);
        $this->assertSame(OperationalAlertState::Resolved, $this->stopAlert($this->resource)->state);
        $this->assertSame('NOT_ENABLED', $this->stopAlert($this->resource)->resolution_kind);
        $this->assertSame(OperationalAlertState::Open, $this->stopAlert($unavailable)->state, 'a reconnect stop has its own way back');
        Queue::assertNothingPushed();
    }

    public function test_the_daily_retry_resolves_old_stop_alerts_of_parked_accounts(): void
    {
        $closed = $this->boundAutomation($this->resource);
        $manager = CoreExternalResource::factory()->create([
            'integration_id' => $this->resource->integration_id, 'provider' => 'google', 'resource_type' => 'google_ads',
            'external_id' => '7778889999', 'display_name' => 'Manager Ads', 'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => ['is_manager' => true],
        ]);
        $managerAutomation = $this->boundAutomation($manager);
        $active = CoreExternalResource::factory()->create([
            'integration_id' => $this->resource->integration_id, 'provider' => 'google', 'resource_type' => 'google_ads',
            'external_id' => '1231231234', 'display_name' => 'Active Ads', 'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => ['is_manager' => false],
        ]);
        $activeAutomation = $this->boundAutomation($active);
        $service = app(ResourceAutomationService::class);
        foreach ([$closed, $managerAutomation, $activeAutomation] as $automation) {
            $service->alert($automation->id, 'collection', 'collection_failed');
        }
        // Parked after its alert opened (an older release did not resolve it then).
        $this->resource->forceFill(['metadata' => $this->resource->metadata + ['not_enabled_at' => now()->toIso8601String()]])->save();

        $this->assertSame(2, $service->retryStopped()['alerts_resolved']);

        $this->assertSame('NOT_ENABLED', $this->stopAlert($this->resource)->resolution_kind);
        $this->assertSame('MANAGER', $this->stopAlert($manager)->resolution_kind);
        $this->assertSame(OperationalAlertState::Open, $this->stopAlert($active)->state);
    }

    /** An enabled automation of an account bound to an operational brand's asset (factory defaults: active customer). */
    private function boundAutomation(CoreExternalResource $resource): ResourceAutomation
    {
        CoreAssetBinding::factory()->create(['external_resource_id' => $resource->id, 'capability' => 'google_ads']);

        return ResourceAutomation::query()->create([
            'external_resource_id' => $resource->id, 'collection_enabled' => true,
            'collection_status' => 'waiting', 'next_collection_at' => now(),
        ]);
    }

    private function stopAlert(CoreExternalResource $resource): OperationalAlert
    {
        return OperationalAlert::query()->where('rule_key', 'resource-automation.collection')->where('scope_key', (string) $resource->id)->sole();
    }

    private function fakeForbidden(string $authorizationError): void
    {
        Http::fake([
            'https://googleads.googleapis.com/*' => Http::response([[
                'error' => [
                    'code' => 403,
                    'message' => 'The caller does not have permission',
                    'status' => 'PERMISSION_DENIED',
                    'details' => [[
                        '@type' => 'type.googleapis.com/google.ads.googleads.v25.errors.GoogleAdsFailure',
                        'errors' => [[
                            'errorCode' => ['authorizationError' => $authorizationError],
                            'message' => "The customer account can't be accessed.",
                        ]],
                        'requestId' => 'req-closed-1',
                    ]],
                ],
            ]], 403),
        ]);
    }
}
