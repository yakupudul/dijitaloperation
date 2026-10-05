<?php

namespace Tests\Feature\Collection;

use App\Models\Collection\CollectionRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\ResourceAutomation;
use App\Services\Collection\GoogleAds\GoogleAdsHistoricalActivityDiscoveryService;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsCustomerNotEnabledException;
use App\Services\Integrations\Google\DiscoverGoogleResourcesService;
use App\Services\Integrations\ResourceAutomationService;
use App\Support\Integrations\Google\GoogleScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * A closed / suspended Google Ads account answers 403 authorizationError:CUSTOMER_NOT_ENABLED. The history probe parks
 * it (`not_enabled_at`, retried weekly) and automatic collection shows "Hesap kapalı" instead of failing every tick.
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
